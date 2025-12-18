<?php
header('Content-Type: application/json');
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_uuid']) || !isset($_POST['story_id']) || !isset($_POST['content'])) {
    echo json_encode(['success' => false, 'message' => 'Non autorisé ou données manquantes.']);
    exit;
}

$storyId = trim($_POST['story_id']);
$userId = $_SESSION['user_uuid'];
$content = trim($_POST['content']);

if ($content === '') {
    echo json_encode(['success' => false, 'message' => 'Le commentaire ne peut pas être vide.']);
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
    echo json_encode(['success' => false, 'message' => 'Erreur serveur.']);
}
?>
