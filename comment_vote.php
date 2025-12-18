<?php
header('Content-Type: application/json');
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_uuid'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Non autorisé.']);
    exit;
}

$userId = $_SESSION['user_uuid'];
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['comment_id']) || !isset($_POST['vote'])) {
    echo json_encode(['success' => false, 'message' => 'Requête invalide.']);
    exit;
}

$commentId = trim($_POST['comment_id']);
$vote = $_POST['vote']; // 'up', 'down', ou 'none'
if (!preg_match('/^[0-9a-fA-F-]{36}$/', $commentId) || !in_array($vote, ['up','down','none'])) {
    echo json_encode(['success' => false, 'message' => 'Paramètres invalides.']);
    exit;
}

try {
    $pdo = getDBConnection();
    if ($vote === 'none') {
        $del = $pdo->prepare("DELETE FROM CommentReaction WHERE IdComment = UUID_TO_BIN(:comment_id) AND IdUser = UUID_TO_BIN(:user_id)");
        $del->execute([':comment_id' => $commentId, ':user_id' => $userId]);
    } else {
        $type = ($vote === 'up') ? 1 : 0;
        $stmt = $pdo->prepare("SELECT IdReaction FROM CommentReaction WHERE IdComment = UUID_TO_BIN(:comment_id) AND IdUser = UUID_TO_BIN(:user_id)");
        $stmt->execute([':comment_id' => $commentId, ':user_id' => $userId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $upd = $pdo->prepare("UPDATE CommentReaction SET Type = :type WHERE IdComment = UUID_TO_BIN(:comment_id) AND IdUser = UUID_TO_BIN(:user_id)");
            $upd->bindValue(':type', $type, PDO::PARAM_INT);
            $upd->bindValue(':comment_id', $commentId);
            $upd->bindValue(':user_id', $userId);
            $upd->execute();
        } else {
            $ins = $pdo->prepare("INSERT INTO CommentReaction (IdReaction, Type, IdUser, IdComment) VALUES (UUID_TO_BIN(UUID()), :type, UUID_TO_BIN(:user_id), UUID_TO_BIN(:comment_id))");
            $ins->bindValue(':type', $type, PDO::PARAM_INT);
            $ins->bindValue(':user_id', $userId);
            $ins->bindValue(':comment_id', $commentId);
            $ins->execute();
        }
    }

    // Retourner le nouveau score
    $scoreStmt = $pdo->prepare("
        SELECT 
            COUNT(DISTINCT CASE WHEN Type=1 THEN IdUser END) AS upvotes,
            COUNT(DISTINCT CASE WHEN Type=0 THEN IdUser END) AS downvotes
        FROM CommentReaction WHERE IdComment = UUID_TO_BIN(:comment_id)
    ");
    $scoreStmt->execute([':comment_id' => $commentId]);
    $score = $scoreStmt->fetch(PDO::FETCH_ASSOC);

    // Déterminer le vote actuel de l'utilisateur
    $userVote = null;
    $voteStmt = $pdo->prepare("SELECT Type FROM CommentReaction WHERE IdComment = UUID_TO_BIN(:comment_id) AND IdUser = UUID_TO_BIN(:user_id) LIMIT 1");
    $voteStmt->execute([':comment_id' => $commentId, ':user_id' => $userId]);
    $voteRow = $voteStmt->fetch(PDO::FETCH_ASSOC);
    if ($voteRow !== false) $userVote = ($voteRow['Type'] == 1 ? 'up' : 'down');

    echo json_encode([
        'success' => true,
        'upvotes' => (int)($score['upvotes'] ?? 0),
        'downvotes' => (int)($score['downvotes'] ?? 0),
        'user_vote' => $userVote
    ]);
} catch (\PDOException $e) {
    error_log("DB Error in comment_vote.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erreur serveur.']);
}
?>
