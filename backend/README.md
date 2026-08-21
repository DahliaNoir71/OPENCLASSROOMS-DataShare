# DataShare — backend

API REST Laravel de DataShare. Elle n'expose aucune interface : l'application
est servie par la SPA de [`../frontend/`](../frontend/), qui consomme `/api`.

La mise en route, les commandes de développement, de test et de qualité sont
décrites une seule fois, dans le [README de la racine](../README.md). Ce fichier
ne répète rien.

## Repères

| Sujet | Où |
| --- | --- |
| Routes exposées | [`routes/api.php`](routes/api.php) |
| Contrat d'API | [`../docs/openapi.yaml`](../docs/openapi.yaml) |
| Architecture, cache, journalisation | [`../docs/architecture.md`](../docs/architecture.md) |
| Modèle de données | [`../docs/mcd.md`](../docs/mcd.md) |
| Limites de sécurité assumées | [`../SECURITY.md`](../SECURITY.md) |

## Particularités

- **Aucune chaîne de build front.** Le squelette Laravel livre par défaut un
  `package.json`, un `vite.config.js` et des assets dans `resources/` ; ils ont
  été supprimés, l'API n'ayant ni vue Blade ni feuille de style à compiler.
  Aucun `npm install` n'est donc à lancer ici.
- **Deux secrets à générer**, `APP_KEY` et `JWT_SECRET` (`php artisan
  key:generate` et `php artisan jwt:secret`). Sans le second, toute route
  d'authentification échoue à la signature.
- **Les tests tournent sur SQLite en mémoire** (voir
  [`phpunit.xml`](phpunit.xml)) : aucun conteneur n'est requis pour `php artisan
  test`. Le PostgreSQL de `compose.yaml` ne sert qu'au développement.

## Licence

[MIT](../LICENSE).
