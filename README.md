# DataShare

> **TODO** — Décrire en une à deux phrases la finalité fonctionnelle de l'application
> (quel besoin, pour quels utilisateurs). Projet réalisé dans le cadre du parcours
> OpenClassrooms.

## État du projet

Projet en cours d'initialisation. Le squelette technique est en place ; le domaine
fonctionnel n'est pas encore implémenté.

| Brique | État |
| --- | --- |
| Backend Laravel (squelette, migrations `users` / `cache` / `jobs`) | ✅ initialisé |
| Base de données PostgreSQL via Docker Compose | ✅ opérationnelle |
| Modèle de données métier | ⬜ à concevoir |
| API | ⬜ à concevoir |
| Frontend (`frontend/`) | ⬜ stack à définir |
| Documentation (`docs/`) | ⬜ vide |
| Intégration continue | ⬜ absente |

## Stack technique

| Composant | Version | Rôle |
| --- | --- | --- |
| PHP | ^8.3 | Langage backend |
| Laravel | ^13.8 | Framework backend |
| PostgreSQL | 17.5 | Base de données (conteneur Docker) |
| Vite | ^8.0 | Build des assets |
| Tailwind CSS | ^4.0 | Feuilles de style |
| PHPUnit | ^12.5 | Tests automatisés |
| Laravel Pint | ^1.27 | Formatage du code PHP |
| Laravel Pail | ^1.2 | Lecture des logs en direct |

## Prérequis

- PHP **8.3** ou supérieur, avec les extensions habituelles de Laravel (dont `pdo_pgsql`)
- [Composer](https://getcomposer.org/) 2.x
- [Node.js](https://nodejs.org/) 22.x et npm
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

### 4. Installer et compiler les assets

```bash
npm install
npm run build
```

Le fichier `.npmrc` du backend force `ignore-scripts=true` : les scripts
d'installation des paquets npm ne sont pas exécutés, par précaution.

## Lancer l'environnement de développement

Depuis `backend/`, une seule commande démarre le serveur HTTP, le worker de file
d'attente, le suivi des logs et Vite en parallèle :

```bash
composer run dev
```

L'application est alors disponible sur <http://localhost:8000>.

Pour lancer les services séparément :

```bash
php artisan serve                          # serveur HTTP
php artisan queue:listen --tries=1         # traitement des jobs
php artisan pail                           # logs en direct
npm run dev                                # Vite en mode watch
```

## Tests

```bash
cd backend
composer run test          # équivaut à : php artisan config:clear && php artisan test
```

Les tests s'exécutent sur une base **SQLite en mémoire** (voir
[`backend/phpunit.xml`](backend/phpunit.xml)), indépendamment du PostgreSQL de
développement : aucun conteneur n'est requis pour les lancer.

## Qualité de code

```bash
cd backend
./vendor/bin/pint          # formate le code PHP
./vendor/bin/pint --test   # vérifie sans modifier
```

## Structure du dépôt

```
.
├── backend/            Application Laravel (API + rendu Blade)
│   ├── app/            Modèles, contrôleurs, providers
│   ├── config/         Configuration du framework
│   ├── database/       Migrations, factories, seeders
│   ├── resources/      Vues Blade, CSS, JS
│   ├── routes/         Déclaration des routes
│   ├── storage/        Logs, cache, fichiers générés (non versionnés)
│   └── tests/          Tests unitaires et fonctionnels
├── frontend/           Application front (stack à définir)
├── docs/               Documentation projet
└── compose.yaml        Services de développement (PostgreSQL)
```

## Base de données

- **Développement** : PostgreSQL 17.5 dans Docker, données persistées dans le
  volume nommé `db_data`. Pour repartir de zéro :
  `docker compose down -v && docker compose up -d`, puis `php artisan migrate`.
- **Tests** : SQLite en mémoire, recréée à chaque exécution.

## Points ouverts

- [ ] Rédiger la description fonctionnelle et les cas d'usage
- [ ] Définir la stack et initialiser `frontend/`
- [ ] Aligner `backend/.env.example` sur PostgreSQL
- [ ] Concevoir le modèle de données métier et les migrations associées
- [ ] Définir le contrat d'API et l'authentification
- [ ] Mettre en place une intégration continue (tests + Pint)
- [ ] Choisir et déclarer une licence
