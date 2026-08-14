# DataShare

DataShare permet de transmettre un fichier volumineux sans le joindre à un
courriel : un utilisateur authentifié dépose un fichier (1 Go maximum) et
obtient en retour un lien de téléchargement temporaire, valable 7 jours au plus
et protégeable par mot de passe. À l'échéance le lien cesse immédiatement de
fonctionner, et le fichier est effacé du disque à la purge quotidienne suivante.
Projet réalisé dans le cadre du parcours OpenClassrooms.

## Documentation de conception

Les documents de [`docs/`](docs/) font autorité sur les choix fonctionnels et
techniques ; le présent README ne traite que de la mise en route.

| Document | Contenu |
| --- | --- |
| [docs/architecture.md](docs/architecture.md) | Composants, flux, décisions techniques et limites du scheduler |
| [docs/mcd.md](docs/mcd.md) | MCD (Merise) et MLD, contraintes, index, décisions de modélisation |
| [docs/openapi.yaml](docs/openapi.yaml) | Contrat d'API (OpenAPI 3.1) — 7 opérations |

Le contrat d'API se valide avec, **depuis la racine du dépôt** :

```bash
npx @redocly/cli lint docs/openapi.yaml
```

Un avertissement subsiste et est **attendu** : `no-server-example.com`, car la
seule entrée `servers` pointe sur `http://localhost:8000/api`. Il n'y a pas
encore de déploiement, et déclarer une URL de production inexistante
introduirait une information fausse dans le contrat. À rouvrir au premier
déploiement réel. Redocly sort malgré tout en succès : l'avertissement
n'invalide pas la description.

## État du projet

La conception fonctionnelle et technique est arrêtée ; l'implémentation du
domaine métier a démarré par l'inscription (US03).

| Brique | État |
| --- | --- |
| Backend Laravel | ✅ initialisé |
| Base de données PostgreSQL via Docker Compose | ✅ opérationnelle |
| Frontend Vue 3 + TypeScript (`frontend/`) | ✅ initialisé |
| Architecture technique | ✅ documentée |
| Authentification JWT | ✅ `php-open-source-saver/jwt-auth` installé et configuré |
| Intégration continue | ✅ GitHub Actions + hooks `pre-commit` |
| Contrat d'API | 🟡 1 opération sur 7 implémentée (`POST /api/auth/register`) |
| Modèle de données métier | 🟡 conçu — table `files` à écrire |
| Écrans de la SPA | ⬜ scaffold Vue non encore remplacé |

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
| Vite | ^8.0 | Build des assets du squelette Laravel |
| Tailwind CSS | ^4.0 | Feuilles de style du squelette Laravel |

