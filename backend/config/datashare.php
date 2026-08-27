<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Dépôt de fichiers (US01)
    |--------------------------------------------------------------------------
    |
    | Tout ce qui borne un dépôt : disque dédié, taille maximale, durée de vie
    | du lien, longueur du token public et extensions refusées. Rassemblé ici
    | plutôt que dans filesystems.php pour que ces réglages métier restent
    | modifiables sans toucher à la configuration du framework.
    |
    */

    'uploads' => [

        // Disque dédié (config/filesystems.php) : jamais 'local' ni 'public',
        // pour que la purge (US10) et la suppression (US06) opèrent sur une
        // racine qui ne contient que des fichiers déposés.
        'disk' => env('DATASHARE_UPLOAD_DISK', 'uploads'),

        // 1 Go = 2^30 octets, la lecture qu'en font les explorateurs de
        // fichiers courants. Exprimé en octets ici ; converti en kilo-octets
        // (2^30 / 1024) au moment de construire la règle de validation.
        'max_bytes' => (int) env('DATASHARE_UPLOAD_MAX_BYTES', 1073741824),

        // Durée de validité du lien : défaut si le client ne précise rien,
        // plafond que la validation serveur fait respecter dans tous les cas.
        'default_expiry_days' => (int) env('DATASHARE_LINK_DEFAULT_DAYS', 7),
        'max_expiry_days' => (int) env('DATASHARE_LINK_MAX_DAYS', 7),

        // Longueur du token public. 22 caractères base62 ≈ 131 bits
        // d'entropie : voir docs/mcd.md pour l'arbitrage face aux 8 caractères
        // envisagés initialement, insuffisants pour un secret exposé dans une
        // URL publique.
        'token_length' => (int) env('DATASHARE_TOKEN_LENGTH', 22),

        // Liste noire, pas un contrôle de sécurité en soi : elle se contourne
        // en renommant ou en zippant. Elle réduit le risque d'exécution
        // accidentelle sur le poste du destinataire. Les vrais garde-fous sont
        // ailleurs : octets hors racine web, jamais exécutés, et remis en
        // Content-Disposition: attachment (US02).
        //
        // .js volontairement absent : à la fois script Windows Script Host et
        // fichier source légitime — le faux positif serait certain, le gain
        // quasi nul. Les documents à macros (docm, xlsm, pptm) sont eux aussi
        // volontairement absents : cf. SECURITY.md pour l'arbitrage.
        'blocked_extensions' => [
            'exe', 'bat', 'cmd', 'sh', 'ps1', 'msi', 'dll', 'scr', 'com', 'pif', 'jar', 'vbs',
        ],
    ],

    // Base des liens de téléchargement retournés par l'API : c'est la SPA qui
    // sert l'écran de téléchargement (US02), jamais une route Laravel — le
    // back-end n'a donc besoin de connaître que l'origine du front pour
    // construire le lien complet.
    'frontend_url' => env('DATASHARE_FRONTEND_URL', 'http://localhost:5173'),

    /*
    |--------------------------------------------------------------------------
    | Historique des fichiers (US05)
    |--------------------------------------------------------------------------
    |
    | Bornes de la pagination de GET /files, lues en configuration et jamais en
    | littéral : c'est ce qui permet à un test d'abaisser 'max_per_page' sans
    | générer des dizaines de fichiers pour vérifier le rejet, sur le même
    | principe que 'max_bytes' plus haut.
    |
    */

    'history' => [
        'per_page' => (int) env('DATASHARE_HISTORY_PER_PAGE', 25),
        'max_per_page' => (int) env('DATASHARE_HISTORY_MAX_PER_PAGE', 100),
    ],

    /*
    |--------------------------------------------------------------------------
    | Purge des fichiers expirés (US10)
    |--------------------------------------------------------------------------
    |
    | Taille du lot parcouru par `files:purge-expired`. La valeur a peu d'effet
    | en production — le coût d'un passage tient aux deux opérations disque de
    | chaque fichier, pas au nombre de requêtes — et elle est ici pour la même
    | raison que 'max_per_page' plus haut : qu'un test puisse l'abaisser à 2 et
    | prouver que le parcours ne saute rien, sans créer mille un fichiers. Le
    | défaut est celui du framework (Prunable::pruneAll).
    |
    */

    'purge' => [
        'chunk' => (int) env('DATASHARE_PURGE_CHUNK', 1000),
    ],

];
