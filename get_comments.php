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

$currentUserId   = $_SESSION['user_uuid'] ?? null;   // UUID string
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
    $comments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Ajout du champ can_delete pour chaque commentaire
    foreach ($comments as &$c) {
        $c['can_delete'] = (
            ($currentUserId && isset($c['IdUser']) && $c['IdUser'] === $currentUserId)
            || ($currentUsername && $currentUsername === 'Admin')
        );
    }

    echo json_encode(['success' => true, 'comments' => $comments]);

} catch (PDOException $e) {
    error_log("DB Error in get_comments.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error.']);
}
?>
