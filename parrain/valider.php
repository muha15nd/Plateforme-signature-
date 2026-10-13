<?php
/**
 * ÉQUIPE 4 – CIBLE DU QR CODE d'un DUT1 : GET = carte du DUT1 + bouton « Confirmer », POST = enregistre la signature (RG6).
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

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_valide(?string $jeton): bool
{
    return is_string($jeton) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $jeton);
}

/** Message affiché une seule fois après une redirection. $type : 'succes' ou 'echec'. */
function flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
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

$idFilleul = (int) ($_GET['id_filleul'] ?? 0);
$st = $pdo->prepare('SELECT id_filleul, prenom, nom, photo FROM filleul WHERE id_filleul = ? AND statut_compte = \'actif\'');
$st->execute([$idFilleul]);
$dut1 = $st->fetch();
if (!$dut1) {
    page_erreur('QR code invalide', 'Ce code ne correspond à aucun DUT1 actif.', 404);
    exit;
}
$nomDut1 = $dut1['prenom'] . ' ' . $dut1['nom'];

// POST : enregistre la signature (clé primaire composée : 2e tentative = erreur 1062, RG6)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valide($_POST['csrf'] ?? null)) {
        flash('echec', 'La page a expiré. Scanne de nouveau le QR code.');
    } else {
        try {
            $ins = $pdo->prepare('INSERT INTO signature (id_parrain, id_filleul) VALUES (?, ?)');
            $ins->execute([(int) $p['id_parrain'], $idFilleul]);
            flash('succes', 'Signature enregistrée pour ' . $nomDut1 . '. Merci !');
        } catch (PDOException $ex) {
            if ((int) ($ex->errorInfo[1] ?? 0) === 1062) {
                flash('echec', 'Signature refusée : tu as déjà signé pour ' . $nomDut1 . '.');
            } else {
                flash('echec', 'La signature a échoué. Réessaie dans un instant.');
            }
        }
    }
    header('Location: accueil.php');
    exit;
}

// GET : carte du DUT1 + bouton « Confirmer ma signature » (évite une signature par simple lien)
$st = $pdo->prepare('SELECT COUNT(*) FROM signature WHERE id_parrain = ? AND id_filleul = ?');
$st->execute([(int) $p['id_parrain'], $idFilleul]);
$dejaSigne = (int) $st->fetchColumn() > 0;

$action = $dejaSigne
    ? vue('message', ['type' => 'echec', 'role' => 'status', 'texte' => 'Tu as déjà signé pour cette personne.'])
      . vue('lien_retour')
    : vue('formulaire_confirmation', ['id' => $idFilleul, 'csrf' => csrf_token()]);

$contenu = vue('confirmer', [
    'html_entete' => vue('entete', ['titre' => 'Confirmer la signature']),
    'html_avatar' => avatar_html($dut1, 'grand'),
    'html_action' => $action,
    'html_pied'   => vue('pied'),
    'nom_dut1'    => $nomDut1,
]);
echo page('Confirmer la signature', $contenu);
