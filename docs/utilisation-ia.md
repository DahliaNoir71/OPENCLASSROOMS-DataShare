# Utilisation de l'IA dans le développement

## Posture adoptée

Le projet DataShare a été développé en binômage supervisé avec deux outils
d'IA générative : Claude (assistant conversationnel, utilisé pour l'analyse,
la planification et les arbitrages) et Claude Code (agent d'exécution en ligne
de commande, utilisé pour la reconnaissance de code et l'implémentation).
Cette posture correspond à la combinaison prévue par le modèle de
documentation : assignation de tâches comme à un développeur junior, encadrée
par une supervision systématique.

Chaque user story a suivi le même cycle en trois phases, sans exception :

1. **Reconnaissance** — un prompt en lecture seule (mode « plan-only »,
   aucune écriture, aucune commande Git) demande à l'IA de cartographier
   l'existant, de proposer un plan et surtout de **remonter les tensions et
   décisions ouvertes sans les trancher**. Le livrable est un rapport avec
   un workplan par tâches et une liste d'arbitrages.
2. **Arbitrage** — chaque décision ouverte est tranchée par moi, sur la base
   des options et recommandations remontées. Les arbitrages structurants
   sont consignés dans `docs/architecture.md` (section décisions d'API)
   et, quand ils portent une limite assumée, dans `SECURITY.md`.
3. **Application** — un second prompt, distinct du premier, autorise
   l'écriture en mode « ask-before-edit » : l'IA propose chaque modification
   et attend validation avant d'écrire. Un point d'arrêt obligatoire sépare
   la relecture de l'existant (Temps 1) de l'exécution (Temps 2), qui ne
   démarre que sur feu vert explicite. La suite de tests complète est
   rejouée après chaque groupe de tâches ; l'ouverture de la pull request
   reste un geste humain.

Deux règles complètent ce cadre : une branche par user story (jamais deux US
mélangées dans une branche ou une tâche), et une hiérarchie de sources actée
en cours de projet — **les spécifications priment sur la maquette**, tout
écart avec l'une ou l'autre étant consigné par écrit, jamais silencieux.

## Tâches confiées à l'IA

- **Reconnaissance de code** : cartographie des routes, modèles, conventions
  et mécanismes réutilisables avant chaque lot ; vérifications dans le code
  du framework (comportement de `Storage::delete()` sur fichier absent,
  sérialisation des paginateurs par les API Resources, non-préservation des
  paramètres de requête dans les liens de pagination).
- **Implémentation back-end** : contrôleurs, Form Requests, scopes Eloquent,
  services, exceptions à rendu dédié, tests PHPUnit (suites Feature et Unit).
- **Implémentation front-end** : vues, composants, stores Pinia, utilitaires,
  tests Vitest.
- **Exploitation de la maquette Figma via MCP** : Claude Code, connecté au
  serveur MCP Figma, a extrait directement de la maquette les jetons de
  design (couleurs, rayons, ombres, espacements), la structure des
  composants (Callout, boutons, en-têtes) et les états des écrans. Ces
  relevés ont alimenté `docs/design-tokens.md` et les feuilles de style,
  en remplaçant la retranscription manuelle des valeurs. Les écarts entre
  maquette et implémentation (libellés d'erreur, contraste d'un jeton,
  icônes) ont été détectés à cette occasion et consignés comme dérivations.
- **Rédaction technique** : mise à jour d'`openapi.yaml`, des documents
  d'architecture et des fichiers qualité (`SECURITY.md`, `PERF.md`) au fil
  des lots, sous relecture.
- **Rédaction des messages de commit** : les messages, au format
  conventional commits, ont été rédigés par Claude Code au moment des
  commits qu'il effectuait, puis relus avant push. Ce point est précisé
  par transparence : la traçabilité des contributions de l'IA ne repose
  donc pas sur un marquage des messages (type `feat(ai):`) mais sur la
  méthode elle-même — une pull request par branche d'US, un mode
  d'exécution où chaque écriture est validée, et des arbitrages consignés
  dans la documentation.

## Supervision et correctifs

La supervision s'est exercée à trois niveaux.

**Avant l'écriture** : les rapports de reconnaissance ont été relus et leurs
plans amendés avant tout feu vert. Exemples : le choix du code de succès et
l'identifiant de route de la suppression ont été fixés avant exécution ;
l'ordre d'exécution proposé pour chaque lot a été validé ou corrigé.

**Sur les décisions** : les tensions de spécification ont été tranchées par
arbitrage humain documenté. Exemples représentatifs : la valeur par défaut du
filtre de l'historique (`status=all`, écart assumé avec la lettre d'US06,
consigné dans `docs/architecture.md`) ; le choix d'un 404 uniforme pour la
suppression d'un fichier d'autrui (fermeture d'un oracle d'énumération
d'identifiants, consigné dans `SECURITY.md`) ; la journalisation du sondage
d'identifiants par un compte authentifié ; l'emplacement des fichiers
qualité (`PERF.md` à la racine, conformité aux livrables nommés par les
spécifications contre la convention interne proposée par l'IA). Lorsqu'un
lot a été livré avec des décisions tranchées unilatéralement par l'IA,
elles ont été requalifiées en propositions et validées une à une avant
exécution.

**Sur le code** : relecture de chaque modification proposée (mode
ask-before-edit), exécution des suites de tests en non-régression à chaque
lot (SQLite puis PostgreSQL, moteur de production), vérification de bout en
bout sur l'application lancée. Exemples de correctifs issus de cette
relecture : un héritage d'exception écarté (la famille `LinkException` est
spécifique aux liens publics, la nouvelle exception duplique le patron au
lieu d'en hériter) ; l'ajout d'une validation client (`maxlength`) alignée
sur la règle serveur, exigée par les spécifications mais absente du plan ;
la restructuration d'un groupe de routes pour éviter qu'un limiteur
d'upload ne s'applique aux nouvelles routes, verrouillée par un test
sentinelle existant.

## Apports et limites constatés

**Apports** : vitesse d'exécution sur le code répétitif (tests, Form
Requests, sérialisation) ; profondeur de la reconnaissance — les
vérifications dans le code du framework ont évité des bugs réels (liens de
pagination perdant leurs paramètres, corps d'erreur exposant une trace de
pile en mode debug) ; qualité de la couverture de tests, systématiquement
nominative et incluant les cas de sécurité (indistinction des corps de
réponse, absence de fuite dans les journaux).

**Limites** : tendance à trancher des décisions qui relèvent de l'arbitrage
humain — le cadre en deux phases avec remontée explicite des tensions a été
conçu en réponse ; préférence spontanée pour les conventions internes au
détriment des livrables nommés par les spécifications (emplacement des
fichiers qualité) ; nécessité d'un point d'arrêt formel entre relecture et
écriture, sans lequel l'agent enchaîne. La règle constante : l'IA recommande
et argumente, la décision et la pull request restent humaines.
