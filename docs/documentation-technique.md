# Documentation technique — DataShare

Septembre 2026 — Projet 3 « Pilotez le développement d'une solution
informatique ».

Ce document suit les huit sections du modèle de documentation fourni. Il est
**consolidateur** : lorsque le sujet dispose déjà d'un document dédié dans le
dépôt, la section en donne la synthèse et renvoie vers le détail, qui reste la
seule source de vérité. Les sections sans document dédié (choix
technologiques, sécurité, qualité, installation) sont écrites ici en entier.

## 1. Architecture de l'application

DataShare est composé de quatre briques et d'un mécanisme planifié :

- une **SPA Vue 3 + TypeScript** (Vite, Vue Router, Pinia), qui porte les
  écrans et la validation côté client ;
- une **API REST Laravel 13 (PHP 8.3)**, qui porte la logique métier, la
  validation côté serveur et l'authentification JWT ;
- **PostgreSQL 17.5** (conteneur Docker), qui stocke les métadonnées —
  utilisateurs et fichiers (token, expiration, protection) ;
- un **stockage de fichiers** hors racine web, accédé via la façade `Storage`
  de Laravel (driver `local`, interchangeable S3 par configuration) ;
- un **scheduler** quotidien qui purge les fichiers expirés (métadonnées et
  contenu physique), et dont les **journaux** sont l'unique canal de sortie —
  un traitement sans client HTTP ne remonte rien d'autre.

Les échanges sont sécurisés par HTTPS de bout en bout, JWT en en-tête
`Authorization` sur les routes protégées, validation des entrées des deux
côtés, et bcrypt pour tous les mots de passe (comptes et partages).

Le diagramme des composants et flux (Mermaid), la grammaire des codes de
statut, le parcours de téléchargement détaillé et l'ensemble des décisions de
conception argumentées (SPA + API plutôt que rendu serveur, JWT plutôt que
session, révocation par liste noire, téléchargement en `POST`, ordre des
suppressions de la purge, planification en UTC…) sont dans
[docs/architecture.md](architecture.md).

**Évolution identifiée, écartée à ce stade** : la conteneurisation complète de
l'application (back-end et front-end dockerisés, en plus de la base). Elle a
été étudiée puis écartée en fin de projet : elle aurait changé le substrat
d'exécution — invalidant les mesures de performance calibrées sur
`artisan serve` et `vite preview`, le modèle cron du scheduler et le job
end-to-end de la CI — à l'étape précisément dédiée à stabiliser et documenter
l'existant, sans être exigée par les spécifications. Elle reste une évolution
naturelle pour un déploiement au-delà du prototype.

## 2. Choix technologiques justifiés

Les spécifications imposaient un choix parmi des options fermées (quatre
back-ends, trois front-ends, deux bases, deux stockages). Choix retenus :

| Élément | Technologie choisie | Alternatives (spec) | Justification |
| --- | --- | --- | --- |
| Langage back | PHP 8.3 | Java, C#, TypeScript | Expérience préalable significative (Laravel, PHP MVC) : la productivité et la qualité de relecture priment sur la découverte d'un langage, dans un projet où l'IA générative doit être supervisée de près |
| Framework back | Laravel 13 | Spring Boot, .NET Core, NestJS, Symfony | Couvre nativement les besoins des US : validation déclarative, façade `Storage` (local/S3 interchangeables), scheduler intégré (purge US10), hachage bcrypt, limiteurs de débit ; écosystème de test mature (`artisan test`) |
| Authentification | JWT via `php-open-source-saver/jwt-auth` | Sessions à cookie | Exigence de la spec (« Authentification JWT ») ; API sans état, front libre de son hébergement ; révocation compensée par liste noire en cache |
| Framework front | Vue 3 + TypeScript | Angular, React | Composants monofichiers lisibles, courbe d'apprentissage douce, TypeScript pour la robustesse des contrats d'API ; outillage Vite (build, HMR) |
| Base de données | PostgreSQL 17.5 | MongoDB | Données nativement relationnelles (un utilisateur, N fichiers) ; contraintes d'intégrité portées par le SGBD : unicité insensible à la casse sur l'email (`LOWER(email)`), FK `ON DELETE CASCADE`, index sur `expires_at` pour le balayage de purge |
| Stockage fichiers | Système de fichiers local via façade `Storage` | AWS S3 | Suffisant pour le prototype, zéro dépendance externe ; la façade rend la migration S3 purement configurative |
| Versionnement et CI | Git + GitHub (PR, ruleset sur `main`, GitHub Actions) | GitLab | Historique propre en conventional commits ; `main` protégée par un check unique `ci-ok` agrégeant lint, tests, e2e et sécurité |
| Conteneurisation | Docker Compose (PostgreSQL seul) | — | Base reproductible sans installer PostgreSQL sur l'hôte ; healthcheck `pg_isready` exploité par les scripts de déploiement (`--wait`) |
| Qualité de code | Pint (PHP), ESLint/outillage Vite (front), lint markdown | — | Rejoués en CI à chaque push (feedback rapide) et chaque PR (gate complète) |
| Assistance IA | Claude (analyse, arbitrages) + Claude Code (exécution supervisée) | — | Posture détaillée en section 8 |

