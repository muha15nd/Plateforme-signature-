<?php
/**
 * ÉQUIPE 4 – Accueil du signataire (parrain / marraine / Mame) : nombre de signatures, dernières signatures, accès scanner et téléchargement.
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

function flash_lire(): ?array
{
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

function date_fr(string $date): string
{
    return (new DateTime($date))->format('d/m/Y à H:i');
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

$st = $pdo->prepare('SELECT COUNT(*) FROM signature WHERE id_parrain = ?');
$st->execute([(int) $p['id_parrain']]);
$nb = (int) $st->fetchColumn();                      // calculé à chaque affichage, jamais stocké

$flash = flash_lire();
$contenu = vue('accueil', [
    'html_entete'    => vue('entete', ['titre' => 'Accueil']),
    'html_flash'     => $flash ? vue('message', ['type' => $flash['type'], 'role' => 'status', 'texte' => $flash['message']]) : '',
    'html_dernieres' => lignes_dut1(requete_signatures($pdo, (int) $p['id_parrain'], 5)),
    'html_pied'      => vue('pied'),
    'prenom'         => $p['prenom'],
    'type'           => $p['type_parrain'],
    'nb'             => $nb,
    'phrase'         => $nb === 0 ? 'Tu n\'as pas encore signé pour un DUT1.' : 'Merci pour ton engagement !',
]);
echo page('Accueil', $contenu);
