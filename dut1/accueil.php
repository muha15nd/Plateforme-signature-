<?php
/**
 * DUT1 – Accueil : compteur de signatures reçues, rang, bouton « Se faire signer », code QR,
 * accès rapides, aperçu du registre.
 */
require_once __DIR__ . '/../includes/init.php';
$moi = exiger_role('dut1');
require_once __DIR__ . '/chambres.php';

$nb_signatures  = nb_signatures_filleul($pdo, $moi['id']);
$total_parrains = (int) $pdo->query('SELECT COUNT(*) FROM parrain WHERE statut_compte = \'actif\'')->fetchColumn();
$rang           = rang_filleul($pdo, $moi['id']);
$total_dut1     = nb_filleuls_actifs($pdo);
$url_qr         = url('parrain/valider.php?dut1=' . $moi['id']);   // cible du QR : parrain/valider.php

// Aperçu du registre : 5 lignes, signatures les plus récentes d'abord
$req = $pdo->prepare(
    'SELECT p.prenom, p.nom, p.type_parrain, p.chambre, p.photo, (s.id_parrain IS NOT NULL) AS a_signe
     FROM parrain p
     LEFT JOIN signature s ON s.id_parrain = p.id_parrain AND s.id_filleul = ?
     WHERE p.statut_compte = \'actif\'
     ORDER BY a_signe DESC, s.date_signature DESC, p.nom
     LIMIT 5'
);
$req->execute([$moi['id']]);
$apercu = $req->fetchAll();

$titre_page = 'Accueil';
require __DIR__ . '/../includes/header.php';
?>
<section class="carte accueil-dut1-bienvenue">
    <h1>Bienvenue <?= e($moi['prenom']) ?> !</h1>
    <p>Faites-vous signer par vos parrains, marraines et Mames.</p>
</section>

<section class="carte accueil-dut1-compteur">
    <p class="accueil-dut1-nombre"><strong><?= e($nb_signatures) ?></strong> sur <?= e($total_parrains) ?>
        <?= $nb_signatures > 1 ? 'signatures reçues' : 'signature reçue' ?></p>
    <progress class="accueil-dut1-barre" value="<?= e($nb_signatures) ?>" max="<?= e(max($total_parrains, 1)) ?>"></progress>
    <p class="accueil-dut1-rang">Vous êtes <?= $rang === 1 ? '1er' : e($rang) . 'e' ?> sur <?= e($total_dut1) ?>.</p>
    <p><a class="btn" href="<?= e(url('dut1/signer.php')) ?>">Se faire signer</a></p>
</section>

<section class="carte accueil-dut1-qr-carte">
    <h2>Code QR</h2>
    <p>Votre parrain ou votre Mame le scanne avec son téléphone pour signer.</p>
    <div id="accueil-dut1-qr" data-url="<?= e($url_qr) ?>"></div>
</section>

<section class="carte accueil-dut1-liens">
    <h2>Accès rapides</h2>
    <ul>
        <li><a href="<?= e(url('dut1/registre.php')) ?>">Registre des parrains et Mames</a></li>
        <li><a href="<?= e(url('dut1/registre.php?non_signes=1')) ?>">Ceux qui n’ont pas encore signé</a></li>
        <li><a href="<?= e(url('dut1/signatures.php')) ?>">Mes signatures</a></li>
        <li><a href="<?= e(url('dut1/classement.php')) ?>">Classement</a></li>
        <li><a href="<?= e(url('dut1/telecharger.php')) ?>">Télécharger mes signatures (CSV)</a></li>
    </ul>
</section>

<section class="carte accueil-dut1-apercu">
    <h2>Aperçu du registre</h2>
    <?php if ($apercu): ?>
        <ul class="signataire-liste">
            <?php foreach ($apercu as $r): ?>
                <li class="signataire-ligne">
                    <?= html_avatar($r) ?>
                    <strong><?= e($r['prenom'] . ' ' . $r['nom']) ?></strong>
                    <small><?= e(libelle_type_parrain($r['type_parrain']) . ' · ' . analyser_chambre($r['chambre'])['affiche']) ?></small>
                    — <?= $r['a_signe'] ? 'A signé' : 'Pas encore signé' ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <p><a href="<?= e(url('dut1/registre.php')) ?>">Voir tout le registre</a></p>
    <?php else: ?>
        <p>Aucun parrain ni Mame inscrit pour l’instant.</p>
    <?php endif; ?>
</section>

<!-- Code QR : bibliothèque qrcodejs. Script court laissé ici faute de fichier JS dédié ; à déplacer dans assets/js/qrcode.js si le groupe l'exige. -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
(function () {
    'use strict';
    var conteneur = document.getElementById('accueil-dut1-qr');
    if (!conteneur) { return; }
    var adresse = conteneur.dataset.url || '';
    if (typeof QRCode === 'undefined') { conteneur.textContent = adresse; return; }
    new QRCode(conteneur, { text: adresse, width: 200, height: 200, correctLevel: QRCode.CorrectLevel.M });
}());
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
