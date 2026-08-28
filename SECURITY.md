# Sécurité

Ce document consigne la politique de sécurité du prototype ainsi que le
compte rendu de la campagne de scans du 2026-08-28 (composer audit, npm
audit, Trivy, Gitleaks). Il regroupe les constats en trois seaux : ce qui a
été corrigé, ce qui est assumé comme limite du MVP, et ce qu'un outil a
signalé sans risque réel ici.

## Corrigées

- **nanoid — GHSA-2v37-7h3g-55p8 (HIGH)** — dépendance transitive de build
  `vite → postcss → nanoid`, verrouillée en 3.3.17. Corrigée par montée de
  verrou vers 3.3.18 dans `frontend/package-lock.json` (`npm audit` confirme
  0 vulnérabilité après correctif). Le risque réel était nul avant même la
  correction : nanoid n'intervient qu'à la construction (jamais dans le
  bundle livré au navigateur), et le vecteur de la CVE — un générateur
  personnalisé appelé avec `size=0` — n'est jamais exercé par la façon dont
  Vite et PostCSS invoquent la librairie.

## Acceptées

Limites assumées du prototype, relevées au fil des lots fonctionnels.

- **Limitation de débit keyée par IP seule** (US04) — le limiteur `auth`
  plafonne `/auth/register` et `/auth/login` à 5 requêtes par minute et par
  adresse IP. Il n'existe aucun verrouillage par compte : un attaquant
  disposant de plusieurs adresses sources peut donc cibler un compte donné
  sans jamais franchir le seuil. Contrôle jugé suffisant pour le MVP.
- **TTL du jeton JWT à 60 minutes** (US04) — valeur par défaut de jwt-auth,
  non surchargée. En cas de vol d'un jeton, la fenêtre d'exposition est d'une
  heure. Elle est compensée par la révocation côté serveur : `POST
  /auth/logout` inscrit le jeton sur la liste noire, qui le refuse dès lors
  sur toute requête ultérieure.
- **Documents à macros autorisés au dépôt** (US01) — la liste noire
  d'extensions (`config/datashare.php`) ne bloque ni `.docm`, ni `.xlsm`, ni
  `.pptm`. Ce sont des fichiers bureautiques ordinaires qu'un utilisateur
  légitime partage, et la menace qu'ils portent — une macro malveillante —
  suppose que le destinataire l'active lui-même à l'ouverture : elle ne
  s'exécute pas du seul fait du dépôt ou du téléchargement, contrairement à un
  exécutable. Ce vecteur est jugé hors du modèle de menace du MVP, où DataShare
  ne fait que transporter des octets sans les interpréter. Réévaluable si le
  service s'adresse à un public où ce risque devient significatif (ex. :
  usage interne à une organisation avec macros activées par défaut).
- **Suppression non atomique** (US06) — `FileStorageService::delete()` efface
  le contenu physique puis la ligne en base, sans transaction englobante : une
  transaction SQL n'annule pas une suppression de fichier déjà exécutée. Une
  fenêtre existe donc entre les deux étapes ; si la seconde échoue, la
  conséquence est observable — le fichier répond `410` et non `404`, et une
  ligne `error` (`Link content missing`) apparaît au prochain téléchargement.
  Réparation : rejouer le `DELETE`.
- **Purge interrompue, réparée d'elle-même** (US10) — la purge efface le
  contenu physique puis la ligne en base, dans cet ordre et sans transaction
  englobante, exactement comme la suppression manuelle ci-dessus : la même
  fenêtre existe donc entre les deux étapes, et le même symptôme l'expose —
  un `410` au lieu d'un `404`, une ligne `error` (`Link content missing`) au
  prochain téléchargement. La différence est dans la réparation. Là où US06
  laisse un état que seul un `DELETE` rejoué à la main résout, l'échéance,
  elle, ne s'efface pas : le passage suivant retrouve la ligne toujours
  expirée et retente, la suppression d'un fichier déjà absent étant sans
  effet. La réparation est donc automatique, et bornée au prochain passage.
  L'ordre inverse ne se réparerait jamais : une ligne effacée avant ses
  octets laisserait sur le disque un fichier que plus aucune requête ne
  référence et qu'aucun passage ne retrouverait.
- **Purge non surveillée** (US10) — aucun seuil ni alerte ne signale qu'un
  passage n'a pas eu lieu : la seule preuve qu'elle tourne est la ligne
  `Expired files purged` dans les journaux, et son absence ne provoque aucune
  réponse HTTP. Un scheduler arrêté est donc indétectable jusqu'à saturation
  du disque. Même limite, et même nature, que `Login failed` et `File
  deletion refused` : le journal enregistre, personne ne le lit
  automatiquement. La procédure de contrôle manuel est dans
  [MAINTENANCE.md](MAINTENANCE.md).
- **Octets orphelins d'un compte supprimé** (US10) — la suppression d'un
  compte efface ses fichiers par `ON DELETE CASCADE` sur `user_id` : les
  lignes disparaissent, mais leurs octets restent sur le disque, puisque le
  SGBD n'a aucune prise sur le système de fichiers. Une purge fondée sur un
  balayage de lignes ne peut pas les retrouver — sans ligne, aucun `expires_at`
  à comparer. Limite d'effacement préexistante à ce lot, consignée ici sans
  être traitée : elle demanderait un mécanisme distinct, hors périmètre du
  prototype.
