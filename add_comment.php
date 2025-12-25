<?php
/*
    Fichier : add_comment.php
    Rôle : Web service pour ajouter un commentaire à une story.
    Entrées POST : `story_id`, `content` ; utilise la session pour l'utilisateur connecté.
    Sortie : JSON { success: bool, message?: string }
    Commentaires : Toutes les validations importantes sont effectuées côté serveur.
*/
header('Content-Type: application/json');
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_uuid']) || !isset($_POST['story_id']) || !isset($_POST['contentComment'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized or missing data.']);
    exit;
}

$storyId = trim($_POST['story_id']);
$userId = $_SESSION['user_uuid'];
$content = trim($_POST['contentComment']);

if ($content === '') {
    echo json_encode(['success' => false, 'message' => 'Comment cannot be empty.']);
    exit;
}

try {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("INSERT INTO Comment (IdComment, IdStory, IdUser, Content) VALUES (UUID_TO_BIN(UUID()), UUID_TO_BIN(:story_id), UUID_TO_BIN(:user_id), :content)");
    $stmt->execute([
        ':story_id' => $storyId,
        ':user_id' => $userId,
        ':content' => $content
    ]);
    echo json_encode(['success' => true]);
} catch (\PDOException $e) {
    error_log("DB Error in add_comment.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error.']);
}
?>
