<?php
header('Content-Type: application/json');
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_uuid']) || !isset($_SESSION['username'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

$userId = $_SESSION['user_uuid'];
$username = $_SESSION['username'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['comment_id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

$commentId = trim($_POST['comment_id']);
if (!preg_match('/^[0-9a-fA-F-\s]{36}$/', $commentId)) {
    echo json_encode(['success' => false, 'message' => 'Invalid comment identifier.']);
    exit;
}

$pdo = null;

function tableExists(PDO $pdo, string $tableName): bool {
    $q = $pdo->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t LIMIT 1");
    $q->execute([':t' => $tableName]);
    return (bool)$q->fetchColumn();
}

try {
    $pdo = getDBConnection();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Récupérer l'auteur du commentaire
    $stmt = $pdo->prepare("SELECT BIN_TO_UUID(IdUser) AS IdUser FROM Comment WHERE IdComment = UUID_TO_BIN(:id)");
    $stmt->execute([':id' => $commentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Comment not found.']);
        exit;
    }

    $isAdmin = ($username === 'Admin');
    if (!$isAdmin && $row['IdUser'] !== $userId) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'You can only delete your own comments.']);
        exit;
    }

    $pdo->beginTransaction();

    // Supprime les dépendances si la table existe encore
    if (tableExists($pdo, 'CommentReaction')) {
        $delReactions = $pdo->prepare("DELETE FROM CommentReaction WHERE IdComment = UUID_TO_BIN(:id)");
        $delReactions->execute([':id' => $commentId]);
    }

    $del = $pdo->prepare("DELETE FROM Comment WHERE IdComment = UUID_TO_BIN(:id)");
    $del->execute([':id' => $commentId]);

    if ($del->rowCount() > 0) {
        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Comment deleted.']);
    } else {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Error while deleting.']);
    }

} catch (PDOException $e) {
    if ($pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("DB Error in delete_comment.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error.']);
    // echo json_encode(['success' => false, 'message' => 'Server error.', 'debug' => $e->getMessage()]);
}
?>
