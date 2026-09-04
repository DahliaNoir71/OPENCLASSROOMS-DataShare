# TESTING

Plan de tests et rapports de couverture de DataShare. Ce document fait
autorité sur la stratégie de tests, la matrice de couverture par user
story et les critères d'acceptation qualité ; les commandes de lancement
détaillées restent dans le [README §Tests](README.md#tests), cité ici
plutôt que dupliquée.

## 1. Stratégie

La pyramide de tests suit la réalité du projet, pas un objectif abstrait :

- **Tests unitaires** ciblés, à la marge — un seul cas ne dépendant
  d'aucune infrastructure (`Unit/FileScopesTest.php`, scopes du modèle
  `File`).
- **Le gros de l'effort** est porté par les tests **Feature/API** côté
  backend (contrôleurs, règles métier, audit, sécurité HTTP) et les tests
  de **composants/vues/stores** côté frontend (Vitest + Vue Test Utils) :
  c'est là que vit la logique métier des deux couches.
- **Un test end-to-end** de parcours critique (Cypress), qui rejoue le
  chemin utilisateur complet plutôt que de dupliquer les cas déjà couverts
  unitairement.

Deux bases de données sont utilisées pour deux usages découplés :

- **SQLite en mémoire** (`backend/phpunit.xml`) pour la boucle de
  développement courte — rapide, aucun conteneur requis.
