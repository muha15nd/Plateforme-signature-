<?php
/**
 * Box de signature : le DUT1 tend son téléphone, le parrain / la Mame saisit SES identifiants.
 *   succès → accueil + confirmation · déjà signé (1062) → accueil + échec (RG6)
 *   identifiants faux → on reste dans la box avec l'erreur
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
// 2. UTILITAIRES
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

// =============================================================================
// 3. GABARITS
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
// 4. PAGE
// =============================================================================

$idFilleul = exiger_filleul();

$erreur = null;
$saisie = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $saisie = trim((string) ($_POST['nom_utilisateur'] ?? ''));
    $mdp    = (string) ($_POST['mot_de_passe'] ?? '');

    if (!csrf_valide($_POST['csrf'] ?? null)) {
        $erreur = 'La page a expiré. Recharge-la puis réessaie.';
    } elseif ($saisie === '' || $mdp === '') {
        $erreur = 'Renseigne ton nom d\'utilisateur et ton mot de passe.';
    } else {
        $st = $pdo->prepare('SELECT id_parrain, mot_de_passe, statut_compte FROM parrain WHERE nom_utilisateur = ?');
        $st->execute([$saisie]);
        $parrain = $st->fetch();

        if (!$parrain || !password_verify($mdp, $parrain['mot_de_passe'])) {
            $erreur = 'Nom d\'utilisateur ou mot de passe incorrect.';
        } elseif ($parrain['statut_compte'] !== 'actif') {
            $erreur = 'Ce compte est suspendu. Contacte l\'administrateur.';
        } else {
            try {
                // La clé primaire (id_parrain, id_filleul) interdit la double signature (RG6)
                $ins = $pdo->prepare('INSERT INTO signature (id_parrain, id_filleul) VALUES (?, ?)');
                $ins->execute([(int) $parrain['id_parrain'], $idFilleul]);
                flash('succes', 'Signature enregistrée. Merci !');
            } catch (PDOException $ex) {
                if ((int) ($ex->errorInfo[1] ?? 0) === 1062) {
                    flash('echec', 'Signature refusée : tu as déjà signé pour cette personne.');
                } else {
                    flash('echec', 'La signature a échoué. Réessaie dans un instant.');
                }
            }
            header('Location: accueil.php');
            exit;
        }
    }
}

$st = $pdo->prepare('SELECT prenom, nom FROM filleul WHERE id_filleul = ?');
$st->execute([$idFilleul]);
$dut1 = $st->fetch() ?: ['prenom' => '', 'nom' => ''];

$contenu = vue('signer', [
    'html_erreur' => $erreur ? vue('message', ['type' => 'echec', 'role' => 'alert', 'texte' => $erreur]) : '',
    'nom_dut1'    => trim($dut1['prenom'] . ' ' . $dut1['nom']),
    'csrf'        => csrf_token(),
    'saisie'      => $saisie,
]);

echo page('Signer', $contenu, [], 'page-box');
