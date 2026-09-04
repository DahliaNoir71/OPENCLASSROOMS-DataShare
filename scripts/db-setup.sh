#!/usr/bin/env bash
#
# Objet : démarre le conteneur PostgreSQL de développement (compose.yaml,
#         service `db`) et applique les migrations Laravel du backend.
# Prérequis : Docker installé, avec le plugin Docker Compose et le démon
#             actif. `backend/vendor` est optionnel : s'il est absent
#             (composer install pas encore joué), les migrations sont
#             sautées ici et seront appliquées par `composer run setup`
#             (cf. scripts/install.sh).
# Répertoire d'exécution attendu : racine du monorepo (celui qui contient
#             compose.yaml) — ex. ./scripts/db-setup.sh
# Idempotence : `docker compose up -d --wait` ne fait rien si le conteneur
#             tourne déjà et est sain ; `php artisan migrate --force`
#             ignore les migrations déjà appliquées. Le script peut être
#             relancé sans effet de bord.
set -euo pipefail

if [[ ! -f compose.yaml ]]; then
  echo "Erreur : compose.yaml introuvable dans le répertoire courant ($(pwd))." >&2
  echo "Lancer ce script depuis la racine du monorepo : ./scripts/db-setup.sh" >&2
  exit 1
fi

if ! command -v docker >/dev/null 2>&1; then
  echo "Erreur : docker est introuvable. Installer Docker : https://docs.docker.com/" >&2
  exit 1
fi

if ! docker info >/dev/null 2>&1; then
  echo "Erreur : le démon Docker n'est pas joignable." >&2
  echo "Sous WSL, le démarrer avec : sudo service docker start" >&2
  exit 1
fi

echo "[db-setup] Démarrage du conteneur PostgreSQL depuis $(pwd)…"
docker compose up -d --wait

if [[ -d backend/vendor ]]; then
  if ! command -v php >/dev/null 2>&1; then
    echo "Erreur : php est introuvable, impossible d'appliquer les migrations." >&2
    echo "Installer PHP 8.3+ : https://www.php.net/" >&2
    exit 1
  fi
  echo "[db-setup] Application des migrations depuis $(pwd)/backend…"
  (cd backend && php artisan migrate --force)
else
  echo "[db-setup] backend/vendor absent (composer install pas encore joué) :"
  echo "[db-setup] migrations sautées ici, elles seront appliquées par 'composer run setup' (scripts/install.sh)."
fi

echo "[db-setup] Terminé."
