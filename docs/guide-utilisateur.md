# DataShare — Guide utilisateur

DataShare permet d'envoyer un fichier à quelqu'un sous la forme d'un lien.
Le lien fonctionne pendant une durée que vous choisissez, puis le fichier est
supprimé automatiquement.

## 1. À qui s'adresse ce guide

Deux personnes interviennent dans un partage :

- **celui qui envoie** : il a un compte DataShare, dépose le fichier et
  obtient le lien à transmettre ;
- **celui qui reçoit** : il n'a pas besoin de compte. Le lien suffit, tant
  qu'il n'a pas expiré.

Les sections 2, 3 et 5 s'adressent à celui qui envoie ; la section 4 à celui
qui reçoit.

## 2. Créer un compte et se connecter

**Créer un compte** — depuis la page d'accueil, cliquez sur *Créer un compte*.
Saisissez votre adresse email et un mot de passe, puis confirmez ce mot de
passe dans le champ *Saisissez-le à nouveau*.

- L'adresse email doit être valide et ne pas être déjà utilisée par un autre
  compte.
- Le mot de passe compte au moins 8 caractères.

Aucun email de confirmation n'est envoyé : le compte est utilisable
immédiatement, et vous êtes connecté dès sa création.

**Se connecter** — cliquez sur *Se connecter*, saisissez votre email et votre
mot de passe. Si l'un des deux est faux, le message est le même dans les deux
cas : il n'indique pas lequel est en cause.

**Se déconnecter** — le bouton *Se déconnecter* est disponible sur toutes les
pages une fois connecté. Par sécurité, une connexion inactive expire au bout
d'une heure ; il suffit alors de se reconnecter.

## 3. Envoyer un fichier

Une fois connecté, la page d'accueil pose la question *Voulez-vous partager un
fichier ?*

1. Cliquez sur le bouton d'envoi, puis sur *Ajouter un fichier* et choisissez
   le fichier sur votre ordinateur. Un seul fichier par envoi.
2. Choisissez la durée dans le champ *Expiration* : de 1 à 7 jours, 7 jours
   par défaut. Passé ce délai, le lien cesse de fonctionner et le fichier est
   supprimé. Cette durée ne peut pas être prolongée ensuite : pour partager
   plus longtemps, il faut redéposer le fichier.
3. Facultatif : saisissez un mot de passe dans le champ *Mot de passe*
   (au moins 6 caractères). La personne qui reçoit devra le saisir pour
   télécharger. Ce mot de passe n'est ni affiché ni récupérable par la suite :
   notez-le avant de l'envoyer, et transmettez-le de préférence par un autre
   canal que le lien lui-même.
4. Cliquez sur *Téléverser*. Une barre de progression s'affiche pendant
   l'envoi.

Quand le message *Félicitations, votre fichier est en ligne !* apparaît, le
lien est prêt : cliquez sur *Copier le lien* et transmettez-le à votre
destinataire.

**Ce qui est refusé à l'envoi**

- Un fichier de plus de 1 Go.
- Un fichier dont le nom se termine par l'une de ces extensions, réservées aux
  programmes exécutables : `.exe`, `.bat`, `.cmd`, `.sh`, `.ps1`, `.msi`,
  `.dll`, `.scr`, `.com`, `.pif`, `.jar`, `.vbs`. Un avertissement s'affiche
  dès le choix du fichier ; l'envoi est refusé avec le motif exact.
- Un mot de passe de moins de 6 caractères, ou une durée hors de 1 à 7 jours.

Dans tous ces cas, le motif du refus s'affiche sous le champ concerné.

## 4. Recevoir un fichier

Ouvrez le lien que vous avez reçu. La page *Télécharger un fichier* affiche,
avant tout téléchargement, le nom du fichier, sa taille et la date à laquelle
il expirera.

- Si le fichier est protégé, un champ *Mot de passe* est affiché : saisissez
  le mot de passe que l'expéditeur vous a communiqué.
- Cliquez sur *Télécharger*. Le message *Le téléchargement a démarré.*
  confirme que le fichier est en cours de réception dans votre navigateur.

**Messages possibles et ce qu'ils signifient**

| Message affiché | Signification | Que faire |
| --- | --- | --- |
| *Ce lien de téléchargement est invalide.* | Le lien ne correspond à aucun fichier : il a été mal copié, ou le fichier a été supprimé par son expéditeur. | Vérifiez le lien reçu ; sinon, demandez un nouveau lien à l'expéditeur. |
| *Ce lien a expiré : le fichier n'est plus disponible.* | Le lien était valide mais sa durée est écoulée. Le fichier a été supprimé. | Demandez à l'expéditeur de redéposer le fichier. |
| *Mot de passe incorrect.* | Le mot de passe saisi ne correspond pas. | Vérifiez la saisie auprès de l'expéditeur. |
| *Trop de requêtes. Réessayez dans quelques instants.* | Trop de tentatives en peu de temps sur ce lien. | Patientez une minute avant de réessayer. |

## 5. Gérer ses fichiers

Une fois connecté, *Mon espace* liste tous les fichiers que vous avez envoyés,
avec pour chacun son nom, sa taille, sa date d'envoi, sa date d'expiration et
son état. Les fichiers dont la durée est écoulée portent la mention *Expiré* :
leur lien ne fonctionne plus.

**Filtrer** — le sélecteur *Filtrer par état* propose *Tous*, *Actifs* et
*Expirés*. Au-delà de 25 fichiers, la liste est répartie sur plusieurs pages.

**Supprimer** — le bouton *Supprimer* d'un fichier ouvre une demande de
confirmation. La suppression est immédiate et définitive : le fichier est
effacé et son lien cesse de fonctionner à l'instant, pour tous ceux qui l'ont
reçu.

Il n'est pas nécessaire de supprimer soi-même les fichiers expirés : ils sont
effacés automatiquement.