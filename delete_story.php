
<?php
header('Content-Type: application/json');
session_start();
require_once 'config.php';

/**
 * Active en DEV uniquement !
 * Mets à false en prod.
 */
$APP_DEBUG = true;

/**
 * Pour capturer aussi les erreurs fatales (qui ne passent pas dans catch PDOException).
 */
register_shutdown_function(function() use ($APP_DEBUG) {
    $err = error_get_last();
    if ($err !== null) {
        // Évite de casser une réponse JSON déjà envoyée
        if (!headers_sent()) {
            header('Content-Type: application/json');
            http_response_code(500);
        }
        $payload = [
            'success' => false,
            'message' => 'Fatal error.',
        ];
        if ($APP_DEBUG) {
            $payload['debug'] = $err;
        }
        echo json_encode($payload);
    }
});

/**
 * Helper: vérifie si une table existe.
 * Note: si information_schema est bloqué chez toi, ça peut planter -> on catch aussi.
 */
function tableExists(PDO $pdo, string $tableName): bool {
    $q = $pdo->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t LIMIT 1");
    $q->execute([':t' => $tableName]);
    return (bool)$q->fetchColumn();
}

// Vérification session
if (!isset($_SESSION['user_uuid']) || !isset($_SESSION['username'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

$userUuid = $_SESSION['user_uuid'];
$username = $_SESSION['username'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['story_id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

$storyId = trim($_POST['story_id']);
if (!preg_match('/^[0-9a-fA-F-]{36}$/', $storyId)) {
    echo json_encode(['success' => false, 'message' => 'Invalid story identifier.']);
    exit;
}

$pdo = null;
$step = 'init';

try {
    $step = 'db_connect';
    $pdo = getDBConnection();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $step = 'select_story';
    $stmt = $pdo->prepare("SELECT BIN_TO_UUID(IdUser) AS author_uuid, Picture FROM Story WHERE IdStory = UUID_TO_BIN(:id)");
    $stmt->execute([':id' => $storyId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Story not found.']);
        exit;
    }

    $authorUuid = $row['author_uuid'];
    $picture = $row['Picture'] ?? null;

    $isAdmin = ($username === 'Admin');
    if (!$isAdmin && $authorUuid !== $userUuid) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied. You cannot delete this story.']);
        exit;
    }

    $step = 'begin_transaction';
    $pdo->beginTransaction();

    // Attention: tableExists utilise information_schema -> peut être bloqué
    $step = 'check_tables';
    $hasCommentReaction = false;
    $hasComment = false;
    $hasStoryReaction = false;
    $hasStoryTag = false;

    try {
        $hasCommentReaction = tableExists($pdo, 'CommentReaction');
        $hasComment = tableExists($pdo, 'Comment');
        $hasStoryReaction = tableExists($pdo, 'StoryReaction');
        $hasStoryTag = tableExists($pdo, 'StoryTag');
    } catch (Throwable $t) {
        // Si information_schema est inaccessible, on tente quand même les DELETE, et on catch si la table n'existe pas.
        $hasCommentReaction = $hasComment = $hasStoryReaction = $hasStoryTag = true;
    }

    // 1) CommentReaction via JOIN
    if ($hasCommentReaction) {
        $step = 'delete_comment_reactions';
        // Multi-table delete (MySQL). Si ton SGBD/driver ne supporte pas, ça plantera => debug te le dira.
        $sql = "
            DELETE CR
            FROM CommentReaction CR
            JOIN Comment C ON CR.IdComment = C.IdComment
            WHERE C.IdStory = UUID_TO_BIN(:id)
        ";
        $pdo->prepare($sql)->execute([':id' => $storyId]);
    }

    // 2) Comments
    if ($hasComment) {
        $step = 'delete_comments';
        $pdo->prepare("DELETE FROM Comment WHERE IdStory = UUID_TO_BIN(:id)")
            ->execute([':id' => $storyId]);
    }

    // 3) StoryReaction
    if ($hasStoryReaction) {
        $step = 'delete_story_reactions';
        $pdo->prepare("DELETE FROM StoryReaction WHERE IdStory = UUID_TO_BIN(:id)")
            ->execute([':id' => $storyId]);
    }

    // 4) StoryTag
    if ($hasStoryTag) {
        $step = 'delete_story_tags';
        $pdo->prepare("DELETE FROM StoryTag WHERE IdStory = UUID_TO_BIN(:id)")
            ->execute([':id' => $storyId]);
    }

    // 5) Story
    $step = 'delete_story';
    $delStory = $pdo->prepare("DELETE FROM Story WHERE IdStory = UUID_TO_BIN(:id)");
    $delStory->execute([':id' => $storyId]);

    if ($delStory->rowCount() === 0) {
        $step = 'rollback_no_row';
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Unable to delete the story.']);
        exit;
    }

    $step = 'commit';
    $pdo->commit();

    // Supprime image locale
    $step = 'delete_picture';
    if (!empty($picture) && stripos($picture, 'http://') !== 0 && stripos($picture, 'https://') !== 0) {
        $picRel = ltrim($picture, "/\\");
        $picFs = __DIR__ . '/' . $picRel;
        if (file_exists($picFs)) {
            @unlink($picFs);
        }
    }

    echo json_encode(['success' => true, 'message' => 'Story deleted (and related data removed).']);
    //Débogage
} catch (Throwable $e) {
    if ($pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log("Error in delete_story.php at step={$step}: " . $e->getMessage());

    http_response_code(500);
    $payload = [
        'success' => false,
        'message' => 'Server error.',
        'step'    => $step,
    ];
    if ($APP_DEBUG) {
        $payload['debug'] = [
            'type' => get_class($e),
            'msg'  => $e->getMessage(),
            'code' => $e->getCode(),
        ];
    }
    echo json_encode($payload);
}
?>
