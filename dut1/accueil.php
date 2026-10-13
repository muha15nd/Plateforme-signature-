<?php
/** Accueil DUT1 : compteur, bouton, QR, actions rapides, checklist (aperçu). */

// =============================================================================
// 1. CONFIGURATION
// =============================================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pdo = new PDO(
    'mysql:host=localhost;dbname=signature2027;charset=utf8mb4',
    'root',          // ← à adapter
    '',              // ← à adapter
    [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]
);

// Adresse publique du site (contenu du code QR). Pas "localhost" en production :
// le téléphone du signataire doit pouvoir l'ouvrir (ex. http://192.168.1.20/signature2027).
const BASE_URL = 'http://localhost/signature2027';

// =============================================================================
// 2. CHAMBRES
// =============================================================================
const PAVILLONS = ['A', 'B', 'C', 'G'];

const ETAGES = [
    'A' => [
        'Rez-de-chaussée' => [1, 5],
        'Étage 1'         => [6, 28],
        'Étage 2'         => [29, 51],
        'Étage 3'         => [52, 75],
    ],
    'B' => [
        'Rez-de-chaussée' => [1, 6],
        'Étage 1'         => [7, 29],
        'Étage 2'         => [30, 52],
        'Étage 3'         => [53, 75],
    ],
    'C' => [
        'Rez-de-chaussée' => [1, 5],
        'Étage 1'         => [6, 28],
        'Étage 2'         => [29, 51],
        'Étage 3'         => [52, 75],
    ],
    'G' => [
        'Étage 1' => [13, 31],
        'Étage 2' => [32, 50],
        'Étage 3' => [51, 69],
        'Étage 4' => [70, 88],
    ],
];

const ETAGE_AUCUN   = '_';
const ETAGE_INCONNU = 'Étage non défini';

/** Transforme la colonne « chambre » ("29G", "G29", "29 g"…) en pavillon / chambre / étage. */
function analyser_chambre(?string $chambre_brute): array
{
    $brut = trim((string) $chambre_brute);
    $pavillon = null;
    $chambre  = null;

    if (preg_match('/^(\d{1,3})[\s-]*([A-Za-z])$/u', $brut, $m)) {          // 29G
        $chambre  = (int) $m[1];
        $pavillon = strtoupper($m[2]);
    } elseif (preg_match('/^([A-Za-z])[\s-]*(\d{1,3})$/u', $brut, $m)) {    // G29
        $chambre  = (int) $m[2];
        $pavillon = strtoupper($m[1]);
    }

    if ($pavillon === null || !in_array($pavillon, PAVILLONS, true)) {
        return ['valide' => false, 'pavillon' => '?', 'chambre' => null,
                'etage' => ETAGE_AUCUN, 'affiche' => $brut !== '' ? $brut : 'Non renseigné'];
    }

    $etage = ETAGE_AUCUN;
    if (!empty(ETAGES[$pavillon])) {
        $etage = ETAGE_INCONNU;
        foreach (ETAGES[$pavillon] as $libelle => [$min, $max]) {
            if ($chambre >= $min && $chambre <= $max) {
                $etage = $libelle;
                break;
            }
        }
    }

    return ['valide' => true, 'pavillon' => $pavillon, 'chambre' => $chambre,
            'etage' => $etage, 'affiche' => $chambre . $pavillon];
}

// =============================================================================
// 3. UTILITAIRES
// =============================================================================
function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Seul un DUT1 connecté passe. Retourne son id_filleul. */
function exiger_filleul(): int
{
    if (($_SESSION['role'] ?? '') !== 'filleul' || empty($_SESSION['id'])) {
        header('Location: connexion_dut1.php');   // page de l'équipe 2
        exit;
    }
    return (int) $_SESSION['id'];
}