Les deux dernières lignes concernent les assets Blade livrés par le squelette,
pas l'interface utilisateur : celle-ci est servie par `frontend/`, qui possède
sa propre chaîne de build.

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
- [Node.js](https://nodejs.org/) **22.18.x → 22.x, ou 24.12+** et npm — contrainte
  `engines` de [`frontend/package.json`](frontend/package.json)
  (`^22.18.0 || >=24.12.0`) : un Node 22.0 à 22.17 est refusé, et Node 23 aussi
- [Docker](https://docs.docker.com/) avec Docker Compose (pour PostgreSQL)

## Installation

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

### 4. Installer les assets du backend

```bash
npm install
npm run build
```

Le fichier `.npmrc` du backend force `ignore-scripts=true` : les scripts
d'installation des paquets npm ne sont pas exécutés, par précaution.

> Les étapes 3 et 4 sont enchaînées par `composer run setup` (`composer install`,
> copie du `.env`, `key:generate`, `migrate --force`, `npm install
> --ignore-scripts`, `npm run build`). Attention, ce script **n'appelle pas**
> `jwt:secret` : il reste à lancer à la main après coup.

### 5. Installer le frontend

Depuis la racine du dépôt :

```bash
cd frontend
npm install
```

> ⚠️ Contrairement au backend, `frontend/` n'a pas de `.npmrc` : `npm install`
> exécute donc le script `prepare`, soit `cypress install`, qui télécharge le
> binaire Cypress (une centaine de mégaoctets). Pour s'en passer, utiliser
> `npm install --ignore-scripts` — les tests end-to-end resteront alors
> indisponibles jusqu'à un `npx cypress install` explicite.

## Lancer l'environnement de développement

### Backend

Depuis `backend/`, une seule commande démarre le serveur HTTP, le worker de file
d'attente, le suivi des logs et Vite en parallèle :

```bash
composer run dev
```

L'API est alors disponible sur <http://localhost:8000> (préfixe `/api`, cf. le
contrat d'API). Routes réellement en place à ce stade, cf.
[`backend/routes/api.php`](backend/routes/api.php) :

| Route | Rôle |
| --- | --- |
| `GET /api/ping` | Sonde de disponibilité, hors contrat d'API |
| `POST /api/auth/register` | Inscription (US03) — renvoie un JWT et l'utilisateur créé |

Pour lancer les services séparément :

```bash
php artisan serve                          # serveur HTTP
php artisan queue:listen --tries=1         # traitement des jobs
php artisan pail                           # logs en direct
npm run dev                                # Vite en mode watch
```

### Frontend

Depuis `frontend/` :

```bash
npm run dev                                # serveur Vite, port 5173 par défaut
npm run build                              # type-check puis build de production
npm run preview                            # sert le build sur le port 4173
```

Les deux serveurs tournent en parallèle. La SPA n'appelle **pas** le port 8000
directement : [`frontend/vite.config.ts`](frontend/vite.config.ts) déclare un
proxy qui relaie `/api` vers `http://localhost:8000`. Côté code, les requêtes
visent donc des chemins relatifs (`/api/...`), ce qui évite le CORS en
développement — mais impose que `php artisan serve` tourne, sinon le proxy
renvoie une erreur de connexion.

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

La CI rejoue en plus la même suite sur PostgreSQL, pour la parité avec la
production. Pour reproduire ce second passage en local, sur une base
**dédiée** :

```bash
docker compose exec db createdb -U datashare datashare_test   # une seule fois
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 \
DB_DATABASE=datashare_test DB_USERNAME=datashare DB_PASSWORD=datashare_local \
php artisan test
```

> ⚠️ Ne jamais pointer ces variables sur `datashare` : les tests utilisent
> `RefreshDatabase`, qui migre à zéro la base ciblée et effacerait donc les
> données de développement. D'où la base séparée `datashare_test` — c'est aussi
> celle que crée le service PostgreSQL de la CI.

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
la main. C'est le comportement voulu en développement, mais inutilisable dans un
script ou un hook — d'où `npx vitest run`, la forme employée par la CI.

`test:e2e` construit puis sert l'application avant de lancer Cypress ; le binaire
Cypress doit avoir été installé (cf. installation du frontend).

## Qualité de code

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
npm run format             # Prettier sur src/
```

### Markdown

Depuis la racine du dépôt :

```bash
npx markdownlint-cli2 README.md      # ce fichier
npx markdownlint-cli2 '**/*.md'      # tout le dépôt
```

Les conventions sont dans
[`.markdownlint-cli2.jsonc`](.markdownlint-cli2.jsonc).

Attention au répertoire : lancé depuis `backend/` ou `frontend/`, `README.md`
désigne le fichier de scaffold de ce sous-projet et non celui-ci — or ces deux
scaffolds ne sont pas conformes (cf. points ouverts).

## Intégration continue

Les deux dispositifs ne se recouvrent **pas**. Chaque contrôle vit à exactement
un endroit, choisi par sa nature :

| | Rôle | Contenu |
| --- | --- | --- |
| Hooks locaux | ce qui **corrige** | `pint --dirty`, `prettier --write`, hygiène de fichiers, garde-fous du commit, `actionlint` |
| CI | ce qui **vérifie** | `pint --test`, oxlint, ESLint, `prettier --check`, vue-tsc, PHPUnit, Vitest, build, `composer validate`, `redocly lint`, `dd()` / `dump()`, gitleaks |

Aucun hook ne rejoue un job, aucun job ne rejoue un hook. Un doublon
n'apporterait que du délai gagné, au prix d'une double maintenance et d'un
commit ralenti — et une gate lente finit contournée au `--no-verify`, donc ne
vaut rien.

Corollaire avant d'ajouter un contrôle : s'il **corrige**, il va dans les
hooks ; s'il **vérifie**, il va en CI. Une vérification en hook local serait
effacée par un `--no-verify`, ce n'est donc pas une gate.

La conséquence est assumée : un écart de vérification n'est signalé qu'après le
push. C'est le prix d'un commit rapide.

### Hooks locaux — [`.pre-commit-config.yaml`](.pre-commit-config.yaml)

```bash
pipx install pre-commit
pre-commit install
pre-commit run --all-files  # premier passage sur l'existant
```

Douze hooks, un seul étage (`pre-commit`), tous rapides. Il n'y a
**pas** d'étage `pre-push` : tout ce qu'il contenait doublait la CI.

### GitHub Actions — [`.github/workflows/ci.yml`](.github/workflows/ci.yml)

Le filet non contournable. Sept jobs, tous bloquants, sur push de branche comme
sur PR vers `main` : qualité backend, tests backend (SQLite **et** PostgreSQL),
qualité frontend, tests frontend, gitleaks sur l'historique complet, contrat
d'API, et l'agrégateur `CI OK` — seul check à déclarer requis sur `main`.

Deux propriétés à connaître avant d'y toucher :

- Le `pint --dirty` des hooks ne corrige que ce qui a bougé depuis `HEAD` : un
  fichier déjà committé sans avoir été formaté n'y repasse jamais. C'est arrivé
  — `config/jwt.php` est entré en `59f9cf4`, avant l'installation des hooks en
  `9f909ea`, et n'a été signalé qu'en CI. C'est le fonctionnement attendu : le
  `pint --test` de la CI est le filet, le hook n'est qu'une commodité d'écriture.
- Les actions sont épinglées par SHA de commit, pas par tag ; l'image gitleaks
  par digest. Voir l'en-tête du workflow pour la
  procédure de rafraîchissement.

## Structure du dépôt

```text
.
├── backend/                    API REST Laravel
│   ├── app/                    Modèles, contrôleurs, form requests, providers
│   ├── config/                 Configuration du framework (dont jwt.php)
│   ├── database/               Migrations, factories, seeders
│   ├── resources/              Vues Blade, CSS, JS du squelette
│   ├── routes/                 Déclaration des routes (api.php, web.php)
│   ├── storage/                Logs, cache, fichiers déposés (non versionnés)
│   └── tests/                  Tests unitaires et fonctionnels
├── frontend/                   SPA Vue 3 + TypeScript
│   ├── cypress/                Tests end-to-end
│   └── src/
│       ├── components/         Composants réutilisables
│       ├── router/             Routes côté client
│       ├── stores/             Stores Pinia
│       └── views/              Écrans
├── docs/                       Documentation de conception (fait autorité)
├── .github/workflows/ci.yml    Pipeline d'intégration continue
├── .pre-commit-config.yaml     Hooks locaux — formatage et garde-fous
└── compose.yaml                Services de développement (PostgreSQL)
```

## Base de données

- **Développement** : PostgreSQL 17.5 dans Docker, données persistées dans le
  volume nommé `db_data`. Pour repartir de zéro :
  `docker compose down -v && docker compose up -d`, puis `php artisan migrate`.
- **Tests** : SQLite en mémoire, recréée à chaque exécution.

Le schéma cible est décrit dans [docs/mcd.md](docs/mcd.md). Trois migrations sont
en place, toutes issues du squelette Laravel à l'exception de `users`, dont la
colonne `name` a été retirée pour l'inscription (US03). Restent à aligner sur le
MCD : `email_verified_at` et `remember_token`, absents du modèle métier ; et la
table `files`, non encore créée.

## Points ouverts

- [x] Choisir et installer un paquet JWT côté backend →
      `php-open-source-saver/jwt-auth`
- [x] Aligner `backend/.env.example` sur PostgreSQL
- [x] Mettre en place une intégration continue (tests, Pint, lint, gitleaks)
- [x] Choisir et déclarer une licence → MIT
- [ ] Écrire les migrations du modèle métier : table `files`, et fin d'alignement
      de `users` sur [docs/mcd.md](docs/mcd.md) (`email_verified_at` et
      `remember_token` restent livrés par le squelette, absents du modèle)
- [ ] Implémenter les 6 opérations restantes du contrat d'API et le scheduler de
      purge (`POST /api/auth/register` est faite)
- [ ] Construire les écrans de la SPA d'après les maquettes ; le scaffold Vue
      (`HelloWorld`, `TheWelcome`, `AboutView`, store `counter`) est encore en place
- [ ] Remplacer `backend/README.md` et `frontend/README.md`, restés les fichiers
      par défaut de Laravel et du template Vue (28 issues markdownlint à eux
      deux)
- [ ] Ajouter `markdownlint` **à la CI** une fois ces deux fichiers remplacés —
      c'est une vérification, sa place est là et non dans les hooks
- [ ] Mettre à jour [docs/architecture.md](docs/architecture.md), qui indique
      encore qu'aucun paquet JWT n'est installé
- [ ] Prévoir en déploiement l'entrée cron appelant `schedule:run` chaque minute,
      sans laquelle aucune purge n'a lieu
- [ ] Déclarer une URL de production dans `servers` du contrat d'API, au premier
      déploiement (lève l'avertissement `no-server-example.com`)

## Licence

[MIT](LICENSE). L'identifiant est déclaré aux trois endroits qui l'exposent :
[`LICENSE`](LICENSE), `info.license` de
[`docs/openapi.yaml`](docs/openapi.yaml), et le champ `license` de
[`backend/composer.json`](backend/composer.json).
