<?php
/**
 * ÉQUIPE 4 – CSV des DUT1 pour lesquels j'ai signé (« ; » + BOM UTF-8 : s'ouvre bien dans Excel).
 *
 * Session attendue (à confirmer avec l'équipe 2) : $_SESSION['role'] = 'parrain' et $_SESSION['id'] = id_parrain.
 * Non connecté : on mémorise la page demandée dans $_SESSION['retour_apres_connexion'] puis on va sur
 * connexion_parrain.php (équipe 2).
 */

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

define('SELF', basename(__FILE__));

// =============================================================================
// 2. UTILITAIRES
// =============================================================================
/** Le parrain/la Mame connecté (compte actif), ou null. */
function parrain_connecte(PDO $pdo): ?array
{
    if (($_SESSION['role'] ?? '') !== 'parrain' || empty($_SESSION['id'])) {
        return null;
    }
    $st = $pdo->prepare(
        'SELECT id_parrain, prenom, nom, nom_utilisateur, type_parrain
         FROM parrain WHERE id_parrain = ? AND statut_compte = \'actif\''
    );
    $st->execute([(int) $_SESSION['id']]);
    return $st->fetch() ?: null;
}

/** Protège la page. Non connecté : on mémorise la page demandée et on va se connecter (équipe 2). */
function exiger_parrain(PDO $pdo): array
{
    $p = parrain_connecte($pdo);
    if ($p === null) {
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $_SESSION['retour_apres_connexion'] = SELF . '?' . ($_SERVER['QUERY_STRING'] ?? '');
        }
        header('Location: connexion_parrain.php');   // page de l'équipe 2
        exit;
    }
    return $p;
}

function cellule_sure(string $v): string
{
    return preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
}

// =============================================================================
// 3. PAGE
// =============================================================================

if (($_SESSION['role'] ?? '') === 'filleul') {
    http_response_code(403);
    exit('Page réservée aux parrains, marraines et Mames.');
}

$p = exiger_parrain($pdo);

$st = $pdo->prepare(
    'SELECT f.prenom, f.nom, f.telephone, s.date_signature
     FROM signature s JOIN filleul f ON f.id_filleul = s.id_filleul
     WHERE s.id_parrain = ? ORDER BY s.date_signature'
);
$st->execute([(int) $p['id_parrain']]);

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="mes_signatures_' . preg_replace('/[^A-Za-z0-9_-]/', '', $p['nom_utilisateur']) . '.csv"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, ['Prénom', 'Nom', 'Téléphone', 'Date de signature'], ';', '"', '');
while ($l = $st->fetch()) {
    fputcsv($out, [cellule_sure($l['prenom']), cellule_sure($l['nom']), $l['telephone'], $l['date_signature']], ';', '"', '');
}
fclose($out);
