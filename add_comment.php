
<?php
/*
    Fichier : add_comment.php
    Rôle : Web service pour ajouter un commentaire à une story.
    Entrées POST : story_id, contentComment ; utilise la session pour l'utilisateur connecté.
    Sortie : JSON { success: bool, message?: string }
*/
header('Content-Type: application/json');
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_uuid']) || !isset($_POST['story_id']) || !isset($_POST['contentComment'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized or missing data.']);
    exit;
}

$storyId = trim($_POST['story_id']);
$userId  = $_SESSION['user_uuid'];
$content = trim($_POST['contentComment']);

/**
 * (Optionnel mais recommandé) : vérifier que story_id est bien un UUID
 */
if (!preg_match('/^[0-9a-fA-F-]{36}$/', $storyId)) {
    echo json_encode(['success' => false, 'message' => 'Invalid story identifier.']);
    exit;
}

/**
 * Regex demandée (version safe) :
 * - le '-' est placé à la fin pour éviter toute ambiguïté
 * - longueur 1 à 60
 */
$commentRegex = '/^[A-Za-z0-9!?,; :_-]{1,60}$/';

if ($content === '') {
    echo json_encode(['success' => false, 'message' => 'Comment cannot be empty.']);
    exit;
}

if (!preg_match($commentRegex, $content)) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid comment. Allowed: A-Z a-z 0-9 and ! ? , ; : - _ (1-60 chars).'
    ]);
    exit;
}

try {
    $pdo = getDBConnection();

    $stmt = $pdo->prepare("
        INSERT INTO Comment (IdComment, IdStory, IdUser, Content)
        VALUES (UUID_TO_BIN(UUID()), UUID_TO_BIN(:story_id), UUID_TO_BIN(:user_id), :content)
    ");

    $stmt->execute([
        ':story_id' => $storyId,
        ':user_id'  => $userId,
        ':content'  => $content
    ]);

    echo json_encode(['success' => true]);

} catch (PDOException $e) {
    error_log('DB Error in add_comment.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error.']);
}
?>
