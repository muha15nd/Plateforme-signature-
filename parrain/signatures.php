<?php
/**
 * ÉQUIPE 4 – Mes signatures : liste des DUT1 pour lesquels j'ai signé ; signatures.php?id=12 = fiche d'un de ces DUT1.
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
function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

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

function date_fr(string $date): string
{
    return (new DateTime($date))->format('d/m/Y à H:i');
}

/** Lien WhatsApp à partir d'un numéro au format international. */
function lien_whatsapp(string $telephone): string
{
    return 'https://wa.me/' . preg_replace('/\D+/', '', $telephone);
}

/** Photo du DUT1 (colonne « photo ») sinon ses initiales. $classe : '' ou 'grand'. */
function avatar_html(array $f, string $classe = ''): string
{
    if (!empty($f['photo'])) {
        return vue('avatar_photo', ['src' => $f['photo'], 'classe' => $classe]);
    }
    return vue('avatar_initiales', [
        'classe'    => $classe,
        'initiales' => mb_strtoupper(mb_substr($f['prenom'], 0, 1) . mb_substr($f['nom'], 0, 1)),
    ]);
}

/** Lignes « DUT1 pour lesquels j'ai signé » (chaque ligne mène à sa fiche). */
function lignes_dut1(array $lignes): string
{
    $html = '';
    foreach ($lignes as $f) {
        $html .= vue('ligne_dut1', [
            'id'          => $f['id_filleul'],
            'html_avatar' => avatar_html($f),
            'nom'         => $f['prenom'] . ' ' . $f['nom'],
            'date'        => date_fr($f['date_signature']),
        ]);
    }
    return $html !== '' ? $html : vue('liste_vide', ['texte' => 'Tu n\'as encore signé pour aucun DUT1.']);
}

function requete_signatures(PDO $pdo, int $idParrain, int $limite = 0): array
{
    $sql = 'SELECT f.id_filleul, f.prenom, f.nom, f.photo, s.date_signature
            FROM signature s JOIN filleul f ON f.id_filleul = s.id_filleul
            WHERE s.id_parrain = ?
            ORDER BY s.date_signature DESC' . ($limite > 0 ? ' LIMIT ' . $limite : '');
    $st = $pdo->prepare($sql);
    $st->execute([$idParrain]);
    return $st->fetchAll();
}

// =============================================================================
// 3. GABARITS
// =============================================================================
// Le HTML vit dans equipe4.html. {{nom}} = valeur échappée, {{html_xxx}} = HTML prêt, inséré tel quel.
function gabarits(): array
{
    static $liste = null;
    if ($liste === null) {
        $liste = [];
        $source = file_get_contents(__DIR__ . '/equipe4.html');
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

function page(string $titre, string $contenu, array $scripts = []): string
{
    $balises = '';
    foreach ($scripts as $src) {
        $balises .= vue('script', ['src' => $src]);
    }
    return vue('layout', ['titre' => $titre, 'html_contenu' => $contenu, 'html_scripts' => $balises]);
}

// =============================================================================
// 4. ERREUR
// =============================================================================
function page_erreur(string $titre, string $texte, int $code = 400): void
{
    http_response_code($code);
    $contenu = vue('erreur', [
        'html_entete' => vue('entete', ['titre' => $titre]),
        'html_message' => vue('message', ['type' => 'echec', 'role' => 'alert', 'texte' => $texte]),
        'html_pied'   => vue('pied'),
    ]);
    echo page($titre, $contenu);
}

// =============================================================================
// 5. PAGE
// =============================================================================

if (($_SESSION['role'] ?? '') === 'filleul') {          // un DUT1 ouvre un lien réservé aux signataires
    page_erreur('Page réservée', 'Cette page est réservée aux parrains, marraines et Mames. Connecte-toi avec ton compte de signataire.', 403);
    exit;
}

$p = exiger_parrain($pdo);

// signatures.php?id=12 → fiche d'un DUT1 (réservée aux DUT1 pour lesquels CE signataire a signé)
if (isset($_GET['id'])) {
    $st = $pdo->prepare(
        'SELECT f.id_filleul, f.prenom, f.nom, f.telephone, f.photo, s.date_signature,
                (SELECT COUNT(*) FROM signature WHERE id_filleul = f.id_filleul) AS nb_recues
         FROM signature s JOIN filleul f ON f.id_filleul = s.id_filleul
         WHERE s.id_parrain = ? AND f.id_filleul = ?'
    );
    $st->execute([(int) $p['id_parrain'], (int) $_GET['id']]);
    $f = $st->fetch();
    if (!$f) {
        page_erreur('Fiche introuvable', 'Tu n\'as pas signé pour cette personne, ou elle n\'existe pas.', 404);
        exit;
    }

    $contenu = vue('fiche', [
        'html_entete'   => vue('entete', ['titre' => 'Fiche du DUT1']),
        'html_avatar'   => avatar_html($f, 'grand'),
        'html_pied'     => vue('pied'),
        'nom_complet'   => $f['prenom'] . ' ' . $f['nom'],
        'prenom'        => $f['prenom'],
        'nom'           => $f['nom'],
        'telephone'     => $f['telephone'],
        'lien_whatsapp' => lien_whatsapp($f['telephone']),
        'date'          => date_fr($f['date_signature']),
        'nb_recues'     => $f['nb_recues'],
    ]);
    echo page('Fiche du DUT1', $contenu);
    exit;
}

// Sinon : liste complète des DUT1 pour lesquels le signataire a signé
$lignes = requete_signatures($pdo, (int) $p['id_parrain']);
$n = count($lignes);
$contenu = vue('liste', [
    'html_entete' => vue('entete', ['titre' => 'Mes signatures']),
    'html_lignes' => lignes_dut1($lignes),
    'html_pied'   => vue('pied'),
    'titre_liste' => $n . ($n > 1 ? ' DUT1 signés' : ' DUT1 signé'),
]);
echo page('Mes signatures', $contenu);
