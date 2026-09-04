# DataShare

![CI](https://github.com/DahliaNoir71/OPENCLASSROOMS-DataShare/actions/workflows/pr.yml/badge.svg)

DataShare permet de transmettre un fichier volumineux sans le joindre à un
courriel : un utilisateur authentifié dépose un fichier (1 Go maximum) et
obtient en retour un lien de téléchargement temporaire, valable 7 jours au plus
et protégeable par mot de passe. À l'échéance le lien cesse immédiatement de
fonctionner, et le fichier est effacé du disque à la purge quotidienne suivante.
Projet réalisé dans le cadre du parcours OpenClassrooms.

## Documentation de conception

Les documents de [`docs/`](docs/) font autorité sur les choix fonctionnels et
techniques. Quatre documents qualité vivent à la racine du dépôt, à l'endroit où
on les attend. Le présent README ne traite que de la mise en route.

| Document | Contenu |
| --- | --- |
| [docs/architecture.md](docs/architecture.md) | Composants, flux aller et retour, cache, journalisation, décisions techniques et limites du scheduler |
| [docs/mcd.md](docs/mcd.md) | MCD (Merise) et MLD, contraintes, index, décisions de modélisation |
| [docs/openapi.yaml](docs/openapi.yaml) | Contrat d'API (OpenAPI 3.1) — 9 opérations |
| [docs/design-tokens.md](docs/design-tokens.md) | Jetons de design de la SPA (couleurs, typographie, espacements) |
| [docs/utilisation-ia.md](docs/utilisation-ia.md) | Posture d'usage de l'IA générative dans le développement, cycle en trois phases par user story, supervision et correctifs, apports et limites constatés |
| [TESTING.md](TESTING.md) | Plan de tests, matrice US × niveau, couverture, critères de sortie |
| [SECURITY.md](SECURITY.md) | Compte rendu de scans en trois seaux (corrigées / acceptées / ignorées) et limites de sécurité assumées |
| [PERF.md](PERF.md) | Campagne k6, logs structurés JSON, arbitrages de performance |
| [MAINTENANCE.md](MAINTENANCE.md) | Exploitation (entrée cron du scheduler, purge quotidienne) et veille automatisée des dépendances |

## État du projet

La conception fonctionnelle et technique est arrêtée. L'implémentation du
domaine métier a démarré par l'authentification (inscription US03, connexion
US04), puis a couvert le dépôt d'un fichier (US01), le parcours de
téléchargement par lien public (US02), l'historique des fichiers (US05), la
suppression manuelle (US06) et la purge planifiée des fichiers expirés
(US10). Le domaine métier est complet ; ce qui reste ouvert tient au
déploiement, pas au code.

| Brique | État |
| --- | --- |
| Backend Laravel | ✅ initialisé |
| Base de données PostgreSQL via Docker Compose | ✅ opérationnelle |
| Frontend Vue 3 + TypeScript (`frontend/`) | ✅ initialisé |
| Architecture technique | ✅ documentée |
| Authentification JWT | ✅ `php-open-source-saver/jwt-auth` installé et configuré |
| Contrat d'API | ✅ 9 opérations sur 9 implémentées — les quatre d'authentification, le dépôt de fichier (US01), le parcours de téléchargement (US02), l'historique (US05) et la suppression manuelle (US06) |
| Modèle de données métier | ✅ table `files` migrée et exploitée |
| Écrans de la SPA | ✅ accueil, inscription, connexion, dépôt, historique (« Mon espace ») et écran de partage (`/l/:token`) en place |
| Purge planifiée des fichiers expirés | ✅ commande `files:purge-expired`, planifiée quotidiennement — l'entrée cron reste à poser en déploiement ([MAINTENANCE.md](MAINTENANCE.md)) |

## Stack technique

### Backend

| Composant | Version | Rôle |
| --- | --- | --- |
| PHP | ^8.3 | Langage backend |
| Laravel | ^13.8 | Framework de l'API REST |
| PostgreSQL | 17.5 | Base de données (conteneur Docker) |
| php-open-source-saver/jwt-auth | ^2.9 | Émission et vérification des JWT |
| PHPUnit | ^12.5 | Tests automatisés |
| Laravel Pint | ^1.27 | Formatage du code PHP |
| Laravel Pail | ^1.2 | Lecture des logs en direct |

