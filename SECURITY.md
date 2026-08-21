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
- **Documents à macros autorisés au dépôt** (US01) — la liste noire
  d'extensions (`config/datashare.php`) ne bloque ni `.docm`, ni `.xlsm`, ni
  `.pptm`. Ce sont des fichiers bureautiques ordinaires qu'un utilisateur
  légitime partage, et la menace qu'ils portent — une macro malveillante —
  suppose que le destinataire l'active lui-même à l'ouverture : elle ne
  s'exécute pas du seul fait du dépôt ou du téléchargement, contrairement à un
  exécutable. Ce vecteur est jugé hors du modèle de menace du MVP, où DataShare
  ne fait que transporter des octets sans les interpréter. Réévaluable si le
  service s'adresse à un public où ce risque devient significatif (ex. :
  usage interne à une organisation avec macros activées par défaut).
