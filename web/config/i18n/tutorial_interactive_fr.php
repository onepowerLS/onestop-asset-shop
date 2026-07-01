<?php
/**
 * Tutoriel interactif (FR) — même structure que Fleet ([data-tutorial]).
 */
declare(strict_types=1);

$tracks = [
    'overview' => [
        'label' => 'Visite complète',
        'steps' => [
            [
                'id' => 'dash-kpis',
                'path' => 'index.php',
                'target' => 'tutorial-dashboard-kpis',
                'title' => 'Tableau de bord',
                'body' => 'Vue d’accueil : totaux, demandes en attente, pays et disponibilité. Utilisez le menu à gauche pour accéder à chaque zone.',
                'suggestion' => 'Les étapes suivantes passent par le catalogue, le stock, les demandes, les manifestes et les rapports.',
            ],
            [
                'id' => 'dash-class',
                'path' => 'index.php',
                'target' => 'tutorial-dashboard-class',
                'title' => 'Classification',
                'body' => 'Comptages par immobilisation, matériau, consommable et inventaire. Cliquez une carte pour ouvrir le catalogue filtré.',
            ],
            [
                'id' => 'dash-recent',
                'path' => 'index.php',
                'target' => 'tutorial-dashboard-recent',
                'title' => 'Activité récente',
                'body' => 'Les dernières transactions. Le journal complet est disponible depuis la fiche article.',
            ],
            [
                'id' => 'nav-catalog',
                'path' => 'index.php',
                'target' => 'nav-catalog',
                'title' => 'Catalogue',
                'body' => 'Le menu Catalogue liste « Tous les articles » et les raccourcis par classe. Déployez-le, puis Suivant pour ouvrir le catalogue complet.',
            ],
            [
                'id' => 'assets-header',
                'path' => 'assets/index.php',
                'target' => 'tutorial-assets-header',
                'title' => 'Registre des articles',
                'body' => 'Parcourez les articles avec recherche et filtres. Chaque ligne mène au détail, à l’historique et au QR.',
            ],
            [
                'id' => 'assets-add',
                'path' => 'assets/index.php',
                'target' => 'tutorial-assets-add',
                'title' => 'Ajouter des articles',
                'body' => 'Les managers et administrateurs utilisent « Ajouter un article » pour enregistrer stock ou immobilisations. La classification détermine les champs.',
            ],
            [
                'id' => 'nav-stock',
                'path' => 'assets/index.php',
                'target' => 'nav-inventory',
                'title' => 'Niveaux de stock',
                'body' => 'Les articles en quantité sont suivis sous Niveaux de stock. Suivant ouvre cette page.',
            ],
            [
                'id' => 'inventory',
                'path' => 'inventory/index.php',
                'target' => 'tutorial-inventory-header',
                'title' => 'Niveaux de stock',
                'body' => 'En stock, alloué, disponible et seuils de réapprovisionnement. Filtrez par classe et pays.',
            ],
            [
                'id' => 'nav-requests',
                'path' => 'inventory/index.php',
                'target' => 'nav-requests-procurement',
                'title' => 'Demandes',
                'body' => 'Les demandes Achats et les flux de service sont sous Demandes. Suivant ouvre les demandes Achats.',
            ],
            [
                'id' => 'requests',
                'path' => 'requests/index.php',
                'target' => 'tutorial-requests-header',
                'title' => 'Demandes d’achat',
                'body' => 'Saisissez les besoins liés au processus Achats. Les managers approuvent, rejettent ou honorent.',
            ],
            [
                'id' => 'nav-loadout',
                'path' => 'requests/index.php',
                'target' => 'nav-loadout',
                'title' => 'Manifestes de chargement',
                'body' => 'Listes de colisage siège → terrain. Liez aux voyages Fleet via l’ID de voyage (voir Fleet Hub).',
            ],
            [
                'id' => 'loadout',
                'path' => 'loadout/index.php',
                'target' => 'tutorial-loadout-header',
                'title' => 'Manifestes',
                'body' => 'Créez et gérez les manifestes ; ajoutez des lignes depuis le catalogue le cas échéant.',
            ],
            [
                'id' => 'nav-reports',
                'path' => 'loadout/index.php',
                'target' => 'nav-reports',
                'title' => 'Rapports',
                'body' => 'Exportez registres, journaux, stock, affectations, etc. en CSV ou PDF.',
            ],
            [
                'id' => 'reports',
                'path' => 'reports/index.php',
                'target' => 'tutorial-reports-grid',
                'title' => 'Rapports et export',
                'body' => 'Choisissez une carte de rapport, réglez les filtres, puis téléchargez. Souvent utilisé pour les audits.',
            ],
            [
                'id' => 'nav-help',
                'path' => 'reports/index.php',
                'target' => 'nav-help',
                'title' => 'Aide',
                'body' => 'La page Aide contient le guide complet (EN/FR). Tutoriel et Aide sont aussi dans la barre du haut.',
            ],
            [
                'id' => 'help',
                'path' => 'help.php',
                'target' => 'tutorial-help-header',
                'title' => 'Terminé',
                'body' => 'Les principales zones sont couvertes. Utilisez le mode tablette pour les opérations au scanner.',
                'suggestion' => 'Terminer pour fermer, ou Quitter à tout moment depuis le panneau.',
            ],
        ],
    ],
    'checkout' => [
        'label' => 'Sortie et retour',
        'steps' => [
            [
                'id' => 'co-intro',
                'path' => 'checkout/index.php',
                'target' => 'tutorial-checkout-out',
                'title' => 'Sortie',
                'body' => 'Choisissez un article disponible, un collaborateur, une date de retour optionnelle, puis validez.',
            ],
            [
                'id' => 'co-in',
                'path' => 'checkout/index.php',
                'target' => 'tutorial-checkout-in',
                'title' => 'Retour',
                'body' => 'Sélectionnez une affectation active et le lieu de retour pour clôturer.',
            ],
            [
                'id' => 'co-active',
                'path' => 'checkout/index.php',
                'target' => 'tutorial-checkout-active',
                'title' => 'Affectations actives',
                'body' => 'Liste des sorties en cours. La fiche article et les transactions donnent l’historique complet.',
            ],
        ],
    ],
    'requests' => [
        'label' => 'Demandes (achats et service)',
        'steps' => [
            [
                'id' => 'rq-pr',
                'path' => 'requests/index.php',
                'target' => 'tutorial-requests-header',
                'title' => 'Achats',
                'body' => 'Créez des demandes par classe, département, pays et priorité.',
            ],
            [
                'id' => 'rq-wf',
                'path' => 'requests/workflow-index.php',
                'target' => 'tutorial-workflow-header',
                'title' => 'Flux de service',
                'body' => 'Demandes AM basées sur modèles. Les managers mettent à jour le statut (approuvé, rejeté, honoré, annulé).',
            ],
        ],
    ],
    'loadout' => [
        'label' => 'Manifestes de chargement',
        'steps' => [
            [
                'id' => 'lo-intro',
                'path' => 'loadout/index.php',
                'target' => 'tutorial-loadout-header',
                'title' => 'Liste des manifestes',
                'body' => 'Filtrez par statut ou ID de voyage. Créez des manifestes pour les envois vers le terrain.',
            ],
            [
                'id' => 'lo-fleet',
                'path' => 'loadout/index.php',
                'target' => 'tutorial-loadout-fleet',
                'title' => 'Intégration Fleet',
                'body' => 'Liez aux voyages Fleet Hub via l’ID de voyage. L’édition des lignes reste dans la gestion d’actifs.',
            ],
        ],
    ],
    'tablet' => [
        'label' => 'Mode tablette',
        'steps' => [
            [
                'id' => 'tb-intro',
                'path' => 'tablet/index.php',
                'target' => 'tutorial-tablet-modes',
                'title' => 'Mode tablette',
                'body' => 'Interface tactile pour scanners : sortie/retour, inventaire, recherche rapide.',
            ],
            [
                'id' => 'tb-desktop',
                'path' => 'tablet/index.php',
                'target' => 'tutorial-tablet-desktop',
                'title' => 'Retour au bureau',
                'body' => 'Utilisez le mode bureau pour revenir à l’interface standard après les scans.',
            ],
        ],
    ],
    'reports' => [
        'label' => 'Rapports et export',
        'steps' => [
            [
                'id' => 'rp-grid',
                'path' => 'reports/index.php',
                'target' => 'tutorial-reports-grid',
                'title' => 'Choisir un rapport',
                'body' => 'Chaque carte est un type de rapport. Réglez les filtres, puis CSV ou PDF.',
            ],
            [
                'id' => 'rp-asset',
                'path' => 'reports/index.php',
                'target' => 'tutorial-reports-asset-card',
                'title' => 'Exemple : registre des actifs',
                'body' => 'Exports courants : registre complet, journal des transactions, stock, couverture QR.',
            ],
        ],
    ],
];

$track_order = ['overview', 'checkout', 'requests', 'loadout', 'tablet', 'reports'];

$query_map = [
    '1' => 'overview',
    'start' => 'overview',
    'overview' => 'overview',
    'tour' => 'overview',
    'checkout' => 'checkout',
    'checkin' => 'checkout',
    'requests' => 'requests',
    'procurement' => 'requests',
    'loadout' => 'loadout',
    'manifest' => 'loadout',
    'tablet' => 'tablet',
    'reports' => 'reports',
];

return [
    'tracks' => $tracks,
    'track_order' => $track_order,
    'query_map' => $query_map,
];