Le backend n'a **aucune chaîne de build front**, donc aucune dépendance npm : le
`package.json`, le `vite.config.js` et le dossier `resources/` livrés par le
squelette Laravel ont été supprimés, puisqu'ils ne servaient qu'à compiler la
page `welcome` par défaut. L'interface utilisateur est servie exclusivement par
`frontend/`, qui possède sa propre chaîne de build.

### Frontend

| Composant | Version | Rôle |
| --- | --- | --- |
| Vue | ^3.5 | Framework de la SPA |
| TypeScript | ~6.0 | Langage |
| Vite | ^8.1 | Serveur de développement et build |
| Vue Router | ^5.2 | Routage côté client |
| Pinia | ^4.0 | État partagé |
| Vitest | ^4.1 | Tests unitaires |
| Cypress | ^15.18 | Tests end-to-end |
| oxlint / ESLint | ~1.77 / ^10.7 | Analyse statique |
| Prettier | 3.9.5 | Formatage |

## Prérequis

- PHP **8.3** ou supérieur, avec les extensions habituelles de Laravel (dont `pdo_pgsql`)
- [Composer](https://getcomposer.org/) 2.x
- [Node.js](https://nodejs.org/) **22.18.x → 22.x, ou 24.12+** et npm, pour le
  frontend uniquement — contrainte `engines` de
  [`frontend/package.json`](frontend/package.json) (`^22.18.0 || >=24.12.0`) :
  un Node 22.0 à 22.17 est refusé, et Node 23 aussi
- [Docker](https://docs.docker.com/) avec Docker Compose (pour PostgreSQL)
- Pour le dépôt de fichiers (US01), un `php.ini` acceptant un fichier de 1 Go :
  `upload_max_filesize = 1100M` et `post_max_size = 1200M` (marge au-delà de
  1 Go pour l'enveloppe multipart — champs `password`, `expires_in_days`,
  en-têtes de la requête). `php artisan serve` étant servi par le SAPI CLI de
  PHP, c'est le `php.ini` retourné par `php --ini` (`Loaded Configuration
  File`) qu'il faut éditer — les valeurs par défaut du framework (2M / 8M)
  refusent silencieusement tout fichier au-delà de 2 Mo. Ce prérequis ne vaut
  que pour le poste de développement : en déploiement, c'est le `php.ini` de
  php-fpm qui s'applique, à aligner de la même façon.
- Pour le téléchargement (US02), rien à régler en développement : sous le SAPI
  CLI de `php artisan serve`, `max_execution_time` vaut `0`. En déploiement, en
  revanche, ce sont `request_terminate_timeout` du pool php-fpm et le
  `fastcgi_read_timeout` (ou `proxy_read_timeout`) du serveur frontal qui
  coupent un gros téléchargement lent — pas `max_execution_time`, que PHP
  n'incrémente pas pendant les entrées-sorties de flux. À relever au même titre
  que `post_max_size`. `memory_limit` n'est pas concerné : les octets sont
  servis en flux, jamais chargés en mémoire.

## Installation

Ces étapes sont automatisées par `scripts/install.sh` (et `scripts/db-setup.sh`
pour la seule base de données) ; la suite ci-dessous reste la version
manuelle, pas à pas.

### 1. Cloner le dépôt

```bash
git clone <url-du-depot> OPENCLASSROOMS-DataShare
cd OPENCLASSROOMS-DataShare
```

### 2. Démarrer la base de données

```bash
docker compose up -d
```

Le service `db` expose PostgreSQL sur `localhost:5432`. Les identifiants de
développement (base, utilisateur, mot de passe) sont définis dans
[`compose.yaml`](compose.yaml) — ils ne sont volontairement pas recopiés ici afin
de garder une seule source de vérité.

Vérifier que le conteneur est bien démarré et sain :

```bash
docker compose ps
```

### 3. Configurer le backend

```bash
cd backend
composer install
cp .env.example .env
```

[`.env.example`](backend/.env.example) est déjà aligné sur le PostgreSQL de
[`compose.yaml`](compose.yaml) : aucune variable `DB_*` n'est à retoucher pour un
poste de développement.

Puis générer les deux secrets et appliquer le schéma :

```bash
php artisan key:generate     # APP_KEY — chiffrement applicatif
php artisan jwt:secret       # JWT_SECRET — signature des jetons (HS256)
php artisan migrate
```

Les deux clés sont laissées vides dans `.env.example` et ne sont jamais
versionnées. **`jwt:secret` n'est pas optionnel** : sans lui, toute route
d'authentification échoue à la signature.

> L'étape 3 est enchaînée par `composer run setup` (`composer install`, copie du
> `.env`, `key:generate`, `jwt:secret` si `JWT_SECRET` est vide, `migrate
> --force`). La génération du secret JWT n'a lieu que si `JWT_SECRET` est vide
> dans `.env` : rejouer `composer run setup` sur une installation existante ne
> régénère pas un secret déjà en place, ce qui invaliderait tous les jetons en
> cours. Pour le régénérer volontairement, `php artisan jwt:secret --force`
> reste la voie manuelle.

Aucun `npm install` n'est à lancer dans `backend/` : l'API ne sert aucun asset.

### 4. Installer le frontend

Depuis la racine du dépôt :

```bash
cd frontend
npm install
```

> ⚠️ `npm install` exécute ici le script `prepare`, soit `cypress install`, qui
> télécharge le binaire Cypress (une centaine de mégaoctets). Pour s'en passer,
> utiliser `npm install --ignore-scripts` — les tests end-to-end resteront alors
> indisponibles jusqu'à un `npx cypress install` explicite.

## Lancer l'environnement de développement

Il n'existe **pas** de commande unique : l'API et la SPA sont deux serveurs
distincts, à démarrer dans deux terminaux, une fois la base de données lancée
(`docker compose up -d` depuis la racine).

### Backend

Depuis `backend/` :

```bash
php artisan serve
```

L'API est alors disponible sur <http://localhost:8000> (préfixe `/api`, cf. le
contrat d'API). Routes réellement en place à ce stade, cf.
[`backend/routes/api.php`](backend/routes/api.php) :

| Route | Rôle |
| --- | --- |
| `GET /api/ping` | Sonde de disponibilité, hors contrat d'API |
| `POST /api/auth/register` | Inscription (US03) — renvoie un JWT et l'utilisateur créé |
| `POST /api/auth/login` | Connexion (US04) — renvoie un JWT |
| `GET /api/auth/me` | Utilisateur du jeton porté par la requête |
| `POST /api/auth/logout` | Invalidation du jeton courant |
| `GET /api/files` | Historique des fichiers de l'utilisateur (US05) — pagination, filtre `status` |
| `POST /api/files` | Dépôt d'un fichier authentifié (US01) — renvoie ses métadonnées et son lien de téléchargement |
| `DELETE /api/files/{id}` | Suppression manuelle d'un fichier de l'utilisateur (US06) — `204` sans corps, irréversible |
| `GET /api/links/{token}` | Métadonnées publiques d'un lien (US02) — nom, taille, type, expiration, protégé ou non |
| `POST /api/links/{token}/download` | Téléchargement du fichier (US02, US09) — mot de passe dans le corps, jamais dans l'URL |

S'y ajoute `GET /up`, la sonde de santé du framework déclarée dans
[`bootstrap/app.php`](backend/bootstrap/app.php). Aucune autre route web n'est
exposée : [`routes/web.php`](backend/routes/web.php) est vide.

Toutes les routes `/api` sont plafonnées à 60 requêtes par minute (par
utilisateur authentifié, sinon par IP). `register` et `login`, ouvertes à tous,
sont en plus plafonnées à 5 par minute et par IP — ce sont celles qu'une attaque
par bourrage d'identifiants viserait ; `me`, `logout`, l'historique
(`GET /files`, US05) et la suppression (`DELETE /files/{id}`, US06), derrière
un jeton, ne relèvent que du plafond général.
`POST /files` (US01) a son propre plafond, 10
par minute et par utilisateur : un ceiling qui n'a rien à voir avec celui d'un
simple appel JSON, chaque appel pouvant transporter jusqu'à 1 Go.

`POST /api/links/{token}/download` (US02) est le seul à porter **deux plafonds
simultanés**, parce que deux attaques distinctes visent la même route : 10 par
minute et par lien, contre la recherche du mot de passe d'un partage connu, et
30 par minute et par IP, contre le balayage de l'espace des jetons. Les deux
doivent passer. Le `GET` des métadonnées, lui, s'en tient au plafond général —
pour un appelant anonyme celui-ci compte déjà par IP, et 22 caractères base62
rendent l'énumération vaine de toute façon.

Un dépassement renvoie `429` avec un en-tête `Retry-After`. Les quatre
limiteurs sont définis dans
[`AppServiceProvider`](backend/app/Providers/AppServiceProvider.php) ; leurs
compteurs sont tenus dans le store de cache (`CACHE_STORE=database`, soit la
table `cache`), seul usage du cache à ce stade — et la clé du plafond par lien
est un condensat, pour qu'un jeton de partage ne se retrouve pas au repos dans
cette table. Chaque dépassement laisse une ligne `warning` dans les journaux :
sans elle, Laravel ne rapporte pas les `429` et une attaque par bourrage
d'identifiants passerait inaperçue.

Toute réponse `/api` porte `Cache-Control: no-store, private`, posé par le
middleware [`NoStore`](backend/app/Http/Middleware/NoStore.php) en tête du
groupe : aucune réponse de cette API n'est stockable, ni par un proxy ni par le
navigateur. Le raisonnement est dans
[docs/architecture.md](docs/architecture.md#cache).

Trois processus facultatifs, à lancer dans des terminaux supplémentaires selon
le besoin :

```bash
php artisan queue:listen --tries=1         # traitement des jobs
php artisan pail                           # logs en direct
php artisan schedule:work                  # boucle : schedule:run chaque minute
```

Le worker de file d'attente ne sert toujours à rien : aucun job n'est défini
(pas de `app/Jobs`, aucun `dispatch()`), et la purge planifiée (US10) n'en est
pas un — c'est une commande Artisan, exécutée en synchrone par le scheduler.
Le choix est délibéré : passer par la file ajouterait un second processus à
superviser et une seconde façon d'échouer, pour une tâche quotidienne qui dure
quelques secondes et ne fait attendre personne. Le worker deviendra nécessaire
le jour où un traitement devra rendre la main avant d'être terminé — un envoi
de courriels, typiquement.

Un poste de développement n'a pas de cron : `schedule:work` en tient lieu le
temps d'une session, ce n'est pas la voie de déploiement — celle-ci est une
entrée cron, décrite dans [MAINTENANCE.md](MAINTENANCE.md). Pour lancer la
purge sans attendre son heure : `php artisan files:purge-expired`, rejouable
sans dommage.

### Frontend

Depuis `frontend/` :

```bash
npm run dev                                # serveur Vite, port 5173 par défaut
npm run build                              # type-check puis build de production
npm run preview                            # sert le build sur le port 4173
```

C'est <http://localhost:5173> qu'on ouvre dans le navigateur — le port 8000 n'a
aucune interface à servir. La SPA n'appelle **pas** ce port directement :
[`frontend/vite.config.ts`](frontend/vite.config.ts) déclare un proxy qui relaie
`/api` vers `http://localhost:8000`. Côté code, les requêtes visent donc des
chemins relatifs (`/api/...`), ce qui évite le CORS en développement — mais
impose que `php artisan serve` tourne, sinon le proxy renvoie une erreur de
connexion.

## Tests

### Backend

```bash
cd backend
composer run test                                     # config:clear puis php artisan test
php artisan test                                      # la suite seule
php artisan test tests/Feature/Auth/RegisterTest.php  # un seul fichier
php artisan test --filter=RegisterTest                # filtrer par nom
php artisan test --testsuite=Feature                  # une seule suite
```

Les tests s'exécutent sur une base **SQLite en mémoire** (voir
[`backend/phpunit.xml`](backend/phpunit.xml)), indépendamment du PostgreSQL de
développement : aucun conteneur n'est requis pour les lancer.

La production tournant sur PostgreSQL, il est prudent de rejouer la même suite
sur ce moteur avant une étape importante, sur une base **dédiée** :

```bash
docker compose exec db createdb -U datashare datashare_test   # une seule fois
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 \
DB_DATABASE=datashare_test DB_USERNAME=datashare DB_PASSWORD=datashare_local \
php artisan test
```

> ⚠️ Ne jamais pointer ces variables sur `datashare` : les tests utilisent
> `RefreshDatabase`, qui migre à zéro la base ciblée et effacerait donc les
> données de développement. D'où la base séparée `datashare_test`.

### Frontend

```bash
cd frontend
npm run test:unit          # Vitest en mode watch (interactif)
npx vitest run             # Vitest en une passe, code de sortie exploitable
npm run type-check         # vue-tsc
npm run test:e2e           # Cypress sur le build de production (port 4173)
npm run test:e2e:dev       # Cypress en mode interactif sur le serveur de dev
```

`test:unit` appelle `vitest` sans `run` : il **reste en watch** et ne rend jamais
la main. C'est le comportement voulu en développement, mais inutilisable dès
qu'on veut un code de sortie exploitable — d'où `npx vitest run`.

`test:e2e` construit puis sert l'application avant de lancer Cypress ; le binaire
Cypress doit avoir été installé (cf. installation du frontend).

Suites avec couverture, seuils bloquants (70 %) :

```bash
composer run test:coverage   # backend
npm run test:coverage        # frontend
```

[TESTING.md](TESTING.md) fait autorité sur la stratégie de tests, la matrice
US × niveau et les critères d'acceptation — ce README ne garde que les
commandes de lancement. La CI (`pr.yml`) rejoue l'ensemble de ces suites à
chaque pull request.

## Qualité de code

Ces commandes se lancent à la main en local ; elles sont aussi rejouées
automatiquement en CI — `push.yml` en feedback rapide (lint + tests
unitaires), `pr.yml` de façon complète, avec le check `ci-ok` requis au
merge sur `main`.

### Backend

```bash
cd backend
./vendor/bin/pint          # formate le code PHP
./vendor/bin/pint --test   # vérifie sans modifier
```

### Frontend

```bash
cd frontend
npm run lint               # oxlint puis ESLint, avec correction automatique
npm run lint:check         # vérification seule, sans correction (utilisé en CI)
npm run format             # Prettier sur src/
```

### Markdown

Les conventions restent décrites dans
[`.markdownlint-cli2.jsonc`](.markdownlint-cli2.jsonc), lu directement par
l'extension markdownlint de l'éditeur : les écarts se voient en écrivant, sans
passage en ligne de commande.

## Structure du dépôt

```text
.
├── backend/                    API REST Laravel
│   ├── app/                    Modèles, contrôleurs, requests, services, exceptions
│   ├── config/                 Configuration du framework (dont jwt.php)
│   ├── database/               Migrations, factories, seeders
│   ├── routes/                 Déclaration des routes et tâches planifiées
│   │                           (api.php, web.php, console.php)
│   ├── storage/                Logs, cache, fichiers déposés (non versionnés)
│   └── tests/                  Tests unitaires et fonctionnels
├── frontend/                   SPA Vue 3 + TypeScript
│   ├── cypress/                Tests end-to-end
│   └── src/
│       ├── assets/             Feuilles de style, jetons de design, polices
│       ├── components/         Composants réutilisables
│       ├── router/             Routes côté client
│       ├── stores/             Stores Pinia
│       └── views/              Écrans
├── docs/                       Documentation de conception (fait autorité)
└── compose.yaml                Services de développement (PostgreSQL)
```

## Base de données

- **Développement** : PostgreSQL 17.5 dans Docker, données persistées dans le
  volume nommé `db_data`. Pour repartir de zéro :
  `docker compose down -v && docker compose up -d`, puis `php artisan migrate`.
- **Tests** : SQLite en mémoire, recréée à chaque exécution.

Le schéma cible est décrit dans [docs/mcd.md](docs/mcd.md). Cinq migrations
sont en place : trois issues du squelette Laravel — à ceci près que `users` a
perdu sa colonne `name` pour l'inscription (US03) —, une qui ajoute l'index
d'unicité insensible à la casse décrit ci-dessous, et une qui crée la table
`files` (US01). Reste à aligner sur le MCD : `email_verified_at` et
`remember_token`, absents du modèle métier.

### Unicité de l'email

PostgreSQL comme SQLite comparent les chaînes en respectant la casse : sans
précaution, `jane@x.com` et `Jane@x.com` donneraient deux comptes distincts
malgré la contrainte `unique` sur la colonne. L'unicité est donc tenue à trois
niveaux :

| Niveau | Où | Rôle |
| --- | --- | --- |
| Validation | `RegisterRequest::prepareForValidation()` | Normalise avant que `unique:users,email` ne cherche en base |
| Écriture | Mutateur `email` du modèle `User` | La colonne ne reçoit que des minuscules |
| Base | Index `users_email_lower_unique` sur `LOWER(email)` | Filet pour toute écriture qui contournerait Eloquent |

L'index fonctionnel est posé en SQL brut : le constructeur de schéma de Laravel
n'a pas de syntaxe portable pour indexer une expression. La même instruction est
acceptée par PostgreSQL et par SQLite, aucune variante par pilote n'est requise.
Il fait doublon avec le `unique` de la colonne, qu'il subsume — ce dernier est
conservé pour que la contrainte reste lisible dans la définition de la table.

## Points ouverts

- [x] Choisir et installer un paquet JWT côté backend →
      `php-open-source-saver/jwt-auth`
- [x] Aligner `backend/.env.example` sur PostgreSQL
- [x] Choisir et déclarer une licence → MIT
- [x] Écrire la migration du modèle métier : table `files` (US01)
- [x] Aligner `users` sur [docs/mcd.md](docs/mcd.md) (`email_verified_at` et
      `remember_token` restent livrés par le squelette, absents du modèle)
- [x] Implémenter les 9 opérations du contrat d'API — les 4 d'authentification,
      le dépôt de fichier (US01), le parcours de téléchargement (US02),
      l'historique (US05) et la suppression manuelle (US06)
- [x] Écrire le scheduler de purge (US10) → commande `files:purge-expired`,
      planifiée dans [`backend/routes/console.php`](backend/routes/console.php)
- [x] Construire l'écran de partage de la SPA d'après les maquettes (US02) →
      `/l/:token`, route publique sans garde
- [x] Ajouter une route de repli 404 au routeur : un chemin totalement
      étranger (hors `/l/:token`, qui capte déjà un token invalide) rend
      aujourd'hui une page vide → `NotFoundView`, route catch-all
      `/:pathMatch(.*)*`
- [x] Supprimer la chaîne de build front du backend, devenue morte :
      `package.json`, `.npmrc`, `vite.config.js`, `resources/` et la route `/`
      de `routes/web.php`
- [x] Mettre à jour [docs/architecture.md](docs/architecture.md), qui indiquait
      encore qu'aucun paquet JWT n'était installé
- [x] Compléter la piste d'audit avec `Expired files purged` → la piste
      d'audit est complète, convention dans
      [docs/architecture.md](docs/architecture.md#la-piste-daudit)
- [x] Micro-lot `fix/US01-expiration-bounds` : `expires_in_days` transmis vide
      crée un fichier déjà expiré (le défaut de 7 jours ne s'applique que si
      le champ est absent, pas s'il est `null`), et `default_expiry_days` /
      `max_expiry_days` sont indépendants en configuration — rien ne garantit
      le premier inférieur ou égal au second
- [x] Nettoyer les répertoires `AAAA/MM/JJ` vides que la purge laisse derrière
      elle sur le disque `uploads` : `FileStorageService::delete()` efface les
      fichiers, jamais les répertoires qui les contenaient

### Au premier déploiement

- [ ] Prévoir en déploiement l'entrée cron appelant `schedule:run` chaque minute,
      sans laquelle aucune purge n'a lieu → ligne exacte dans
      [MAINTENANCE.md](MAINTENANCE.md)
- [ ] En déploiement : `APP_DEBUG=false`, `LOG_LEVEL=info` — et non `warning`,
      qui ferait taire la piste d'audit —, canal `daily` ou `stderr`, et
      `CACHE_STORE=redis` si la charge le justifie
- [ ] Déclarer une URL de production dans `servers` du contrat d'API, au premier
      déploiement — l'unique entrée pointe aujourd'hui sur `localhost`

## Licence

[MIT](LICENSE). L'identifiant est déclaré aux trois endroits qui l'exposent :
[`LICENSE`](LICENSE), `info.license` de
[`docs/openapi.yaml`](docs/openapi.yaml), et le champ `license` de
[`backend/composer.json`](backend/composer.json).
