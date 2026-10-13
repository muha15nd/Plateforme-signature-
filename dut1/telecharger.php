<?php
/** Téléchargement CSV (séparateur « ; » + BOM UTF-8 : s'ouvre bien dans Excel en français). */

// Fichier autonome : tout ce dont cette page a besoin est dans ce fichier.

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

/** Seul un DUT1 connecté passe. Retourne son id_filleul. */
function exiger_filleul(): int
{
    if (($_SESSION['role'] ?? '') !== 'filleul' || empty($_SESSION['id'])) {
        header('Location: connexion_dut1.php');   // page de l'équipe 2
        exit;
    }
    return (int) $_SESSION['id'];
}

/** Neutralise les cellules CSV commençant par = + - @ (injection de formule Excel). */
function cellule_sure(string $v): string
{
    return preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
}

// =============================================================================
// 4. PAGE
// =============================================================================
$idFilleul = exiger_filleul();

$st = $pdo->prepare('SELECT nom_utilisateur FROM filleul WHERE id_filleul = ?');
$st->execute([$idFilleul]);
$login = (string) $st->fetchColumn();

$st = $pdo->prepare(
    'SELECT p.prenom, p.nom, p.type_parrain, p.chambre, p.telephone, s.date_signature
     FROM signature s
     JOIN parrain p ON p.id_parrain = s.id_parrain
     WHERE s.id_filleul = ?
     ORDER BY s.date_signature'
);
$st->execute([$idFilleul]);

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="signatures_' . preg_replace('/[^A-Za-z0-9_-]/', '', $login) . '.csv"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, ['Prénom', 'Nom', 'Type', 'Chambre', 'Téléphone', 'Date de signature'], ';', '"', '');
while ($l = $st->fetch()) {
    fputcsv($out, [
        cellule_sure($l['prenom']), cellule_sure($l['nom']), $l['type_parrain'],
        analyser_chambre($l['chambre'])['affiche'], $l['telephone'], $l['date_signature'],
    ], ';', '"', '');
}
fclose($out);
