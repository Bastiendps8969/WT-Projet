<?php
/*
    Fichier : get_comments.php
    Rôle : Retourne les commentaires d'une story donnée en JSON.
    Entrées GET : `story_id` (UUID string) attendu.
    Sortie : JSON { success: bool, comments: [...] }
*/
header('Content-Type: application/json');
session_start();
require_once 'config.php';

$currentUserId = $_SESSION['user_uuid'] ?? null;
$currentUsername = $_SESSION['username'] ?? null;

if (!isset($_GET['story_id'])) {
    echo json_encode(['success' => false, 'message' => 'Missing story ID.']);
    exit;
}

$storyId = trim($_GET['story_id']);

try {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        SELECT 
            BIN_TO_UUID(C.IdComment) AS IdComment,
            C.Content,
            C.CreationDate,
            U.Username,
            BIN_TO_UUID(U.IdUser) AS IdUser
        FROM Comment C
        JOIN User U ON C.IdUser = U.IdUser
        WHERE BIN_TO_UUID(C.IdStory) = :story_id
        ORDER BY C.CreationDate ASC
    ");
    $stmt->execute([':story_id' => $storyId]);
    $comments = $stmt->fetchAll();

    // Ajout du champ can_delete pour chaque commentaire
    foreach ($comments as &$c) {
        $c['can_delete'] = (
            ($currentUserId && isset($c['IdUser']) && $c['IdUser'] === $currentUserId)
            || ($currentUsername && $currentUsername === 'Admin')
        );

        // Ajout : score et vote utilisateur pour chaque commentaire
        $scoreStmt = $pdo->prepare("
            SELECT 
                COUNT(DISTINCT CASE WHEN Type=1 THEN IdUser END) AS upvotes,
                COUNT(DISTINCT CASE WHEN Type=0 THEN IdUser END) AS downvotes
            FROM CommentReaction WHERE IdComment = UUID_TO_BIN(:idComment)
        ");
        $scoreStmt->execute([':idComment' => $c['IdComment']]);
        $score = $scoreStmt->fetch(PDO::FETCH_ASSOC);
        $c['upvotes'] = (int)($score['upvotes'] ?? 0);
        $c['downvotes'] = (int)($score['downvotes'] ?? 0);

        // Vote utilisateur courant
        $userVote = null;
        if ($currentUserId) {
            $voteStmt = $pdo->prepare("SELECT Type FROM CommentReaction WHERE IdComment = UUID_TO_BIN(:idComment) AND IdUser = UUID_TO_BIN(:idUser) LIMIT 1");
            $voteStmt->execute([':idComment' => $c['IdComment'], ':idUser' => $currentUserId]);
            $voteRow = $voteStmt->fetch(PDO::FETCH_ASSOC);
            if ($voteRow !== false) $userVote = ($voteRow['Type'] == 1 ? 'up' : 'down');
        }
        $c['user_vote'] = $userVote;
    }

    echo json_encode(['success' => true, 'comments' => $comments]);
} catch (\PDOException $e) {
    error_log("DB Error in get_comments.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error.']);
}
?>
