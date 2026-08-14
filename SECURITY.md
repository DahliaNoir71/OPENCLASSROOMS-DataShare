# Sécurité

Ce document sera complété au lot qualité ; il ne consigne pour l'instant que
les limites assumées relevées au fil des lots fonctionnels.

## Limites assumées

- **Limitation de débit keyée par IP seule** (US04) — le limiteur `auth`
  plafonne `/auth/register` et `/auth/login` à 5 requêtes par minute et par
  adresse IP. Il n'existe aucun verrouillage par compte : un attaquant
  disposant de plusieurs adresses sources peut donc cibler un compte donné
  sans jamais franchir le seuil. Contrôle jugé suffisant pour le MVP.
- **TTL du jeton JWT à 60 minutes** (US04) — valeur par défaut de jwt-auth,
  non surchargée. En cas de vol d'un jeton, la fenêtre d'exposition est d'une
  heure. Elle est compensée par la révocation côté serveur : `POST
  /auth/logout` inscrit le jeton sur la liste noire, qui le refuse dès lors
  sur toute requête ultérieure.
