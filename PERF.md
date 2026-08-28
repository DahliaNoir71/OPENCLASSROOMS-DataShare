# Performance

Ce document consigne les arbitrages de performance relevés au fil des lots
fonctionnels, les métriques structurées ajoutées au chemin de dépôt et de
téléchargement, et la campagne de charge menée sur l'endpoint de
téléchargement anonyme (P12b). Le raisonnement déjà écrit sur les axes
structurants du projet — streaming des téléchargements, absence de cache de
réponses, couperets de temps du dépôt et du téléchargement — reste dans
[docs/architecture.md](docs/architecture.md#cache) et n'est pas recopié ici.

## Budgets et arbitrages

- **Pagination de l'historique** (US05) — `GET /api/files` ne renvoie jamais
  l'intégralité des fichiers d'un compte : 25 éléments par page par défaut,
  100 au maximum (`DATASHARE_HISTORY_PER_PAGE`, `DATASHARE_HISTORY_MAX_PER_PAGE`
  dans `config/datashare.php`), bornes lues en configuration plutôt qu'en
  littéral. La purge quotidienne (US10) borne désormais la table par le
  haut — une ligne expirée disparaît au passage suivant —, mais elle ne
  remplace pas la pagination et ne l'a jamais rendue superflue : elle ne
  retire rien avant l'échéance, et un compte actif peut accumuler sept jours
  de dépôts avant qu'une seule ligne ne devienne éligible. Les deux
  mécanismes bornent des choses différentes : la purge borne ce que la table
  conserve, la pagination borne ce qu'une réponse transporte.
- **Balayage quotidien de `files`** (US10) — `files:purge-expired` sélectionne
  `expires_at < now()` sur toute la table, par lots de 1 000 lignes
  (`DATASHARE_PURGE_CHUNK`), ordonnés par `id` plutôt que par `expires_at` :
  c'est ce qui rend la pagination insensible aux suppressions déjà faites
  (cf. [docs/architecture.md](docs/architecture.md)). L'index posé sur
  `expires_at` existe et sert la sélection, mais pas le tri, qui porte sur
  `id` : selon la sélectivité, PostgreSQL arbitre entre un parcours d'index
  sur `expires_at` suivi d'un tri et un parcours par clé primaire filtré —
  excellent quand la majorité des lignes est expirée (le cas d'un
  rattrapage), plus coûteux quand quelques lignes expirées se cachent parmi
  des millions de lignes actives. C'est la seule requête du projet qui ne se
  limite pas à un compte. Assumé : le balayage a lieu une fois par jour, hors
  de toute requête client.
- **`paginate()` plutôt que `simplePaginate()`** (US05) — deux requêtes SQL par
  appel, un `COUNT(*)` puis la page elle-même, contre une seule pour
  `simplePaginate()`. Choix assumé pour exposer `meta.total` et
  `meta.last_page`, que le contrat publie et qu'un affichage du type « Page 2
  sur 5 » a besoin de connaître à l'avance. Le `COUNT(*)` porte sur les lignes
  déjà filtrées par `user_id` (et par `status`), donc sur l'historique d'un
  seul compte, jamais sur la table entière.
- **Index `(user_id, created_at)`** (US01, `docs/mcd.md`) — posé dès la
  migration de `files`, il sert la requête de l'historique : filtrage par
  propriétaire puis tri par date de dépôt décroissante, sans balayage de la
  table. Le départage par `id` ajouté au tri (`ORDER BY created_at DESC, id
  DESC`), nécessaire à un ordre déterministe entre deux dépôts de la même
  seconde, ne demande pas d'index supplémentaire : il ne s'applique qu'au sein
  des groupes déjà réduits par l'index composite.

## Logs structurés

La piste d'audit ([docs/architecture.md](docs/architecture.md#la-piste-daudit))
est écrite en JSON depuis P12b : `App\Logging\UseJsonFormatter` est tapé sur
les canaux `single` et `daily` (`config/logging.php`), et remplace le
formateur texte de Monolog par `Monolog\Formatter\JsonFormatter` sans toucher
au canal, à sa rotation, ni au seuil `LOG_LEVEL` — la décision R1 (canal et
rotation) reste entière, seule la sérialisation change. Le grep de
[MAINTENANCE.md](MAINTENANCE.md#vérifier-quelle-a-tourné) (`grep 'Expired
files purged' storage/logs/laravel.log`) continue de fonctionner tel quel :
`JsonFormatter` restitue le `message` en clair dans le champ `message` du
JSON, une ligne grep par sous-chaîne littérale n'a donc rien à changer pour
survivre au passage au JSON.

Un exemple de ligne, intégrale, telle qu'écrite dans
`storage/logs/laravel.log` :

```json
{"message":"Link consumed","context":{"file_id":217,"duration_ms":2,"bytes":2097152,"route":"api/links/{token}/download"},"level":200,"level_name":"INFO","channel":"local","datetime":"2026-08-28T10:06:54.522385+00:00","extra":{}}
```

Deux métriques de charge ont été ajoutées au contexte des lignes existantes,
sans en créer de nouvelles ni changer leurs déclencheurs :

| Ligne | Déclencheur | Métriques ajoutées |
| --- | --- | --- |
| `File uploaded` | `201` sur `/files` | `duration_ms` (traitement du contrôleur), `route` |
| `Link consumed` | `200` sur le téléchargement | `duration_ms` (résolution seule, pas le transfert), `bytes`, `route` |

`route` porte le motif de route (`api/files`, `api/links/{token}/download`),
jamais le chemin résolu — sur un lien de téléchargement, le chemin résolu
contiendrait le token. `duration_ms` sur `Link consumed` ne couvre que la
résolution du lien et l'ouverture du flux : `StreamedResponse` rend la main
au serveur HTTP avant que les octets ne partent, le transfert lui-même n'est
donc pas mesuré par cette ligne (cf. §Analyse, serveur vs client).

```json
{"message":"File uploaded","context":{"user_id":1,"file_id":13,"size":11122,"protected":false,"duration_ms":23,"route":"api/files"}}
{"message":"Rate limit exceeded","context":{"limiter":"api","ip":"127.0.0.1","method":"POST","route":"api/links/{token}/download","user_id":null}}
```

`Rate limit exceeded` (niveau `warning`, `AppServiceProvider::rejectAndLog`)
n'a pas reçu de métrique de charge : son rôle est d'identifier le limiteur qui
a tranché (`api`, `uploads` ou `downloads`), pas de mesurer un temps de
traitement qu'un 429 n'a pas eu à produire.

La liste d'exclusion de la piste d'audit
([docs/architecture.md](docs/architecture.md#la-piste-daudit)) n'a pas bougé :
le contexte ne contient que des identifiants numériques et des valeurs non
parlantes — ni email, ni nom de fichier d'origine, ni token, ni mot de passe.
`duration_ms`, `bytes` et `route` (motif, pas chemin résolu) s'y conforment.

## Protocole de campagne

### Endpoint ciblé et pourquoi

`POST /api/links/{token}/download` — c'est la seule route anonyme du projet
et celle qui *streame* (`FileStorageService::stream()`), donc la seule où
temps de réponse et débit réseau se mesurent simultanément sans qu'une
session authentifiée ou un cache applicatif ne brouille le signal. L'upload
n'a pas été chargé (cf. §Ce qui reste à mesurer) : k6 fait des requêtes
HTTP, générer 2 Mo de corps par requête pour des centaines d'itérations
alourdit le protocole de test bien plus que le protocole applicatif.

### Seeding

```bash
cd backend
php artisan files:seed-perf 100 --size=2
```

Crée 100 fichiers de 2 Mo, non protégés, rattachés à un compte de campagne
dédié, et exporte leurs tokens dans `backend/perf/tokens.json` (jamais
versionné). Les contenus sont distincts (`random_bytes` par fichier), pour
deux raisons qui se recoupent sans se confondre : le limiteur
`downloads_per_token` compte par token, donc rejouer le même lien en boucle
plafonnerait le débit mesuré sur une seule clé au lieu d'exercer les N ; et
un unique fichier relu en boucle resterait en cache de pages du noyau après
la première lecture, ce qui mesurerait la mémoire plutôt que le disque. Le
scénario 2 (montée) répartit justement la charge sur les 100 liens en
round-robin pour éviter les deux biais à la fois.

### Les trois scénarios (`backend/perf/k6/download.js`)

Un seul script, le scénario choisi par `K6_SCENARIO` pour ne pas dupliquer
la logique de requête :

1. **Calibrage** — 1 VU, 30 s, plafonds relevés. Latence et débit de
   référence sans aucune contention, ni limiteur ni file d'attente.
2. **Montée** — paliers 5 → 20 → 50 VU sur 5 min, plafonds relevés, sur le
   pool des 100 liens distincts. Cherche le point de saturation du serveur
   de développement, pas celui d'un limiteur.
3. **Dépassement délibéré** — 10 VU, 30 s, plafonds nominaux (aucune
   variable `DATASHARE_THROTTLE_*` positionnée). Vérifie que le contrat
   429/`Retry-After` tient sous charge, sans présumer lequel des trois
   limiteurs (`api`, `downloads_per_ip`, `downloads_per_token`) répond en
   premier — `bootstrap/app.php` évalue le limiteur `api` avant même que la
   route de téléchargement ne soit atteinte.

Les plafonds sont pilotés par les variables d'environnement
`DATASHARE_THROTTLE_API`, `DATASHARE_THROTTLE_DOWNLOADS_PER_TOKEN` et
`DATASHARE_THROTTLE_DOWNLOADS_PER_IP`, lues une fois au démarrage du
serveur : jamais un interrupteur à bascule en cours de campagne, mais un
choix qui se prend en relançant `artisan serve` avec l'environnement voulu
avant chaque scénario.

### Mode d'emploi exact

Le répertoire de lancement change selon la commande — l'ambiguïté a coûté
des runs ratés pendant la campagne :

```bash
# Depuis backend/ — serveur de dev, un par scénario (relire les plafonds
# suppose de relancer le serveur, ils ne se recalculent pas à chaud).
# --no-reload : sans lui, PHP_CLI_SERVER_WORKERS (plusieurs workers PHP
# intégrés) ne tient pas la charge — le serveur recharge le code à chaque
# requête au lieu de faire tourner les workers en parallèle.
cd backend

# Calibrage et montée : plafonds relevés pour observer le serveur, pas le limiteur.
DATASHARE_THROTTLE_API=100000 \
DATASHARE_THROTTLE_DOWNLOADS_PER_TOKEN=100000 \
DATASHARE_THROTTLE_DOWNLOADS_PER_IP=100000 \
PHP_CLI_SERVER_WORKERS=4 \
php artisan serve --no-reload

# Dépassement : serveur relancé sans variable DATASHARE_THROTTLE_*, plafonds nominaux.
PHP_CLI_SERVER_WORKERS=4 php artisan serve --no-reload
```

```bash
# Depuis la racine du monorepo — k6 tourne en conteneur, les volumes sont
# relatifs à ce répertoire.
docker run --rm -i --network host \
  --user "$(id -u):$(id -g)" \
  -v "$(pwd)/backend/perf/k6:/scripts" \
  -v "$(pwd)/backend/perf/tokens.json:/scripts/../tokens.json:ro" \
  -v "$(pwd)/backend/perf/results:/results" \
  -e BASE_URL=http://localhost:8000 \
  -e K6_SCENARIO=calibrage \
  grafana/k6 run /scripts/download.js --summary-export=/results/calibrage.json
```

`K6_SCENARIO` et le nom du fichier `--summary-export` changent pour `montee`
et `depassement`, sans autre différence. Trois corrections apprises pendant
la campagne, toutes silencieuses tant qu'on ne les applique pas :

- **Le montage de `tokens.json` dans le conteneur** — `download.js` le lit
  par un chemin relatif (`../tokens.json`) depuis `/scripts`, donc le
  fichier doit être monté explicitement à cet emplacement ; un conteneur qui
  ne voit que `k6/` échoue à l'ouverture du fichier avant la première
  requête.
- **`--no-reload` sur `artisan serve`** — requis dès que
  `PHP_CLI_SERVER_WORKERS` est positionné à plus de 1 : sans lui, le serveur
  de développement recharge le code applicatif à chaque requête au lieu de
  répartir sur les workers, et la charge simulée ne teste plus rien.
- **`--user "$(id -u):$(id -g)"` sur `docker run`** — sans lui, le
  conteneur `grafana/k6` écrit `--summary-export` en tant que `root` dans un
  volume monté, et le fichier produit n'est ensuite plus modifiable ni
  supprimable par l'utilisateur du poste hôte.

## Résultats

### Calibrage — 1 VU, 30 s, plafonds relevés

| Métrique | Valeur |
| --- | --- |
| Requêtes | 877 |
| Débit | 29,2 req/s |
| Débit réseau | ≈ 61 Mo/s |
| Durée médiane | 32,6 ms |
| p95 | 39,7 ms |
| Max | 252,7 ms (premier hit, caches froids) |
| Échecs | 0 |

### Montée — 5 → 20 → 50 VU, 5 min, plafonds relevés

| Métrique | Valeur |
| --- | --- |
| Requêtes | 32 171 |
| Statut 200 | 100 % |
| Débit saturé | ≈ 107 req/s |
| Débit réseau | ≈ 225 Mo/s |
| Durée médiane | 170 ms |
| p95 | 461 ms |

### Dépassement délibéré — 10 VU, 30 s, plafonds nominaux

| Métrique | Valeur |
| --- | --- |
| Requêtes | 7 680 |
| Succès (200) | 34 |
| Rejets (429) | 7 646 |
| Débit | 255,6 req/s |
| Checks réussis | 7 680 / 7 680 |

Les deux checks (`status is 200 or 429`, `429 responses carry Retry-After`)
passent sur l'intégralité des 7 680 requêtes : chaque 429 porte bien un
`Retry-After`, et aucune réponse ne sort de l'ensemble {200, 429}.

## Analyse

**Saturation.** Le débit ne croît pas linéairement avec le nombre de VU : le
palier à 50 VU plafonne à ≈ 107 req/s au lieu de suivre la progression des
paliers précédents. La loi de Little donne une explication cohérente avec la
latence observée : 50 VU ÷ 107 req/s ≈ 467 ms, à comparer aux 461 ms de p95
mesurés au même palier. Les deux chiffres coïncident : au palier 50, les
requêtes attendent en file avant d'être traitées, et le p95 mesuré est
d'abord celui de cette file, pas celui du traitement métier.

**Serveur vs client.** `duration_ms` journalisé par `Link consumed` reste à
2-3 ms sous charge (cf. l'échantillon ci-dessus), pendant que k6 mesure une
durée médiane de 170 ms au même palier. L'écart n'est pas une anomalie :
`duration_ms` couvre la résolution du lien côté serveur, `StreamedResponse`
rendant la main avant l'envoi des octets, alors que le chiffre k6 couvre
l'aller-retour complet — transfert du corps compris, plus l'attente en file
du palier. Les deux chiffres ne sont pas comparables, et ne doivent pas être
lus comme deux mesures d'une même chose. La même réserve vaut côté upload :
`duration_ms` sur `File uploaded` couvre le traitement du contrôleur, pas la
réception du corps de la requête par le serveur HTTP.

**Limitation.** Sous le scénario de dépassement, les lignes `Rate limit
exceeded` journalisées portent `"limiter":"api"` — c'est le limiteur général
par IP (60 requêtes/minute nominales) qui répond, avant même que la route de
téléchargement ne soit atteinte, exactement comme prévu par l'ordre
d'évaluation de `bootstrap/app.php`. Les limiteurs `downloads_per_token` et
`downloads_per_ip` restent en défense ciblée, jamais mesurés comme
déclencheurs sur ce protocole. Le contrat HTTP (429 + `Retry-After`) tient
malgré tout à 255,6 req/s, quel que soit le limiteur qui répond.

## Réserves de validité

Ces chiffres viennent de `php artisan serve` (serveur de développement PHP,
multi-workers via `PHP_CLI_SERVER_WORKERS`), pas de php-fpm derrière un vrai
serveur HTTP — le comportement sous charge d'un serveur de développement et
celui d'une pile de production ne sont pas gouvernés par le même code. Les
chiffres sont donc **relatifs** : comparables entre eux (calibrage vs montée
vs dépassement, sur la même machine), pas des valeurs absolues transposables
à un déploiement réel. La mesure est en outre locale — client k6 et serveur
sur la même machine, `--network host` — sans latence ni topologie réseau
réelles.

## Ce qui reste à mesurer

- Le coût réel du `COUNT(*)` sur un compte à plusieurs milliers de fichiers
  n'a pas été mesuré : le prototype ne dispose pas encore d'un jeu de données
  de cette taille. À réévaluer si l'usage le justifie — `simplePaginate()`
  reste le repli si `meta.total` s'avère trop coûteux à maintenir à ce volume.
- L'upload n'a pas été chargé par la campagne k6 (cf. §Protocole de
  campagne) : le comportement de `POST /api/files` sous plusieurs VU
  simultanés, avec des corps de plusieurs mégaoctets, reste à mesurer.
- La campagne a tourné sur `php artisan serve`, jamais sur une pile
  représentative d'un environnement de production (php-fpm, reverse proxy,
  réseau réel) — cf. §Réserves de validité.
