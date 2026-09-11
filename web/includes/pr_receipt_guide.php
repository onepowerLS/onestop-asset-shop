<?php $receiptGuideFr = ($page_lang ?? 'en') === 'fr'; ?>
<section id="pr-am-receipts" class="card border-primary mb-4"><div class="card-body">
<h2 class="h4"><?= $receiptGuideFr ? 'Réceptions PR et correspondances UGP' : 'PR receipts and UGP mappings' ?></h2>
<ol>
<?php if ($receiptGuideFr): ?>
<li>Dans une commande PR au statut ORDERED, un administrateur PR choisit « Set up AM receipt checks », le site, le propriétaire et l’article AM exact pour chaque ligne. Il vérifie les spécifications et active le contrôle.</li>
<li>L’approbateur AM ouvre le lien de réception depuis PR, choisit la ligne, saisit la quantité acceptée et la référence du bon de livraison, puis confirme. Ne ressaisissez pas des biens déjà enregistrés ailleurs dans AM.</li>
<li>Les réceptions partielles laissent la commande ouverte. PR autorise la clôture lorsque toutes les quantités approuvées sont enregistrées. Les photos ne remplacent pas ce contrôle.</li>
<li>Pour un retour intégral, utilisez « Reverse a receipt » avec l’identifiant de réception et le motif. Un retour après clôture signale une exception à examiner.</li>
</ol><p>Pour relier un article à UGP, ouvrez sa fiche puis « Verify UGP mapping ». Vérifiez les spécifications et unités actuelles. Un nom similaire est une proposition, pas une preuve.</p>
<p class="mb-0"><strong>Limites du pilote :</strong> biens en unités entières, stocks réconciliés et destinations existantes. Aucun rattrapage historique, aucune conversion de kits ni retour partiel. Tout doublon ou écart de stock exige une réconciliation AM.</p>
<?php else: ?>
<li>On an ORDERED purchase order, a PR administrator selects <strong>Set up AM receipt checks</strong>, chooses the destination and owner, and matches every approved line to the exact AM item. Verify specifications before enabling the checks.</li>
<li>The AM approver follows the receipt link from PR, selects the line, enters the accepted quantity and delivery-note reference, then confirms. Do not record goods already entered through another AM screen.</li>
<li>Partial receipts keep the order open. PR permits closeout when AM has recorded every approved quantity. Delivery photos cannot replace this check.</li>
<li>For a full return, use <strong>Reverse a receipt</strong> with the receipt identifier and reason. A return after closeout creates an exception for review.</li>
</ol><p>To link an item to UGP, open its detail page and choose <strong>Verify UGP mapping</strong>. Check current specifications and units. A similar name is a proposal, not proof.</p>
<p class="mb-0"><strong>Pilot limits:</strong> whole-unit goods, reconciled stock and existing destinations. No historical backfill, kit conversions or partial returns. Stock differences or duplicate locations require AM reconciliation.</p>
<?php endif ?>
</div></section>
