#!/usr/bin/env bash
#
# Objet : installation complète du projet DataShare — vérifie les
#         prérequis, démarre et configure la base de données
#         (scripts/db-setup.sh), installe le backend (composer run setup,
#         depuis backend/) puis le frontend (npm install, depuis
#         frontend/).
# Prérequis : PHP 8.3+, Composer 2.x, Node.js (^22.18.0 || >=24.12.0) et
#             npm, Docker avec le plugin Docker Compose.
# Répertoire d'exécution attendu : racine du monorepo (celui qui contient
#             compose.yaml, backend/ et frontend/) — ex. ./scripts/install.sh
# Idempotence : chaque étape déléguée est elle-même idempotente —
#             scripts/db-setup.sh (voir son en-tête), `composer run setup`
#             (backend/composer.json : ne recopie pas un .env existant, ne
#             régénère pas un JWT_SECRET déjà en place, migrations déjà
#             appliquées ignorées) et `npm install` (idempotent par
#             construction). Le script peut être relancé sans effet de
#             bord sur une installation existante.
#
# Remarque : `npm install` exécute ici le script `prepare` (cypress
#             install), qui télécharge le binaire Cypress (~100 Mo). Pour
#             l'éviter, lancer manuellement, depuis frontend/ :
#             npm install --ignore-scripts
#             — les tests end-to-end resteront alors indisponibles jusqu'à
#             un `npx cypress install` explicite.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if [[ ! -f compose.yaml || ! -d backend || ! -d frontend ]]; then
  echo "Erreur : ce script doit être lancé depuis la racine du monorepo." >&2
  echo "Le répertoire courant ($(pwd)) ne contient pas compose.yaml, backend/ et frontend/." >&2
  exit 1
fi

missing=()
command -v php >/dev/null 2>&1 || missing+=("php (>= 8.3) — https://www.php.net/")
command -v composer >/dev/null 2>&1 || missing+=("composer (2.x) — https://getcomposer.org/")
command -v node >/dev/null 2>&1 || missing+=("node (^22.18.0 || >=24.12.0) — https://nodejs.org/")
command -v npm >/dev/null 2>&1 || missing+=("npm — fourni avec Node.js")
command -v docker >/dev/null 2>&1 || missing+=("docker (avec le plugin Docker Compose) — https://docs.docker.com/")

if [[ ${#missing[@]} -gt 0 ]]; then
  echo "Erreur : prérequis manquants :" >&2
  for m in "${missing[@]}"; do
    echo "  - $m" >&2
  done
  exit 1
fi

echo "[install] Configuration de la base de données…"
"$SCRIPT_DIR/db-setup.sh"

echo "[install] Installation du backend depuis $(pwd)/backend…"
(cd backend && composer run setup)

echo "[install] Installation du frontend depuis $(pwd)/frontend…"
(cd frontend && npm install)

echo "[install] Terminé."
