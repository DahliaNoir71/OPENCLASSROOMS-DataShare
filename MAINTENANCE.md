# Maintenance

Ce document sera complété au lot qualité ; il consigne pour l'instant ce que
le lot de la purge planifiée (US10) rend nécessaire à l'exploitation. Le
raisonnement déjà écrit sur le scheduler, l'ordre des suppressions et la
piste d'audit reste dans [docs/architecture.md](docs/architecture.md) et
n'est pas recopié ici : ce document ne garde que le geste, jamais la règle.

## Prérequis d'exploitation

### L'entrée cron

Le scheduler Laravel ne s'exécute pas de lui-même : il suppose une entrée
cron appelant `schedule:run` chaque minute (le pourquoi de ce découplage est
dans [docs/architecture.md](docs/architecture.md#ce-que-garantit--et-ne-garantit-pas--le-scheduler)).
Aucun script de déploiement n'existe encore dans ce dépôt ; en attendant, la
ligne à déposer sur l'hôte de déploiement est :

```cron
# /etc/cron.d/datashare — appel du scheduler Laravel (US10)
# L'heure du passage est déclarée dans backend/routes/console.php, pas ici :
# cron n'appelle que le scheduler, chaque minute.
PATH=/usr/local/bin:/usr/bin:/bin
MAILTO=exploitation@example.org
* * * * * www-data cd /srv/datashare/backend && /usr/bin/php artisan schedule:run --whisper >/dev/null
```

Trois pièges, chacun réel :

- **Chemins absolus.** Le `PATH` de cron est minimal ; `php` peut ne pas s'y
  trouver. D'où `/usr/bin/php` en dur, et un `cd` vers la racine du
  back-end.
- **L'utilisateur doit être celui de php-fpm** (`www-data` ici). Sinon
  `storage/logs/` et le disque `uploads` reçoivent des fichiers appartenant
  au mauvais compte, et c'est le processus **web** qui échouera plus tard à
  y écrire.
- **La redirection.** `--whisper` supprime le « No scheduled commands are
  ready to run. » que `schedule:run` afficherait 1439 fois par jour.
  `>/dev/null` jette le reste de la sortie standard, mais ce n'est pas
  étouffer une erreur : le vrai rapport de la tâche est la ligne
  `Expired files purged` dans les journaux, pas sa sortie standard.

### Ce qui se passe sans elle

Les liens expirent quand même : l'inaccessibilité est vérifiée à chaque
requête, indépendamment du scheduler. Ce qui manque sans l'entrée cron,
c'est l'effacement — le disque ne se libère jamais. Et aucune réponse HTTP
ne le signale : le scheduler n'a pas de client
([docs/architecture.md](docs/architecture.md#comment-linformation-remonte)).

### En développement

Un poste de développement n'a pas de cron. `php artisan schedule:work`, dans
un terminal supplémentaire, boucle et appelle `schedule:run` chaque minute —
ce n'est pas la voie de déploiement, seulement un moyen de voir la purge
partir sans rien installer sur son poste.

## Mise à jour des dépendances

### Rythme

Un passage de contrôle mensuel, et sans délai dès qu'un avis de sécurité est
publié sur une dépendance du projet. Regarder n'est pas mettre à jour : la
décision de mettre à jour se prend au cas par cas, cf. ci-dessous.

### Repérer une faille

Côté backend :

```bash
cd backend
composer audit              # base d'avis Packagist, intégrée à Composer 2.4+
composer outdated --direct  # dépendances directes seulement
```

Côté frontend :

```bash
cd frontend
npm audit
npm outdated
```

`npm audit` remonte massivement des dépendances de **build** (Vite, Cypress)
qui ne partent jamais en production : trier avant de s'alarmer.

### Ce qui distingue un correctif d'une montée de version

Les contraintes `^` de `composer.json` et de `package.json` autorisent déjà
patch et mineur : `composer update` / `npm update` les appliquent sans
toucher au manifeste, c'est le geste de maintenance ordinaire. Une montée
majeure (Laravel 13 → 14, jwt-auth 2 → 3, Vue 3 → 4) suppose d'éditer la
contrainte et de lire le guide de migration : c'est un lot à part entière,
jamais une passe de maintenance.

### Les deux dépendances à surveiller de près

**Laravel 13** est récent : ses versions mineures sortent chaque semaine et
emportent parfois des changements de comportement.
**`php-open-source-saver/jwt-auth`** est un fork communautaire d'un paquet
abandonné — c'est la **seule** dépendance tierce sur le chemin
d'authentification, sans repli, et sa cadence de publication est donc une
exposition du projet en soi.

### Vérifier après une mise à jour

Dans l'ordre :

1. `composer update` (ou `npm update`)
2. `composer run test` (backend) — la procédure complète est dans
   [README.md](README.md#tests)
3. `./vendor/bin/pint --test`
4. Relecture du diff de `composer.lock` (ou `package-lock.json`)
5. Avant toute étape importante, rejeu de la suite backend sur PostgreSQL —
   procédure dans [README.md](README.md#tests)
6. Côté frontend : `npx vitest run`, `npm run type-check`, `npm run lint`,
   `npm run test:e2e`

`composer.lock` et `package-lock.json` sont versionnés : une passe de
maintenance est **un commit `chore(deps):` qui porte le diff de verrou**,
jamais une mise à jour silencieuse sur un serveur.

## Exploitation de la purge

### Vérifier qu'elle a tourné

La purge n'a pas de client : elle ne produit aucune réponse HTTP, et sa
seule trace est une ligne `Expired files purged` dans les journaux. Après
03:05 UTC, la chercher :

```bash
grep 'Expired files purged' storage/logs/laravel.log | tail -3
```

Son absence signifie l'une de trois choses, à écarter dans cet ordre.
L'entrée cron manque, donc `schedule:run` n'est jamais appelé et rien ne
tourne — c'est le cas le plus fréquent après une réinstallation de serveur.
L'application est en mode maintenance, où la tâche est volontairement
sautée. Ou `LOG_LEVEL` a été remonté à `warning`, qui fait taire toute la
piste d'audit sans rien casser d'autre. Le troisième cas est le plus
trompeur, parce que le service, lui, fonctionne : ne pas conclure à une
panne de purge avant d'avoir vérifié le niveau de journal.

### La rejouer à la main

```bash
php artisan files:purge-expired   # le plus simple, rejouable sans dommage
php artisan schedule:test         # la joue via le scheduler, vérifie le câblage
```

`php artisan schedule:run` ne fait rien hors de la minute planifiée, et
c'est normal — il ne sert qu'à cron.

### Lire ses lignes d'audit

La synthèse `Expired files purged` est en niveau `info`, son contexte porte
le nombre supprimé et le nombre en échec (`deleted`, `failed`). Une ligne
`debug` `Expired file purged` accompagne chaque fichier effectivement
purgé, avec son seul identifiant — filtrée par défaut en production, elle
ne coûte rien au quotidien et reste disponible en diagnostic ponctuel
(`LOG_LEVEL=debug` temporaire). La convention générale de la piste d'audit
est dans [docs/architecture.md](docs/architecture.md#la-piste-daudit), et
n'est pas recopiée ici.

### Quand le compteur d'échecs n'est pas nul

Causes plausibles : droits insuffisants sur le disque `uploads`, disque
plein, latence ou identifiants d'un futur driver distant. La ligne en échec
reste en base, donc le passage suivant retente automatiquement — **un
compteur non nul une fois n'est pas un incident, un compteur non nul
plusieurs jours de suite en est un.** Symptôme miroir à surveiller :
`Link content missing`, en niveau `error`.

### Vérifier la planification elle-même

```bash
php artisan schedule:list
```

Affiche l'expression cron et la prochaine échéance. L'heure est en UTC,
comme `expires_at` et comme les horodatages des journaux — une seule
horloge dans toute l'application. Pour la lire en heure locale sans rien
changer au code : `php artisan schedule:list --timezone=Europe/Paris`.

### Ne pas confondre avec `cache:clear`

Un exploitant en séance de maintenance y viendra tôt ou tard : `cache:clear`
efface aussi la liste noire des jetons JWT, ce qui rend valides jusqu'à leur
échéance des jetons pourtant déconnectés. Raisonnement complet dans
[docs/architecture.md](docs/architecture.md#limites-assumées-et-voie-de-production).

## Piste frontend : lint d'accessibilité

`eslint-plugin-vuejs-accessibility` n'est pas installé (lot B18-B26,
2026-08-28). Écarté pour ce lot : l'activer sur l'ensemble du frontend
remonterait des erreurs sur des composants hors périmètre, sans lien avec le
travail en cours. À évaluer pour une passe qualité dédiée, en mode `warn` ou
sur un périmètre de fichiers restreint le temps de résorber l'existant.

## Ce que ce document ne dit pas

- **Installation, mise en route, prérequis PHP** — [README.md](README.md)
- **Les blocs de commandes de test et de qualité** — [README.md](README.md)
- **Pourquoi le contenu physique part avant la ligne en base** —
  [docs/architecture.md](docs/architecture.md#ce-que-garantit--et-ne-garantit-pas--le-scheduler)
- **Ce que le scheduler garantit et ne garantit pas** —
  [docs/architecture.md](docs/architecture.md#ce-que-garantit--et-ne-garantit-pas--le-scheduler)
- **La convention de la piste d'audit, et le piège du `LOG_LEVEL`** —
  [docs/architecture.md](docs/architecture.md#la-piste-daudit)
- **Les limites de sécurité assumées** — [SECURITY.md](SECURITY.md)
- **Les arbitrages de performance** — [PERF.md](PERF.md)
- **Les variables d'environnement de production** —
  [README.md](README.md#points-ouverts),
  [docs/architecture.md](docs/architecture.md#configuration-cible)
