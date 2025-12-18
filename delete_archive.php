<?php
header('Content-Type: application/json');
session_start();
require_once 'config.php';

// Auth
if (!isset($_SESSION['user_uuid'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Non autorisé.']);
    exit;
}

$currentUser = $_SESSION['user_uuid'];
$currentUsername = $_SESSION['username'] ?? null;

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['archive_id'])) {
    echo json_encode(['success' => false, 'message' => 'Requête invalide.']);
    exit;
}

$archiveId = trim($_POST['archive_id']);
if (!preg_match('/^[0-9a-fA-F-]{36}$/', $archiveId)) {
    echo json_encode(['success' => false, 'message' => 'Identifiant invalide.']);
    exit;
}

try {
    $pdo = getDBConnection();

    // Récupérer l'archive et son auteur + picture
    $stmt = $pdo->prepare("SELECT BIN_TO_UUID(IdUser) AS owner_uuid, Picture FROM Archive WHERE BIN_TO_UUID(IdArchive) = :id");
    $stmt->execute([':id' => $archiveId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Archive introuvable.']);
        exit;
    }

    $ownerUuid = $row['owner_uuid'];
    $picturePath = $row['Picture'] ?? null;

    // Vérifier les permissions : auteur ou Admin
    $isAdmin = ($currentUsername !== null && $currentUsername === 'Admin');
    if (!$isAdmin && $ownerUuid !== $currentUser) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Permission refusée.']);
        exit;
    }

    // Supprimer l'enregistrement
    $del = $pdo->prepare("DELETE FROM Archive WHERE BIN_TO_UUID(IdArchive) = :id");
    $ok = $del->execute([':id' => $archiveId]);

    if ($ok && $del->rowCount() > 0) {
        // Supprimer le fichier image associé si présent et accessible
        if (!empty($picturePath)) {
            $fsPath = __DIR__ . '/' . ltrim($picturePath, '/\\');
            if (file_exists($fsPath) && is_writable($fsPath)) {
                @unlink($fsPath);
            }
        }
        echo json_encode(['success' => true, 'message' => 'Archive supprimée.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Échec de la suppression en base.']);
    }

} catch (\PDOException $e) {
    error_log("DB Error in delete_archive.php: " . $e->getMessage());
    $debug = $GLOBALS['DB_CONFIG']['debug'] ?? false;
    if ($debug) {
        echo json_encode(['success' => false, 'message' => 'Erreur BDD: '.$e->getMessage()]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Erreur serveur.']);
    }
}
?>
