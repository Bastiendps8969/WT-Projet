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
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['story_id']) || !isset($_POST['vote'])) {
    echo json_encode(['success' => false, 'message' => 'Requête invalide.']);
    exit;
}

$storyId = trim($_POST['story_id']);
$vote = $_POST['vote']; // 'up', 'down', ou 'none'
if (!preg_match('/^[0-9a-fA-F-]{36}$/', $storyId) || !in_array($vote, ['up','down','none'])) {
    echo json_encode(['success' => false, 'message' => 'Paramètres invalides.']);
    exit;
}

try {
    $pdo = getDBConnection();
    if ($vote === 'none') {
        // Suppression du vote
        $del = $pdo->prepare("DELETE FROM StoryReaction WHERE IdStory = UUID_TO_BIN(:story_id) AND IdUser = UUID_TO_BIN(:user_id)");
        $del->execute([':story_id' => $storyId, ':user_id' => $userId]);
    } else {
        // Correction : forcer le type à int pour éviter tout bug d'encodage
        $type = ($vote === 'up') ? 1 : 0;
        // Vérifier si le vote existe déjà
        $stmt = $pdo->prepare("SELECT IdReaction FROM StoryReaction WHERE IdStory = UUID_TO_BIN(:story_id) AND IdUser = UUID_TO_BIN(:user_id)");
        $stmt->execute([':story_id' => $storyId, ':user_id' => $userId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            // Mettre à jour le vote (type doit être un entier 0 ou 1)
            $upd = $pdo->prepare("UPDATE StoryReaction SET Type = :type WHERE IdStory = UUID_TO_BIN(:story_id) AND IdUser = UUID_TO_BIN(:user_id)");
            $upd->bindValue(':type', $type, PDO::PARAM_INT);
            $upd->bindValue(':story_id', $storyId);
            $upd->bindValue(':user_id', $userId);
            $upd->execute();
        } else {
            // Nouveau vote
            $ins = $pdo->prepare("INSERT INTO StoryReaction (IdReaction, Type, IdUser, IdStory) VALUES (UUID_TO_BIN(UUID()), :type, UUID_TO_BIN(:user_id), UUID_TO_BIN(:story_id))");
            $ins->bindValue(':type', $type, PDO::PARAM_INT);
            $ins->bindValue(':user_id', $userId);
            $ins->bindValue(':story_id', $storyId);
            $ins->execute();
        }
    }

    // Retourner le nouveau score
    $scoreStmt = $pdo->prepare("
        SELECT 
            SUM(CASE WHEN Type=1 THEN 1 ELSE 0 END) AS upvotes,
            SUM(CASE WHEN Type=0 THEN 1 ELSE 0 END) AS downvotes
        FROM StoryReaction WHERE IdStory = UUID_TO_BIN(:story_id)
    ");
    $scoreStmt->execute([':story_id' => $storyId]);
    $score = $scoreStmt->fetch(PDO::FETCH_ASSOC);

    // Déterminer le vote actuel de l'utilisateur
    $userVote = null;
    $voteStmt = $pdo->prepare("SELECT Type FROM StoryReaction WHERE IdStory = UUID_TO_BIN(:story_id) AND IdUser = UUID_TO_BIN(:user_id) LIMIT 1");
    $voteStmt->execute([':story_id' => $storyId, ':user_id' => $userId]);
    $voteRow = $voteStmt->fetch(PDO::FETCH_ASSOC);
    if ($voteRow !== false) $userVote = ($voteRow['Type'] == 1 ? 'up' : 'down');

    echo json_encode([
        'success' => true,
        'upvotes' => (int)($score['upvotes'] ?? 0),
        'downvotes' => (int)($score['downvotes'] ?? 0),
        'score' => ((int)($score['upvotes'] ?? 0)) - ((int)($score['downvotes'] ?? 0)),
        'user_vote' => $userVote
    ]);
} catch (\PDOException $e) {
    error_log("DB Error in story_vote.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erreur serveur.']);
}
?>
