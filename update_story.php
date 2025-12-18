<?php
header('Content-Type: application/json');
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_uuid'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Non autorisé.']);
    exit;
}

$userUuid = $_SESSION['user_uuid'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['story_id']) || !isset($_POST['title']) || !isset($_POST['content'])) {
    echo json_encode(['success' => false, 'message' => 'Requête invalide.']);
    exit;
}

$storyId = trim($_POST['story_id']);
$title = trim($_POST['title']);
$content = trim($_POST['content']);

if (!preg_match('/^[0-9a-fA-F-]{36}$/', $storyId)) {
    echo json_encode(['success' => false, 'message' => 'Identifiant de story invalide.']);
    exit;
}

if ($title === '' || $content === '') {
    echo json_encode(['success' => false, 'message' => 'Titre et contenu requis.']);
    exit;
}

try {
    $pdo = getDBConnection();

    // Vérifier propriété
    $stmt = $pdo->prepare("SELECT BIN_TO_UUID(IdUser) AS author_uuid FROM Story WHERE BIN_TO_UUID(IdStory) = :id");
    $stmt->execute([':id' => $storyId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Story introuvable.']);
        exit;
    }

    if ($row['author_uuid'] !== $userUuid) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Vous n\'êtes pas l\'auteur de cette story.']);
        exit;
    }

    // Mettre à jour Title et Content
    $upd = $pdo->prepare("UPDATE Story SET Title = :title, Content = :content WHERE BIN_TO_UUID(IdStory) = :id");
    $ok = $upd->execute([':title' => $title, ':content' => $content, ':id' => $storyId]);

    if ($ok) {
        echo json_encode(['success' => true, 'message' => 'Story mise à jour.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Erreur lors de la mise à jour en base.']);
    }

} catch (\PDOException $e) {
    error_log("DB Error in update_story.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erreur serveur.']);
}
?>
