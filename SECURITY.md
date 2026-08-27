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
- **Suppression non atomique** (US06) — `FileStorageService::delete()` efface
  le contenu physique puis la ligne en base, sans transaction englobante : une
  transaction SQL n'annule pas une suppression de fichier déjà exécutée. Une
  fenêtre existe donc entre les deux étapes ; si la seconde échoue, la
  conséquence est observable — le fichier répond `410` et non `404`, et une
  ligne `error` (`Link content missing`) apparaît au prochain téléchargement.
  Réparation : rejouer le `DELETE`.
- **Purge interrompue, réparée d'elle-même** (US10) — la purge efface le
  contenu physique puis la ligne en base, dans cet ordre et sans transaction
  englobante, exactement comme la suppression manuelle ci-dessus : la même
  fenêtre existe donc entre les deux étapes, et le même symptôme l'expose —
  un `410` au lieu d'un `404`, une ligne `error` (`Link content missing`) au
  prochain téléchargement. La différence est dans la réparation. Là où US06
  laisse un état que seul un `DELETE` rejoué à la main résout, l'échéance,
  elle, ne s'efface pas : le passage suivant retrouve la ligne toujours
  expirée et retente, la suppression d'un fichier déjà absent étant sans
  effet. La réparation est donc automatique, et bornée au prochain passage.
  L'ordre inverse ne se réparerait jamais : une ligne effacée avant ses
  octets laisserait sur le disque un fichier que plus aucune requête ne
  référence et qu'aucun passage ne retrouverait.
- **Purge non surveillée** (US10) — aucun seuil ni alerte ne signale qu'un
  passage n'a pas eu lieu : la seule preuve qu'elle tourne est la ligne
  `Expired files purged` dans les journaux, et son absence ne provoque aucune
  réponse HTTP. Un scheduler arrêté est donc indétectable jusqu'à saturation
  du disque. Même limite, et même nature, que `Login failed` et `File
  deletion refused` : le journal enregistre, personne ne le lit
  automatiquement. La procédure de contrôle manuel est dans
  [MAINTENANCE.md](MAINTENANCE.md).
- **Octets orphelins d'un compte supprimé** (US10) — la suppression d'un
  compte efface ses fichiers par `ON DELETE CASCADE` sur `user_id` : les
  lignes disparaissent, mais leurs octets restent sur le disque, puisque le
  SGBD n'a aucune prise sur le système de fichiers. Une purge fondée sur un
  balayage de lignes ne peut pas les retrouver — sans ligne, aucun `expires_at`
  à comparer. Limite d'effacement préexistante à ce lot, consignée ici sans
  être traitée : elle demanderait un mécanisme distinct, hors périmètre du
  prototype.
- **Irréversibilité sans pierre tombale** (US06) — une fois la ligne
  supprimée, le lien de partage répond `404` et non `410` : rien ne distingue
  plus « jamais émis » de « supprimé ». La ligne d'audit `File deleted` est la
  seule trace qui subsiste de la suppression.
- **Sondage journalisé mais non détecté** (US06) — une tentative de
  suppression du fichier d'un autre compte écrit une ligne `File deletion
  refused`, mais aucun seuil ni alerte ne surveille sa fréquence : seule une
  lecture manuelle du journal la révèle. Même limite, et même nature, que
  `Login failed`.
