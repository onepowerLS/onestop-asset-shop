<?php
/**
 * Aide et guide utilisateur — français (HTML de confiance, rédigé dans le dépôt).
 */
declare(strict_types=1);

return [
    'page_title' => 'Aide et guide utilisateur',
    'subtitle' => 'Utiliser la gestion d’actifs 1PWR',
    'toc_title' => 'Sommaire',
    'footer' => 'Besoin d’aide supplémentaire ? Contactez l’administrateur système.',
    'lang_label' => 'Langue',
    'lang_en' => 'English',
    'lang_fr' => 'Français',
    'toc' => [
        'logging-in' => 'Connexion',
        'dashboard' => 'Tableau de bord',
        'catalog' => 'Catalogue (articles)',
        'stock-levels' => 'Niveaux de stock',
        'checkout' => 'Sortie et retour',
        'transactions' => 'Transactions',
        'requests' => 'Demandes',
        'loadout-manifests' => 'Manifestes de chargement',
        'qr-codes' => 'Codes QR',
        'reports' => 'Rapports et export',
        'tablet-mode' => 'Mode tablette',
        'admin' => 'Pages d’administration',
        'roles' => 'Rôles et droits',
    ],
    'sections' => [
        [
            'id' => 'logging-in',
            'icon' => 'fa-sign-in-alt',
            'title' => 'Connexion',
            'html' => '
<p>Rendez-vous sur <strong>am.1pwrafrica.com</strong>. Saisissez votre adresse e-mail 1PWR et votre mot de passe. L’application utilise Firebase — les mêmes identifiants que pour les Achats et les Job Cards.</p>
<p>Si vous utilisiez un <strong>nom d’utilisateur</strong> (sans e-mail), cela peut encore fonctionner grâce au mappage des comptes hérités.</p>
<div class="alert alert-info mb-0">
    <i class="fas fa-info-circle me-1"></i>
    <strong>Mot de passe oublié ?</strong> Utilisez « Mot de passe oublié ? » sur la page de connexion, ou contactez votre administrateur.
</div>',
        ],
        [
            'id' => 'dashboard',
            'icon' => 'fa-home',
            'title' => 'Tableau de bord',
            'html' => '
<p>Après connexion, le tableau de bord résume le parc :</p>
<ul class="mb-0">
    <li><strong>Total d’articles</strong> par pays et par classe</li>
    <li><strong>Répartition par classe</strong> — immobilisations, matériaux, consommables, inventaire ; cliquez une carte pour ouvrir le catalogue filtré</li>
    <li><strong>Articles par pays</strong> — Lesotho, Zambie, Bénin</li>
    <li><strong>Articles par statut</strong> — Disponible, Sorti, Alloué, etc.</li>
    <li><strong>Transactions récentes</strong> — dernières activités</li>
</ul>',
        ],
        [
            'id' => 'catalog',
            'icon' => 'fa-th-large',
            'title' => 'Catalogue (articles)',
            'html' => '
<h5 class="h6 text-uppercase text-muted">Consultation</h5>
<p>Utilisez le menu <strong>Catalogue</strong> :</p>
<ul>
    <li><strong>Tous les articles</strong> — catalogue complet</li>
    <li><strong>Immobilisations</strong> — véhicules, équipements, infrastructure</li>
    <li><strong>Matériaux</strong> — intrants pour chantier/installation</li>
    <li><strong>Consommables</strong> — EPI, bureau, maintenance</li>
    <li><strong>Inventaire</strong> — compteurs, pièces, kits</li>
</ul>
<p>Chaque ligne affiche le nom, la classe, la catégorie, le pays, le statut et l’état. Utilisez les filtres et la recherche (nom, n° de série, étiquette, code QR).</p>
<hr>
<h5 class="h6 text-uppercase text-muted">Ajouter un article</h5>
<ol>
    <li>Cliquez sur <strong>+ Ajouter un article</strong> dans le catalogue</li>
    <li>Choisissez la <strong>classification</strong> — elle détermine les champs :
        <ul>
            <li><em>Immobilisation</em> : n° de série, fabricant, modèle, date/prix d’achat, valeur résiduelle, garantie</li>
            <li><em>Matériau / Consommable / Inventaire</em> : quantité, unité, coût unitaire</li>
        </ul>
    </li>
    <li>Renseignez les champs obligatoires (nom, classe, pays, état, statut)</li>
    <li>Sélectionnez une <strong>catégorie</strong> (filtrée par classe)</li>
    <li>Cliquez sur <strong>Enregistrer</strong></li>
</ol>
<p>Les étiquettes d’actif suivent le format <code>1PWR-{CLASSE}-{PAYS}-000001</code>.</p>
<hr>
<h5 class="h6 text-uppercase text-muted">Voir et modifier</h5>
<p class="mb-0">Ouvrez une ligne pour le détail : propriétés, QR (si attribué), historique d’affectation et transactions. <strong>Modifier</strong> pour mettre à jour ; le <strong>statut</strong> (ex. Retiré, Radié) se change sur le formulaire d’édition.</p>',
        ],
        [
            'id' => 'stock-levels',
            'icon' => 'fa-warehouse',
            'title' => 'Niveaux de stock',
            'html' => '
<p>La page <strong>Niveaux de stock</strong> concerne les matériaux, consommables et inventaires — articles suivis par quantité.</p>
<table class="table table-sm">
    <thead><tr><th>Colonne</th><th>Signification</th></tr></thead>
    <tbody>
        <tr><td><strong>En stock</strong></td><td>Quantité physique</td></tr>
        <tr><td><strong>Alloué</strong></td><td>Affecté mais pas encore consommé/déployé</td></tr>
        <tr><td><strong>Disponible</strong></td><td>En stock moins l’alloué</td></tr>
        <tr><td><strong>Seuil de réappro.</strong></td><td>Seuil d’alerte</td></tr>
    </tbody>
</table>
<p class="mb-0">Cochez <strong>Uniquement stock bas</strong> et filtrez par classe/pays pour cibler la liste.</p>',
        ],
        [
            'id' => 'checkout',
            'icon' => 'fa-hand-holding',
            'title' => 'Sortie et retour',
            'html' => '
<h5 class="h6 text-uppercase text-muted">Sortie (check-out)</h5>
<ol>
    <li>Ouvrez <strong>Sortie / Retour</strong></li>
    <li>Dans <strong>Sortie</strong>, choisissez un article <code>Disponible</code></li>
    <li>Sélectionnez le collaborateur</li>
    <li>Optionnel : date de retour prévue</li>
    <li>Cliquez sur <strong>Sortir</strong></li>
</ol>
<p>Le statut passe à <code>CheckedOut</code> et une affectation est créée.</p>
<h5 class="h6 text-uppercase text-muted mt-3">Retour (check-in)</h5>
<ol>
    <li>Dans <strong>Retour</strong>, choisissez une affectation active</li>
    <li>Indiquez le lieu de retour</li>
    <li>Cliquez sur <strong>Retour</strong></li>
</ol>
<p class="mb-0">Le tableau <strong>Affectations actives</strong> liste tout ce qui est sorti.</p>',
        ],
        [
            'id' => 'transactions',
            'icon' => 'fa-exchange-alt',
            'title' => 'Transactions',
            'html' => '
<p>Chaque changement d’état est journalisé. Ouvrez <strong>Transactions</strong> pour la piste d’audit.</p>
<table class="table table-sm">
    <thead><tr><th>Type</th><th>Signification</th></tr></thead>
    <tbody>
        <tr><td><code>CheckOut</code></td><td>Sortie vers une personne</td></tr>
        <tr><td><code>CheckIn</code></td><td>Retour</td></tr>
        <tr><td><code>StockIngestion</code></td><td>Réception de stock</td></tr>
        <tr><td><code>StockTake</code></td><td>Inventaire physique saisi</td></tr>
        <tr><td><code>Transfer</code></td><td>Transfert entre lieux</td></tr>
        <tr><td><code>Allocation</code></td><td>Réservé pour un projet</td></tr>
        <tr><td><code>Return</code></td><td>Retour depuis une allocation projet</td></tr>
        <tr><td><code>WriteOff</code></td><td>Retrait du stock actif</td></tr>
        <tr><td><code>Consume</code></td><td>Consommation</td></tr>
        <tr><td><code>Deploy</code></td><td>Mise en service définitive</td></tr>
        <tr><td><code>QRScan</code></td><td>Scan informatif</td></tr>
    </tbody>
</table>
<p class="mb-0">Filtrez par type et recherchez pour retrouver un événement.</p>',
        ],
        [
            'id' => 'requests',
            'icon' => 'fa-clipboard-list',
            'title' => 'Demandes',
            'html' => '
<h5 class="h6 text-uppercase text-muted">Achats (Procurement)</h5>
<p>Sous <strong>Demandes → Achats</strong>, saisissez les besoins liés au flux Achats.</p>
<ol>
    <li>Cliquez sur <strong>Nouvelle demande</strong></li>
    <li>Choisissez la <strong>classe d’article</strong>, le <strong>département</strong> (RET, FAC, O&amp;M, Général)</li>
    <li>Définissez le <strong>pays</strong> et éventuellement le lieu</li>
    <li>Définissez la <strong>priorité</strong> et décrivez le besoin</li>
    <li><strong>Envoyer</strong> — référence du type <code>REQ-2026-0001</code></li>
</ol>
<p>Les administrateurs et managers peuvent <strong>Approuver</strong>, <strong>Rejeter</strong> (avec note) ou <strong>Honorer</strong> la demande.</p>
<h5 class="h6 text-uppercase text-muted mt-3">Flux de service</h5>
<p class="mb-0">Utilisez <strong>Demandes → Flux de service</strong> pour les demandes AM basées sur modèles (collection <code>am_core_requests</code>). Les managers/administrateurs mettent à jour le statut (Approuvé, Rejeté, Honoré, Annulé) depuis la liste ou le détail.</p>',
        ],
        [
            'id' => 'loadout-manifests',
            'icon' => 'fa-dolly',
            'title' => 'Manifestes de chargement',
            'html' => '
<p>Les <strong>manifestes de chargement</strong> sont des listes de colisage pour le stock ou le matériel quittant le siège vers un site terrain.</p>
<ul>
    <li>Créez ou modifiez un manifeste depuis <strong>Manifestes de chargement</strong> ; ajoutez des lignes liées au catalogue lorsque c’est pertinent.</li>
    <li>Liez un manifeste au contexte Flotte / voyage via l’<strong>ID de voyage</strong> pour l’intégration avec <code>fm.1pwrafrica.com</code> (API ou Firestore).</li>
    <li>Filtrez par statut ou voyage ; ouvrez un manifeste pour le détail et l’historique.</li>
</ul>
<p class="mb-0">Les profils en lecture seule (p. ex. certains auditeurs) peuvent consulter sans créer ni modifier.</p>',
        ],
        [
            'id' => 'qr-codes',
            'icon' => 'fa-qrcode',
            'title' => 'Codes QR',
            'html' => '
<h5 class="h6 text-uppercase text-muted">Génération</h5>
<p><strong>Admin → Étiquettes QR</strong> affiche la couverture, les articles sans code et les actions par lot. Format : <code>1PWR-{PAYS}-{PRÉFIXE_CLASSE}-{SÉQUENCE}</code>.</p>
<h5 class="h6 text-uppercase text-muted mt-3">Lecture</h5>
<ol class="mb-2">
    <li>Connectez un lecteur USB ou Bluetooth</li>
    <li>Scannez — la saisie simule un clavier</li>
    <li>L’application résout le code vers l’article</li>
    <li>Depuis la fiche : sortie, modification ou historique</li>
</ol>
<div class="alert alert-light mb-0">
    <i class="fas fa-lightbulb me-1 text-warning"></i>
    <strong>Conseil :</strong> utilisez le <strong>mode tablette</strong> pour les opérations intensives en scan.
</div>',
        ],
        [
            'id' => 'reports',
            'icon' => 'fa-chart-bar',
            'title' => 'Rapports et export',
            'html' => '
<p>Ouvrez <strong>Rapports</strong>, réglez les filtres de chaque carte, puis téléchargez en <strong>CSV</strong> ou <strong>PDF</strong>.</p>
<table class="table table-sm mb-0">
    <thead><tr><th>Rapport</th><th>Description</th><th>Export</th></tr></thead>
    <tbody>
        <tr><td><strong>Registre des actifs</strong></td><td>Registre complet par classe et pays</td><td>CSV, PDF</td></tr>
        <tr><td><strong>Journal des transactions</strong></td><td>Piste d’audit filtrable</td><td>CSV, PDF</td></tr>
        <tr><td><strong>Rapport de stock</strong></td><td>Niveaux et alertes de réappro.</td><td>CSV, PDF</td></tr>
        <tr><td><strong>Rapport d’affectations</strong></td><td>Affectations actives et historiques</td><td>CSV, PDF</td></tr>
        <tr><td><strong>Couverture QR</strong></td><td>Articles étiquetés ou non</td><td>CSV</td></tr>
        <tr><td><strong>Synthèse par classification</strong></td><td>Volumes/valeurs par classe et pays</td><td>CSV, PDF</td></tr>
    </tbody>
</table>',
        ],
        [
            'id' => 'tablet-mode',
            'icon' => 'fa-tablet-screen-button',
            'title' => 'Mode tablette',
            'html' => '
<p>Interface plein écran optimisée tactile et scanner. Menu <strong>Mode tablette</strong> ou <code>/tablet/</code>.</p>
<ul class="mb-0">
    <li><strong>Sortie / Retour</strong> — scanner puis affecter ou retourner</li>
    <li><strong>Inventaire (comptage)</strong> — scanner et saisir la quantité physique</li>
    <li><strong>Recherche rapide</strong> — fiche article immédiate</li>
    <li><strong>Mode bureau</strong> — retour à l’interface standard</li>
</ul>',
        ],
        [
            'id' => 'admin',
            'icon' => 'fa-cog',
            'title' => 'Pages d’administration',
            'html' => '
<p>Visibles pour les profils <strong>Admin</strong> (sauf mention contraire).</p>
<ul>
    <li><strong>Catégories</strong> — codes (ex. <code>FA-VEH</code>), périmètre département, amortissement pour les immobilisations, suivi de réappro.</li>
    <li><strong>Lieux</strong> — sites de stockage et d’exploitation par pays</li>
    <li><strong>Collaborateurs</strong> — annuaire partagé avec les Achats pour les affectations</li>
    <li><strong>Étiquettes QR</strong> — génération et attribution</li>
    <li><strong>Migration de données</strong> — import par lots</li>
    <li><strong>Provisionner un auditeur</strong> — comptes en lecture seule</li>
</ul>',
        ],
        [
            'id' => 'roles',
            'icon' => 'fa-user-shield',
            'title' => 'Rôles et droits',
            'html' => '
<table class="table table-sm">
    <thead><tr><th>Rôle</th><th>Accès</th></tr></thead>
    <tbody>
        <tr><td><strong>Admin</strong></td><td>Accès complet, y compris admin et migration</td></tr>
        <tr><td><strong>Manager</strong></td><td>Opérations, sorties/retours, articles, demandes — pas les réglages admin</td></tr>
        <tr><td><strong>Lecteur (Viewer)</strong></td><td>Lecture catalogue, stock, transactions ; peut créer des demandes</td></tr>
        <tr><td><strong>Auditeur</strong></td><td>Interface en lecture seule ; pas de sortie ni d’écriture (blocage UI)</td></tr>
    </tbody>
</table>
<p class="mb-0">Le rôle provient du <strong>permissionLevel</strong> dans le profil Firebase partagé. Pour toute modification, contactez un administrateur.</p>',
        ],
    ],
];
