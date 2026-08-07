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

Le contrat d'API se valide avec :

```bash
npx @redocly/cli lint docs/openapi.yaml
```

## État du projet

La conception fonctionnelle et technique est arrêtée ; l'implémentation du
domaine métier n'est pas commencée.

| Brique | État |
| --- | --- |
| Backend Laravel (squelette, migrations `users` / `cache` / `jobs`) | ✅ initialisé |
| Base de données PostgreSQL via Docker Compose | ✅ opérationnelle |
| Frontend Vue 3 + TypeScript (`frontend/`) | ✅ initialisé |
| Architecture technique | ✅ documentée |
| Modèle de données métier | 🟡 conçu — migrations à écrire |
| Contrat d'API | 🟡 conçu — routes et contrôleurs à écrire |
| Authentification JWT | 🟡 décidée — aucun paquet installé |
| Intégration continue | ⬜ absente |

## Stack technique

### Backend

| Composant | Version | Rôle |
| --- | --- | --- |
| PHP | ^8.3 | Langage backend |
| Laravel | ^13.8 | Framework de l'API REST |
| PostgreSQL | 17.5 | Base de données (conteneur Docker) |
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
- [Node.js](https://nodejs.org/) **22.18+ ou 24.12+** et npm — contrainte `engines`
  de [`frontend/package.json`](frontend/package.json) ; un Node 22.0 à 22.17 est refusé
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

> ⚠️ **À faire avant toute migration.** Le fichier `.env.example` est encore celui
> livré par défaut avec Laravel et pointe sur SQLite. Il faut renseigner la
> connexion PostgreSQL dans le `.env` fraîchement créé, sinon les migrations
> créeront une base SQLite locale au lieu d'utiliser le conteneur :
>
> ```dotenv
> DB_CONNECTION=pgsql
> DB_HOST=127.0.0.1
> DB_PORT=5432
> DB_DATABASE=datashare
> DB_USERNAME=datashare
> DB_PASSWORD=<valeur définie dans compose.yaml>
> ```
>
> Il serait souhaitable d'aligner directement `.env.example` sur PostgreSQL pour
> éviter cette étape manuelle.

Puis générer la clé applicative et appliquer le schéma :

```bash
php artisan key:generate
php artisan migrate
```

### 4. Installer les assets du backend

```bash
npm install
npm run build
```

Le fichier `.npmrc` du backend force `ignore-scripts=true` : les scripts
d'installation des paquets npm ne sont pas exécutés, par précaution.

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
contrat d'API).

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

Les deux serveurs tournent en parallèle : la SPA appelle l'API sur le port 8000.

## Tests

### Backend

```bash
cd backend
composer run test          # équivaut à : php artisan config:clear && php artisan test
```

Les tests s'exécutent sur une base **SQLite en mémoire** (voir
[`backend/phpunit.xml`](backend/phpunit.xml)), indépendamment du PostgreSQL de
développement : aucun conteneur n'est requis pour les lancer.

### Frontend

```bash
cd frontend
npm run test:unit          # Vitest
npm run type-check         # vue-tsc
npm run test:e2e           # Cypress sur le build de production (port 4173)
npm run test:e2e:dev       # Cypress en mode interactif sur le serveur de dev
```

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

## Structure du dépôt

```
.
├── backend/            API REST Laravel
│   ├── app/            Modèles, contrôleurs, providers
│   ├── config/         Configuration du framework
│   ├── database/       Migrations, factories, seeders
│   ├── resources/      Vues Blade, CSS, JS du squelette
│   ├── routes/         Déclaration des routes
│   ├── storage/        Logs, cache, fichiers déposés (non versionnés)
│   └── tests/          Tests unitaires et fonctionnels
├── frontend/           SPA Vue 3 + TypeScript
│   ├── cypress/        Tests end-to-end
│   └── src/
│       ├── components/ Composants réutilisables
│       ├── router/     Routes côté client
│       ├── stores/     Stores Pinia
│       └── views/      Écrans
├── docs/               Documentation de conception (fait autorité)
└── compose.yaml        Services de développement (PostgreSQL)
```

## Base de données

- **Développement** : PostgreSQL 17.5 dans Docker, données persistées dans le
  volume nommé `db_data`. Pour repartir de zéro :
  `docker compose down -v && docker compose up -d`, puis `php artisan migrate`.
- **Tests** : SQLite en mémoire, recréée à chaque exécution.

Le schéma cible est décrit dans [docs/mcd.md](docs/mcd.md) ; les migrations
actuelles sont encore celles du squelette Laravel.

## Points ouverts

- [ ] Écrire les migrations du modèle métier : table `files`, et alignement de
      `users` sur [docs/mcd.md](docs/mcd.md) (le squelette livre `name`,
      `email_verified_at` et `remember_token`, absents du modèle)
- [ ] Implémenter les 7 opérations du contrat d'API et le scheduler de purge
- [ ] Choisir et installer un paquet JWT côté backend (décision d'architecture
      prise, aucune dépendance ajoutée)
- [ ] Construire les écrans de la SPA d'après les maquettes
- [ ] Aligner `backend/.env.example` sur PostgreSQL
- [ ] Mettre en place une intégration continue (tests backend et frontend, Pint, lint)
- [ ] Prévoir en déploiement l'entrée cron appelant `schedule:run` chaque minute,
      sans laquelle aucune purge n'a lieu
- [ ] Choisir et déclarer une licence
