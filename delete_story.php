<?php
header('Content-Type: application/json');
session_start();
require_once 'config.php';

// Vérification session
if (!isset($_SESSION['user_uuid']) || !isset($_SESSION['username'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Non autorisé.']);
    exit;
}

$userUuid = $_SESSION['user_uuid'];
$username = $_SESSION['username'];

// Requête POST attendue
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['story_id'])) {
    echo json_encode(['success' => false, 'message' => 'Requête invalide.']);
    exit;
}

$storyId = trim($_POST['story_id']);
if (!preg_match('/^[0-9a-fA-F-]{36}$/', $storyId)) {
    echo json_encode(['success' => false, 'message' => 'Identifiant de story invalide.']);
    exit;
}

try {
    $pdo = getDBConnection();

    // Récupérer l'auteur (IdUser) et Picture de la story
    $stmt = $pdo->prepare("SELECT BIN_TO_UUID(IdUser) AS author_uuid, Picture FROM Story WHERE BIN_TO_UUID(IdStory) = :id");
    $stmt->execute([':id' => $storyId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Story introuvable.']);
        exit;
    }

    $authorUuid = $row['author_uuid'];
    $picture = $row['Picture'] ?? null;

    // Permission : auteur OU Admin
    $isAdmin = ($username === 'Admin');
    if (!$isAdmin && $authorUuid !== $userUuid) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Accès refusé. Vous ne pouvez pas supprimer cette story.']);
        exit;
    }

    // Suppression en transaction
    $pdo->beginTransaction();

    $delTags = $pdo->prepare("DELETE FROM StoryTag WHERE IdStory = UUID_TO_BIN(:id)");
    $delTags->execute([':id' => $storyId]);

    $delStory = $pdo->prepare("DELETE FROM Story WHERE IdStory = UUID_TO_BIN(:id)");
    $delStory->execute([':id' => $storyId]);

    if ($delStory->rowCount() === 0) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Impossible de supprimer la story.']);
        exit;
    }

    $pdo->commit();

    // Supprimer l'image si chemin local (non http(s))
    if (!empty($picture) && stripos($picture, 'http://') !== 0 && stripos($picture, 'https://') !== 0) {
        $picRel = ltrim($picture, '/\\');
        $picFs = __DIR__ . '/' . $picRel;
        if (file_exists($picFs)) {
            @unlink($picFs);
        }
    }

    echo json_encode(['success' => true, 'message' => 'Story supprimée.']);

} catch (\PDOException $e) {
    if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
    error_log("DB Error in delete_story.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erreur serveur.']);
}
?>