## 3. Modèle de données

Deux entités suffisent au périmètre livré : `USERS` et `FILES`, liées par une
relation 1-N (un utilisateur dépose 0 à n fichiers ; tout fichier appartient à
exactement un utilisateur — l'upload anonyme US07 est hors périmètre du
prototype).

Points structurants :

- **Clés primaires techniques** (`id`) : `email` et `token` sont uniques et
  non nuls — identifiants candidats — mais l'un est modifiable et l'autre
  exposé publiquement dans les liens ; ni l'un ni l'autre ne doit se propager
  dans des clés étrangères.
- **Contraintes portées par le SGBD** : unicité de `LOWER(email)` (l'identité
  ne dépend pas de la casse saisie), unicité de `token` (résolution du lien
  public en une requête), `ON DELETE CASCADE` sur `files.user_id`, index sur
  `expires_at` (balayage quotidien de la purge).
- **Pas de soft delete** : l'état « expiré » se calcule à la requête
  (`expires_at < now()`), jamais par une colonne figée.

Le MCD (formalisme Merise) et le MLD (schéma relationnel) sont dans
[docs/mcd.md](mcd.md).

## 4. Documentation d'API

Le contrat d'interface entre le front et le back est spécifié en
**OpenAPI 3.1** : [docs/openapi.yaml](openapi.yaml). Il couvre les neuf
opérations implémentées — quatre d'authentification (`register`, `login`,
`me`, `logout`), trois de gestion de fichiers (dépôt US01, historique paginé
et filtrable US05, suppression US06) et deux du parcours public de
téléchargement (métadonnées et téléchargement du lien, US02).

