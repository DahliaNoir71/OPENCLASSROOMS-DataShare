# Performance

Ce document sera complété au lot qualité ; il consigne pour l'instant les
arbitrages de performance relevés au fil des lots fonctionnels. Le
raisonnement déjà écrit sur les axes structurants du projet — streaming des
téléchargements, absence de cache de réponses, couperets de temps du dépôt et
du téléchargement — reste dans
[docs/architecture.md](docs/architecture.md#cache) et n'est pas recopié ici.

## Budgets et arbitrages

- **Pagination de l'historique** (US05) — `GET /api/files` ne renvoie jamais
  l'intégralité des fichiers d'un compte : 25 éléments par page par défaut,
  100 au maximum (`DATASHARE_HISTORY_PER_PAGE`, `DATASHARE_HISTORY_MAX_PER_PAGE`
  dans `config/datashare.php`), bornes lues en configuration plutôt qu'en
  littéral. C'est le seul filet contre la croissance sans fin d'une réponse
  tant que la purge (US10) n'existe pas : sans elle, aucune ligne ne disparaît
  jamais d'elle-même, et un compte ancien accumule tous ses dépôts.
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

## Ce qui reste à mesurer

- Le coût réel du `COUNT(*)` sur un compte à plusieurs milliers de fichiers
  n'a pas été mesuré : le prototype ne dispose pas encore d'un jeu de données
  de cette taille. À réévaluer si l'usage le justifie — `simplePaginate()`
  reste le repli si `meta.total` s'avère trop coûteux à maintenir à ce volume.
