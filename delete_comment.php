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
    if (!preg_match('/^[0-9a-fA-F-]{36}$/', $commentId)) {
    echo json_encode(['success' => false, 'message' => 'Invalid comment identifier.']);
    exit;
}

try {
    $pdo = getDBConnection();
    // Récupérer l'auteur du commentaire
    $stmt = $pdo->prepare("SELECT BIN_TO_UUID(IdUser) AS IdUser FROM Comment WHERE BIN_TO_UUID(IdComment) = :id");
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

    $del = $pdo->prepare("DELETE FROM Comment WHERE BIN_TO_UUID(IdComment) = :id");
    $ok = $del->execute([':id' => $commentId]);

    if ($ok && $del->rowCount() > 0) {
        echo json_encode(['success' => true, 'message' => 'Comment deleted.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error while deleting.']);
    }
} catch (\PDOException $e) {
    error_log("DB Error in delete_comment.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error.']);
}
?>