Le contrat fait des codes de statut une grammaire : `401` (jeton ou
identifiants refusés), `404` (lien inconnu — volontairement indistinguable
d'un fichier d'autrui), `410` (lien connu mais expiré), `422` (validation
serveur, détaillée champ par champ — le front n'invente aucun libellé), `429`
avec `Retry-After` (quota dépassé). Aucune réponse n'est cachable
(`no-store`) : elles dépendent toutes de l'instant ou du porteur du jeton.

## 5. Sécurité et gestion des accès

**Authentification.** JWT (`php-open-source-saver/jwt-auth`), durée de vie de
60 minutes. La déconnexion (`POST /auth/logout`) inscrit le jeton sur une
liste noire en cache, vérifiée à chaque requête authentifiée : un jeton volé
cesse d'être utilisable dès la déconnexion, sans attendre son échéance.
L'existence du compte est revérifiée à chaque requête plutôt que déduite du
seul contenu du jeton.

**Gestion des accès.** Pas de rôles ni de profil administrateur (conforme au
MVP) : chaque utilisateur authentifié n'accède qu'à ses propres fichiers, le
contrôle d'appartenance répondant `404` — indistinguable d'un identifiant
inexistant — pour ne rien révéler du patrimoine d'autrui. Le parcours de
téléchargement est public mais gouverné par le lien : token de 22 caractères
non prédictible, mot de passe de partage optionnel (US09), expiration
vérifiée à chaque requête.

**Mesures de sécurisation :**

- mots de passe de comptes **et** de partages hachés en bcrypt, jamais
  réversibles ;
- validation des entrées côté client (retour immédiat) et côté serveur (seule
  barrière réelle, l'API étant atteignable sans la SPA) ;
- fichiers stockés hors de la racine web sous des noms physiques aléatoires,
  servis exclusivement par un contrôleur — expiration et mot de passe sont
  incontournables ;
- téléchargement en `POST` : le mot de passe de partage ne transite jamais
  dans une URL (journaux d'accès, historique, `Referer`) ;
- message d'échec unique à la connexion (anti-énumération de comptes) ;
- `no-store` sur toutes les réponses de l'API ;
- quatre limiteurs de débit : authentification (5/min/IP), dépôts,
  téléchargements (par lien et par IP), et limiteur général (60/min/utilisateur),
  plafonds surchargeables par variables `DATASHARE_THROTTLE_*` ;
- journaux sans données personnelles : identifiants numériques plutôt
  qu'emails ou noms de fichiers, motifs de route plutôt que chemins résolus,
  jamais de token ni de mot de passe ; seule exception assumée, l'adresse IP
  des événements de sécurité ;
- `APP_DEBUG=false` exigé en production (une trace d'exception exposerait les
  paramètres de requête).

**Limites et protections d'upload.** Taille maximale 1 Go
(`DATASHARE_UPLOAD_MAX_BYTES`), liste noire d'extensions exécutables
(`config/datashare.php`), expiration par défaut et maximale de 7 jours,
mot de passe de partage de 6 caractères minimum.

**Limites assumées.** Elles sont consignées, argumentées et datées dans
[SECURITY.md](../SECURITY.md) (sections « Acceptées » et « Ignorées ») :
limitation de débit par IP seule, TTL du jeton à 60 minutes, documents à
macros autorisés, suppressions non atomiques, purge non surveillée, octets
orphelins d'un compte supprimé, entre autres.

## 6. Qualité, tests et maintenance

Synthèse des quatre documents du plan de suivi, qui restent la référence.

**Tests — [TESTING.md](../TESTING.md).** La pyramide suit la réalité du
projet : l'essentiel de l'effort porte sur les tests Feature/API côté back
(190 tests, plus de 1 000 assertions — contrôleurs, règles métier, audit,
sécurité HTTP) et les tests de composants côté front (Vitest + Vue Test
Utils), un test unitaire pur là où il a du sens, et un parcours end-to-end
critique (Cypress) qui rejoue le chemin utilisateur complet. Couverture :
seuil bloquant de 70 % des deux côtés (pcov côté PHP, v8 côté front),
rapports capturés dans `docs/captures/`. Deux bases pour deux usages :
SQLite pour la boucle courte, PostgreSQL pour la CI de conformité.

**Sécurité — [SECURITY.md](../SECURITY.md).** Quatre scans : `composer audit
--locked`, `npm audit --audit-level=high --package-lock-only`, Trivy
(système de fichiers), Gitleaks (historique complet). Politique bloquante dès
« high » (et dès le premier avis côté Composer), rejouée à chaque PR et
chaque lundi. Les constats sont triés en trois seaux — corrigés, acceptés
(limites argumentées du MVP), ignorés (signalés sans risque réel ici).

**Performance — [PERF.md](../PERF.md).** Côté back, campagne k6 en trois
scénarios (calibrage, montée en charge, dépassement délibéré) sur l'endpoint
critique de téléchargement, avec résultats, analyse et réserves de validité.
Côté front, budget de performance mesuré sur le build de production :
bundle JS initial ≤ 55 kB gzip (mesuré 49,4 kB au plus lourd), CSS ≤ 10 kB,
Lighthouse Performance ≥ 90 (desktop) / ≥ 80 (mobile), Accessibilité et
Bonnes pratiques ≥ 90, SEO ≥ 80 — seuil recalibré et justifié : les pages
d'une application de partage par liens privés n'ont pas vocation à être
indexées. Chaque mesure est reproductible par un mode d'emploi aux
répertoires explicites.

**Maintenance — [MAINTENANCE.md](../MAINTENANCE.md).** Prérequis
d'exploitation (l'entrée cron du scheduler, et ce qui se passe sans elle),
procédures de mise à jour des dépendances — contrôle mensuel, action
immédiate sur avis de sécurité, distinction correctif/montée de version,
vérifications après mise à jour — et exploitation de la purge (vérifier
qu'elle a tourné, la rejouer, lire ses lignes d'audit).

**Intégration continue.** Trois workflows en pyramide : `push.yml` (feedback
rapide — lint et tests unitaires, hors `main`), `pr.yml` (gate complète —
lint, tests des deux couches, PostgreSQL, end-to-end, sécurité — agrégée par
le check unique `ci-ok` exigé au merge), `security.yml` (audits et scans,
appelé par la gate et planifié chaque lundi pour détecter la dérive entre
deux PR). Le rejeu partiel des jobs entre push et PR ouverte est un doublon
connu, arbitré et documenté en tête de `push.yml` — les gardes qui
l'élimineraient fragiliseraient la protection de `main`.

**La maintenance à l'épreuve des faits — septembre 2026.** La semaine de
finalisation du projet a fourni trois cas réels, absorbés par les procédures
ci-dessus :

- *Vulnérabilités publiées sur une dépendance mergée* : quatre avis « high »
  sur `league/commonmark` (dépendance transitive de Laravel), publiés le
  1er septembre, ont été détectés par la gate sur une pull request purement
  documentaire trois jours plus tard. Correctif ciblé du lockfile
  (`composer update league/commonmark --with-all-dependencies`, 2.9.0 →
  2.10.0), vérifié par l'audit puis par la suite de tests complète —
  exactement la procédure « repérer une faille » de MAINTENANCE.md.
- *Rupture d'écosystème* : npm a retiré ses endpoints d'audit historiques
  (juillet 2026, brownouts depuis avril) ; le repli silencieux de `npm audit`
  vers l'endpoint retiré a cassé la gate. Correctif : `--package-lock-only`
  (arbre virtuel depuis le lockfile, endpoint moderne) et une logique de
  retry qui ne rejoue que les erreurs d'endpoint — une vraie détection de
  vulnérabilité échoue au premier passage.
- *Indisponibilité du registre npm* : un incident de plusieurs dizaines de
  minutes a mis la gate au rouge sans aucun défaut du code. Procédure
  retenue : re-run des jobs échoués après retour au vert du registre — le
  retry automatique couvre les erreurs transitoires, pas les incidents
  déclarés.

Ces épisodes illustrent la limite structurelle d'une gate de sécurité : elle
dépend de la disponibilité des registres qu'elle interroge.

## 7. Processus d'installation et d'exécution

**Prérequis** : PHP 8.3+ (avec `pdo_pgsql` ; `php.ini` relevé à
`upload_max_filesize=1100M` / `post_max_size=1200M` pour le dépôt à 1 Go),
Composer 2.x, Node 22.18+ (ou 24.12+, contrainte `engines`) et npm, Docker
avec Docker Compose.

**Installation scriptée** (depuis la racine du dépôt) :

```bash
./scripts/install.sh     # tout : BDD (conteneur + migrations), backend, frontend
./scripts/db-setup.sh    # base de données seule (conteneur, attente healthy, migrations)
```

Les deux scripts sont idempotents — rejouables sans effet de bord : `.env`
jamais écrasé, `APP_KEY` et `JWT_SECRET` générés uniquement s'ils sont vides,
migrations déjà appliquées ignorées. Ils vérifient leurs prérequis et
échouent avec un message actionnable (par exemple `sudo service docker
start` si le démon Docker est arrêté) plutôt que d'escalader eux-mêmes les
privilèges. La procédure manuelle pas à pas reste documentée dans le
[README](../README.md).

**Lancement en développement** :

```bash
docker compose up -d                 # racine — PostgreSQL
cd backend && php artisan serve      # API sur :8000
cd frontend && npm run dev           # SPA sur :5173
```

**Variables d'environnement** (`backend/.env`, modèle commenté dans
`.env.example`) : connexion base (`DB_*`), clés générées (`APP_KEY`,
`JWT_SECRET`), origine de la SPA (`DATASHARE_FRONTEND_URL`), et réglages
métier `DATASHARE_*` — disque et taille maximale d'upload, durées
d'expiration par défaut et maximale, longueur du token, pagination de
l'historique, taille des lots de purge, plafonds des limiteurs de débit.

En production s'ajoutent : l'entrée cron du scheduler, `APP_DEBUG=false`, le
niveau de journal `info` et la rotation — détaillés dans
[MAINTENANCE.md](../MAINTENANCE.md) et [docs/architecture.md](architecture.md).

## 8. Utilisation de l'IA dans le développement

Le projet a été développé en **binômage supervisé** avec deux outils : Claude
(analyse, planification, arbitrages) et Claude Code (agent d'exécution en
ligne de commande). Chaque user story a suivi le même cycle en trois
phases — **reconnaissance** (lecture seule, remontée des décisions ouvertes
sans les trancher), **arbitrage** (chaque décision tranchée par le
développeur, consignée dans les documents du dépôt), **application** (mode
« ask-before-edit », point d'arrêt obligatoire entre relecture et exécution,
suite de tests rejouée, pull request restant un geste humain).

Les tâches confiées, la supervision exercée (transgressions de périmètre
requalifiées, corrections de sécurité), et le bilan des apports et limites
constatés sont détaillés dans [docs/utilisation-ia.md](utilisation-ia.md).