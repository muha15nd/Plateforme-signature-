<?php
/**
 * Liste des signataires du DUT1 connecté (parrains / Mames qui ont signé pour lui),
 * avec un lien WhatsApp vers chacun.
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

/** Avatar d'un parrain : sa photo (colonne « photo ») sinon ses initiales. */
function avatar_html(array $r): string
{
    if (!empty($r['photo'])) {
        return vue('avatar_photo', ['src' => $r['photo']]);
    }
    return vue('avatar_initiales', [
        'initiales' => mb_strtoupper(mb_substr($r['prenom'], 0, 1) . mb_substr($r['nom'], 0, 1)),
    ]);
}

// =============================================================================
// 4. GABARITS
// =============================================================================
// Le HTML vit dans equipe3.html. {{nom}} = valeur échappée, {{html_xxx}} = insérée telle quelle.
function gabarits(): array
{
    static $liste = null;
    if ($liste === null) {
        $liste = [];
        $source = file_get_contents(__DIR__ . '/equipe3.html');
        preg_match_all('/<!--\s*@gabarit\s+(\w+)\s*-->(.*?)<!--\s*@fin\s*-->/s', $source, $m, PREG_SET_ORDER);
        foreach ($m as $g) {
            $liste[$g[1]] = trim($g[2], "\r\n");
        }
    }
    return $liste;
}

function vue(string $nom, array $variables = []): string
{
    $html = gabarits()[$nom] ?? '';
    return preg_replace_callback('/\{\{(\w+)\}\}/', function (array $m) use ($variables): string {
        $valeur = (string) ($variables[$m[1]] ?? '');
        return strncmp($m[1], 'html_', 5) === 0 ? $valeur : e($valeur);
    }, $html);
}

function page(string $titre, string $contenu, array $scripts = [], string $classeBody = ''): string
{
    $balises = '';
    foreach ($scripts as $src) {
        $balises .= vue('script', ['src' => $src]);
    }
    return vue('layout', [
        'titre' => $titre, 'classe_body' => $classeBody,
        'html_contenu' => $contenu, 'html_scripts' => $balises,
    ]);
}

// =============================================================================
// 5. PAGE
// =============================================================================

$idFilleul = exiger_filleul();

// Signataires du DUT1 connecté, avec téléphone pour le lien WhatsApp
$st = $pdo->prepare(
    'SELECT p.prenom, p.nom, p.type_parrain, p.chambre, p.telephone, p.photo, s.date_signature
     FROM signature s
     JOIN parrain p ON p.id_parrain = s.id_parrain
     WHERE s.id_filleul = ?
     ORDER BY s.date_signature'
);
$st->execute([$idFilleul]);
$signataires = $st->fetchAll();

$lignes = '';
foreach ($signataires as $r) {
    $lignes .= vue('ligne_signataire', [
        'html_avatar' => avatar_html($r),
        'nom'         => $r['prenom'] . ' ' . $r['nom'],
        'type'        => $r['type_parrain'],
        'chambre'     => analyser_chambre($r['chambre'])['affiche'],
        'date'        => date('d/m/Y H:i', strtotime($r['date_signature'])),
        'lien_wa'     => 'https://wa.me/' . preg_replace('/\D/', '', (string) $r['telephone']),
    ]);
}
if ($lignes === '') {
    $lignes = vue('liste_vide', ['texte' => 'Personne n\'a encore signé pour toi.']);
}

$contenu = vue('signatures', [
    'html_entete' => vue('entete', ['titre' => 'Mes signatures']),
    'html_lignes' => $lignes,
    'html_pied'   => vue('pied'),
    'nb'          => count($signataires),
]);

echo page('Mes signatures', $contenu);