function flash_lire(): ?array
{
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

/** Avatar d'un parrain : sa photo (colonne « photo ») sinon ses initiales. */
function avatar_html(array $r): string
{
    if (!empty($r['photo'])) {
        return '<img class="avatar" src="' . e($r['photo']) . '" alt="">';
    }
    return '<span class="avatar">' . e(mb_strtoupper(mb_substr($r['prenom'], 0, 1) . mb_substr($r['nom'], 0, 1))) . '</span>';
}

/** Début de page : en-tête HTML + menu. Se ferme avec fin_page(). */
function debut_page(string $titre, string $classe = ''): void
{ ?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($titre) ?> – Signature 2027</title>
<style>
:root{--c:#1e5eff;--ok:#1a9b55}
*{box-sizing:border-box}
body{margin:0;font-family:system-ui,sans-serif;background:#f4f6fb;color:#1b2333}
header{background:#fff;padding:12px 16px;display:flex;gap:14px;align-items:center;flex-wrap:wrap;box-shadow:0 1px 4px #0001}
header h1{font-size:1.1rem;margin:0;flex:1}
header a{color:var(--c);text-decoration:none;font-weight:600}
main{max-width:720px;margin:16px auto;padding:0 12px}
.carte{background:#fff;border-radius:12px;padding:16px;margin-bottom:16px;box-shadow:0 1px 4px #0001}
.msg{padding:10px 14px;border-radius:8px;margin-bottom:12px}
.msg.succes{background:#e3f6ea;color:#14663a}
.msg.echec{background:#fde6e6;color:#a12626}
.liste{list-style:none;margin:0;padding:0}
.ligne{display:flex;gap:10px;align-items:center;padding:10px 0;border-bottom:1px solid #e6e9f0}
.ligne.fait .nom{color:var(--ok)}
.avatar{width:40px;height:40px;border-radius:50%;background:#dbe4ff;color:var(--c);display:flex;align-items:center;justify-content:center;font-weight:700;object-fit:cover;flex:none}
.sous{color:#667;font-size:.85rem}
.barre{height:10px;background:#e3e8f3;border-radius:6px;overflow:hidden;margin:8px 0 14px}
.barre>div{height:100%;background:var(--c)}
.btn{display:inline-block;background:var(--c);color:#fff;border:0;border-radius:8px;padding:10px 16px;font-size:1rem;text-decoration:none;cursor:pointer}
input,select{width:100%;padding:10px;border:1px solid #c9d0e0;border-radius:8px;font-size:1rem;margin:4px 0 12px}
input[type=checkbox]{width:auto;margin:0 6px 0 0}
</style>
</head>
<body class="<?= e($classe) ?>">
<header>
  <h1><?= e($titre) ?></h1>
  <a href="accueil.php">Accueil</a>
  <a href="registre.php">Registre</a>
  <a href="signatures.php">Mes signatures</a>
  <a href="deconnexion.php">Déconnexion</a>
</header>
<main>
<?php }

function fin_page(): void
{ ?>
</main>
<?php }

// =============================================================================
// PAGE
// =============================================================================

$idFilleul = exiger_filleul();

$st = $pdo->prepare('SELECT prenom FROM filleul WHERE id_filleul = ?');
$st->execute([$idFilleul]);
$dut1 = $st->fetch();
if (!$dut1) {                      // compte supprimé par l'admin pendant la session
    session_destroy();
    header('Location: connexion_dut1.php');
    exit;
}

// Compteur et total (RG8 : calculés, jamais stockés)
$st = $pdo->prepare('SELECT COUNT(*) FROM signature WHERE id_filleul = ?');
$st->execute([$idFilleul]);
$nbSignatures = (int) $st->fetchColumn();

$totalParrains = (int) $pdo->query("SELECT COUNT(*) FROM parrain WHERE statut_compte = 'actif'")->fetchColumn();
$pourcent = $totalParrains > 0 ? min(100, (int) round($nbSignatures / $totalParrains * 100)) : 0;

// Checklist (aperçu) : 5 lignes, signatures les plus récentes d'abord
$st = $pdo->prepare(
    'SELECT p.prenom, p.nom, p.type_parrain, p.chambre, p.photo, (s.id_parrain IS NOT NULL) AS a_signe
     FROM parrain p
     LEFT JOIN signature s ON s.id_parrain = p.id_parrain AND s.id_filleul = ?
     WHERE p.statut_compte = \'actif\'
     ORDER BY a_signe DESC, s.date_signature DESC, p.nom
     LIMIT 5'
);
$st->execute([$idFilleul]);
$apercu = $st->fetchAll();

$flash = flash_lire();
$urlQr = BASE_URL . '/signer_qr.php?id_filleul=' . $idFilleul;   // page de l'équipe 4

debut_page('Accueil');
?>
<?php if ($flash): ?>
  <div class="msg <?= e($flash['type']) ?>" role="status"><?= e($flash['message']) ?></div>
<?php endif; ?>

<section class="carte">
  <h2>Salut <?= e($dut1['prenom']) ?> 👋</h2>
  <p><strong><?= $nbSignatures ?></strong> signature(s) sur <?= $totalParrains ?> (<?= $pourcent ?> %)</p>
  <div class="barre"><div style="width:<?= $pourcent ?>%"></div></div>
  <a class="btn" href="signer.php">Faire signer</a>
</section>

<section class="carte">
  <h3>Mon code QR</h3>
  <div id="qr"></div>
  <p class="sous"><?= e($urlQr) ?></p>
</section>

<section class="carte">
  <h3>Actions rapides</h3>
  <p>
    <a class="btn" href="registre.php">Registre</a>
    <a class="btn" href="signatures.php">Mes signatures</a>
    <a class="btn" href="telecharger.php">Télécharger (CSV)</a>
  </p>
</section>

<section class="carte">
  <h3>Checklist</h3>
  <ul class="liste">
  <?php foreach ($apercu as $r): ?>
    <li class="ligne <?= $r['a_signe'] ? 'fait' : '' ?>">
      <?= avatar_html($r) ?>
      <div>
        <div class="nom"><?= $r['a_signe'] ? '✓ A signé : ' : 'Pas encore signé : ' ?><?= e($r['prenom'] . ' ' . $r['nom']) ?></div>
        <div class="sous"><?= e($r['type_parrain']) ?> · <?= e(analyser_chambre($r['chambre'])['affiche']) ?></div>
      </div>
    </li>
  <?php endforeach; ?>
  <?php if (!$apercu): ?>
    <li class="sous">Aucun parrain ni Mame inscrit pour l'instant.</li>
  <?php endif; ?>
  </ul>
  <p><a href="registre.php">Voir tout le registre →</a></p>
</section>
<?php fin_page(); ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
new QRCode(document.getElementById('qr'), {
  text: <?= json_encode($urlQr, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
  width: 180, height: 180
});
</script>
</body>
</html>