- **Irréversibilité sans pierre tombale** (US06) — une fois la ligne
  supprimée, le lien de partage répond `404` et non `410` : rien ne distingue
  plus « jamais émis » de « supprimé ». La ligne d'audit `File deleted` est la
  seule trace qui subsiste de la suppression.
- **Sondage journalisé mais non détecté** (US06) — une tentative de
  suppression du fichier d'un autre compte écrit une ligne `File deletion
  refused`, mais aucun seuil ni alerte ne surveille sa fréquence : seule une
  lecture manuelle du journal la révèle. Même limite, et même nature, que
  `Login failed`.

## Ignorées

Constats remontés par un outil de la campagne, sans risque réel dans ce
projet.

- **`backend/vendor/mockery/mockery/docs/requirements.txt` (Trivy fs, 20
  CVE, dont 7 HIGH)** — dépendances Python de génération de la documentation
  de Mockery (dépendance de test PHP vendorisée). Jamais installées ni
  exécutées par ce projet : ni Python, ni pip, ni ces paquets ne font partie
  de l'exécution de DataShare.
- **`backend/.env:67` (Gitleaks, mode filesystem, règle `generic-api-key`) —
  valeur du `JWT_SECRET` local** — fichier gitignoré (`backend/.gitignore`),
  jamais tracké : le même scan Gitleaks rejoué en mode historique git (167
  commits) ne trouve aucune fuite. Secret de développement généré localement
  sur cette machine, absent du dépôt.
- **6 clés privées de test + 1 fixture les référençant, sous
  `backend/vendor/namshi/jose/tests/`, plus un faux positif dans
  `backend/vendor/symfony/mime/MimeTypes.php:2838` (Gitleaks, règle
  `private-key` / `generic-api-key`)** — fixtures de test d'un package tiers
  vendorisé (`namshi/jose`) et une chaîne de type MIME mal détectée comme
  secret. Aucun de ces fichiers n'est servi ni exécuté en production par
  DataShare.
- **`/etc/ssl/private/ssl-cert-snakeoil.key` sur l'image `postgres:17.5`
  (Trivy image, secret HIGH)** — certificat auto-signé « snakeoil », généré
  par le paquet Debian `ssl-cert` à la construction de toute image basée sur
  cette distribution. Placeholder standard, non spécifique à ce projet, non
  actionnable.

**Non retenus dans ces seaux** : `backend/phpunit.xml:29` (`JWT_SECRET` de
test) et `compose.yaml` (`POSTGRES_PASSWORD` du conteneur Postgres local)
contiennent des identifiants de développement, mais aucun des outils de
cette campagne ne les a signalés (entropie/format insuffisants pour les
règles Gitleaks). Ils restent hors des trois seaux ci-dessus ; ce sont des
identifiants de développement délibérés, documentés dans
`backend/.env.example`.

## Résultats bruts par outil

| Outil | Version | Date | Verdict |
|---|---|---|---|
| `composer audit` | Composer 2.10.2 / PHP 8.3.6 | 2026-08-28 | Aucune vulnérabilité |
| `npm audit` (frontend) | npm 12.0.2 | 2026-08-28 | 0 vulnérabilité (nanoid 3.3.18 confirmé) |
| Trivy `fs` | 0.72.0, DB 2026-08-28 | 2026-08-28 | 3 cibles propres (`backend/composer.lock`, `frontend/package-lock.json`, lockfile npm vendorisé de Laravel) ; 20 CVE sur `backend/vendor/mockery/mockery/docs/requirements.txt` — voir Ignorées |
| Gitleaks (mode git) | v8.30.1 | 2026-08-28 | Aucune fuite sur 167 commits |
| Gitleaks (mode filesystem, `--no-git`) | v8.30.1 | 2026-08-28 | 8 findings — voir Ignorées |
| Trivy `image postgres:17.5` | 0.72.0, DB 2026-08-28 | 2026-08-28 | 700 CVE (debian 13.0), 111 CVE (`gosu`), 1 secret (`ssl-cert-snakeoil.key`) — voir ci-dessous |

**Trivy sur `postgres:17.5`** : les 700 CVE de la base Debian 13.0 et les 111
CVE du binaire `gosu` appartiennent à l'image officielle amont, non au code
du projet — aucune n'est actionnable par DataShare, qui ne fait que
consommer cette image telle quelle. Le seul finding propre à documenter est
le certificat snakeoil, traité en section Ignorées.

## Veille

Les dépendances en retard qui ne portent pas d'avis de sécurité ne sont pas
des vulnérabilités et ne sont pas listées ici : voir
[MAINTENANCE.md](MAINTENANCE.md), section Rythme.

## Reproduction

```bash
# depuis backend/
composer audit

# depuis frontend/
npm audit

# depuis la racine du dépôt
docker run --rm --user "$(id -u):$(id -g)" -v "$(pwd):/repo:ro" aquasec/trivy fs /repo

# depuis la racine du dépôt
docker run --rm -v "$(pwd):/repo:ro" zricethezav/gitleaks detect --source /repo

# depuis la racine du dépôt (comparaison filesystem, hors historique git)
docker run --rm -v "$(pwd):/repo:ro" zricethezav/gitleaks detect --source /repo --no-git

# sans répertoire particulier
docker run --rm aquasec/trivy image postgres:17.5
```
