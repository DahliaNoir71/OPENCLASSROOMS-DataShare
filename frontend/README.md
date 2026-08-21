# DataShare — frontend

SPA Vue 3 + TypeScript de DataShare. C'est la seule interface utilisateur du
projet : l'API Laravel de [`../backend/`](../backend/) ne sert aucune vue.

La mise en route, les commandes de développement, de test et de qualité sont
décrites une seule fois, dans le [README de la racine](../README.md). Ce fichier
ne répète rien.

## Repères

| Sujet | Où |
| --- | --- |
| Routes côté client | [`src/router/index.ts`](src/router/index.ts) |
| Jetons de design | [`../docs/design-tokens.md`](../docs/design-tokens.md) et [`src/assets/styles/tokens.css`](src/assets/styles/tokens.css) |
| Contrat d'API consommé | [`../docs/openapi.yaml`](../docs/openapi.yaml) |
| Architecture d'ensemble | [`../docs/architecture.md`](../docs/architecture.md) |

## Particularités

- **L'API n'est jamais appelée sur le port 8000 en dur.** Le proxy déclaré dans
  [`vite.config.ts`](vite.config.ts) relaie `/api` vers
  `http://localhost:8000` : le code ne manipule que des chemins relatifs, ce qui
  évite le CORS en développement. En contrepartie, `php artisan serve` doit
  tourner, sinon le proxy renvoie une erreur de connexion.
- **`npm install` télécharge le binaire Cypress** (une centaine de mégaoctets)
  via le script `prepare`. `npm install --ignore-scripts` s'en passe, au prix
  des tests end-to-end jusqu'à un `npx cypress install` explicite.
- **`npm run test:unit` reste en watch** : pour un code de sortie exploitable,
  utiliser `npx vitest run`.
- **Les polices sont auto-hébergées** dans `src/assets/fonts/` : aucune requête
  vers un tiers, cf. [`../docs/design-tokens.md`](../docs/design-tokens.md).

## Configuration de l'éditeur

[VS Code](https://code.visualstudio.com/) avec l'extension
[Vue (Official)](https://marketplace.visualstudio.com/items?itemName=Vue.volar),
Vetur désactivé. TypeScript ne sait pas typer les imports `.vue` seul : c'est
`vue-tsc` qui remplace `tsc`, via `npm run type-check`.

## Licence

[MIT](../LICENSE).