- **PostgreSQL**, le moteur de production, comme **critère de sortie**
  avant une étape importante — pas pour l'exécution courante. La
  procédure de rejeu est décrite au
  [README §Tests](README.md#tests) et n'est pas dupliquée ici.

Le découplage n'est pas cosmétique : le correctif
`fix/US06-non-numeric-id` a corrigé un écart de comportement réel entre
les deux moteurs (une `QueryException` 500 sur un identifiant non
numérique en PostgreSQL, là où SQLite ne trouvait simplement aucune
ligne). SQLite reste donc un raccourci de développement, jamais une
preuve de correction sur le moteur de production.

## 2. Matrice US × niveau de test

| US | Backend (Feature/Unit) | Frontend (composants/vues/stores) | E2E |
| --- | --- | --- | --- |
| US01 — Dépôt d'un fichier | `Files/UploadTest.php` (20), `Files/UploadAuditTest.php` (4) | `components/UploadCard.spec.ts` (21), `stores/files.spec.ts` (25, partagé avec US05/US06) | Couvert par le parcours (téléversement) |
| US02 — Téléchargement par lien public | `Links/DownloadTest.php` (19), `Links/LinkMetadataTest.php` (11), `Links/DownloadAuditTest.php` (6) | `views/DownloadView.spec.ts` (17), `stores/links.spec.ts` (20), `utils/saveBlob.spec.ts` (2) | Couvert par le parcours (lien public, téléchargement vérifié par interception réseau) |
| US03 — Inscription | `Auth/RegisterTest.php` (12) | `views/RegisterView.spec.ts` (9), `stores/auth.spec.ts` (21, partagé avec US04) | Couvert par le parcours (inscription) |
| US04 — Connexion / déconnexion | `Auth/LoginTest.php` (11), `Auth/SessionTest.php` (6, `/me` + logout) | `views/LoginView.spec.ts` (13), `stores/auth.spec.ts` (21, cf. US03) | Couvert par le parcours (déconnexion) |
| US05 — Historique des fichiers | `Files/ListFilesTest.php` (28), `Unit/FileScopesTest.php` (2) | `views/MyFilesView.spec.ts` (33), `stores/files.spec.ts` (cf. US01) | Couvert par le parcours (historique) |
| US06 — Suppression manuelle | `Files/DeleteFileTest.php` (17), `Files/DeleteAuditTest.php` (5) | `views/MyFilesView.spec.ts` (cf. US05, dialogue de suppression) | Couvert par le parcours (suppression) |
| US10 — Purge planifiée *(hors énoncé de l'étape)* | `Files/PurgeTest.php` (11), `Files/PurgeAuditTest.php` (13), `Console/PurgeScheduleTest.php` (4) | Sans objet — aucune interface cliente pour une tâche planifiée serveur | Sans objet — pas de client à exercer |
| Transversal (sécurité HTTP, audit, journalisation, infra) | `Api/AuditTrailTest.php` (6), `Api/RateLimitLoggingTest.php` (4), `Api/NoStoreHeaderTest.php` (4), `Logging/JsonFormatterTest.php` (1), `DatabaseSmokeTest.php` (2) | `components/AppHeader.spec.ts` (5), `AppFooter.spec.ts` (1), `AppCallout.spec.ts` (8), `views/HomeView.spec.ts` (5), `views/NotFoundView.spec.ts` (2), `router/index.spec.ts` (2), `utils/formatMimeType.spec.ts` (5) | — |

Total : 190 tests backend, 189 tests frontend (16 fichiers), 1 test e2e.

## 3. Critères d'acceptation

- Les trois suites sont vertes : 190 tests / 1 006 assertions côté backend,
  189 tests côté frontend, 1 test e2e.
- Les seuils de couverture bloquants sont outillés et respectés :
  70 % côté backend (`composer run test:coverage`, `--min=70`) et 70 % ×
  4 (lignes/fonctions/branches/statements) côté frontend
  (`npm run test:coverage`, seuils dans `vitest.config.ts`) — l'un comme
  l'autre largement dépassés (86,2 % et 96,1 % de lignes, cf. §4).
- La suite backend rejoue verte sur PostgreSQL, le moteur de production
  (rejeu du 2026-09-04 : 190 tests, 1 006 assertions, 6,11 s, base
  `datashare_test` — aucun écart de moteur constaté).
- Le test e2e Cypress est vert sur le parcours complet.

## 4. Rapports de couverture

| | Lignes | Branches | Fonctions | Instructions |
| --- | --- | --- | --- | --- |
| Backend (pcov) | 86,2 % (TOTAL) | — | — | — |
| Frontend (v8) | 96,13 % | 88,56 % | 92,66 % | 96,19 % |

Captures des rapports HTML :

- [docs/captures/datashare-backend-coverage.jpeg](docs/captures/datashare-backend-coverage.jpeg)
- [docs/captures/datashare-frontend-coverage.jpeg](docs/captures/datashare-frontend-coverage.jpeg)

Zones sous 100 %, toutes identifiées et assumées :

- **Backend** — `Rules/BlockedFileExtension` (83,3 %) et
  `Services/FileStorageService` (81,8 %) : branches d'erreur résiduelles,
  chemins d'exception peu accessibles en test sans dégrader la lisibilité
  des cas nominaux. `Console/Commands/SeedPerfFiles` (0 %) : outillage de
  campagne de performance ([PERF.md](PERF.md)), hors code de production,
  jamais exécuté par un utilisateur ni par un cron — délibérément non
  testé.
- **Frontend** — `App.vue` (0 %) : ne contient qu'un montage de
  `RouterView` et un lien d'évitement, sans logique propre à tester ; le
  parcours e2e l'exerce déjà indirectement. `router/index.ts` (50 %) : ne
  fait que déclarer les routes, sans garde ni redirection — cette
  logique vit dans les vues elles-mêmes, où elle est testée (choix
  d'architecture documenté, pas un manque de test).
- `src/main.ts` est exclu du calcul de couverture frontend (bootstrap de
  l'application, sans logique testable), configuré dans
  `vitest.config.ts`.

## 5. Instructions d'exécution

Toutes les commandes détaillées (options de filtrage, prérequis) sont au
[README §Tests](README.md#tests) ; voici l'essentiel par usage.

| Usage | Répertoire | Commande |
| --- | --- | --- |
| Suite rapide backend | `backend/` | `composer run test` |
| Suite rapide frontend | `frontend/` | `npm run test:unit -- --run` |
| Couverture backend (seuil 70 %) | `backend/` | `composer run test:coverage` |
| Couverture frontend (seuils 70 % × 4) | `frontend/` | `npm run test:coverage` |
| Rejeu PostgreSQL | `backend/` | Procédure complète au [README §Tests](README.md#tests) (`createdb` puis variables d'environnement `DB_*`) |
| End-to-end | `frontend/` | 3 processus : backend (`php artisan serve`), build+preview frontend (`npm run test:e2e`, sert sur le port 4173), avec `DATASHARE_FRONTEND_URL=http://localhost:4173` |

## 6. Limites et pistes

- **E2E mono-navigateur** : le test Cypress ne s'exécute que sur le
  navigateur du runner par défaut ; aucune matrice multi-navigateurs
  n'est en place.
- **E2E mono-parcours** : un seul scénario nominal est couvert ; les
  scénarios d'erreur (mot de passe incorrect, lien expiré, quota dépassé)
  sont couverts au niveau composant/store, pas en e2e.
- **US10 sans test client** : la purge planifiée n'a ni interface ni
  parcours utilisateur — seule sa logique serveur est testée
  (cf. §2). C'est un choix motivé par l'absence de client, pas un angle
  mort.
- **Charge applicative** : la tenue en charge (gros fichiers, montée en
  parallèle) est hors périmètre de cette campagne de tests fonctionnels
  et renvoyée à [PERF.md](PERF.md).
