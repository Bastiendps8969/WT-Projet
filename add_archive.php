<?php
// add_archive.php - Ajoute une nouvelle entrée dans la table Archive (Web Service JSON)

header('Content-Type: application/json');
session_start();
require_once 'config.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || !isset($_SESSION['user_uuid'])) {
    echo json_encode(['success' => false, 'message' => 'Authentification requise pour archiver.']);
    exit;
}

$idUser = $_SESSION['user_uuid'];

$uploadWebDir = 'uploads/';
$uploadFsDir = __DIR__ . '/' . $uploadWebDir;
if (!is_dir($uploadFsDir)) mkdir($uploadFsDir, 0777, true);

$picturePath = null;
$pictureFsPath = null;
$pdo = null;

try {
    $title = trim($_POST['title'] ?? '');
    $content = trim($_POST['content'] ?? '');
    // tags sont ignorés pour Archive table (pas de relation fournie)
    if (empty($title) || empty($content)) throw new Exception('Titre et contenu sont obligatoires.');

    // gestion image identique à add_story
    if (isset($_FILES['picture']) && $_FILES['picture']['error'] === UPLOAD_ERR_OK) {
        $fileTmpPath = $_FILES['picture']['tmp_name'];
        $fileName = basename($_FILES['picture']['name']);
        $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $allowedFileTypes = ['jpg','jpeg','png','gif'];
        if (!in_array($fileExtension, $allowedFileTypes)) throw new Exception('Format de fichier non supporté. (JPG, PNG, GIF)');
        $newFileName = uniqid('archive_') . '.' . $fileExtension;
        $destPathFs = $uploadFsDir . $newFileName;
        $destPathWeb = $uploadWebDir . $newFileName;
        if (move_uploaded_file($fileTmpPath, $destPathFs)) {
            $picturePath = $destPathWeb;
            $pictureFsPath = $destPathFs;
        } else {
            error_log("add_archive.php - move_uploaded_file failed to {$destPathFs}");
        }
    }

    $pdo = getDBConnection();
    $pdo->beginTransaction();

    $sql = "INSERT INTO Archive (IdArchive, Title, Content, Picture, IdUser) VALUES (UUID_TO_BIN(UUID()), :title, :content, :picture, UUID_TO_BIN(:idUser))";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':title', $title, PDO::PARAM_STR);
    if ($picturePath !== null) $stmt->bindValue(':picture', $picturePath, PDO::PARAM_STR);
    else $stmt->bindValue(':picture', null, PDO::PARAM_NULL);
    $stmt->bindValue(':idUser', $idUser, PDO::PARAM_STR);
    $stmt->execute();

    // récupérer l'IdArchive inséré
    $stmtGet = $pdo->prepare("SELECT BIN_TO_UUID(IdArchive) FROM Archive WHERE IdUser = UUID_TO_BIN(?) ORDER BY CreationDate DESC LIMIT 1");
    $stmtGet->execute([$idUser]);
    $newArchiveId = $stmtGet->fetchColumn();

    $pdo->commit();

    echo json_encode(['success' => true, 'message' => 'Archive créée avec succès.', 'archive_id' => $newArchiveId]);

} catch (Exception $e) {
    if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
    if (!empty($pictureFsPath) && file_exists($pictureFsPath)) unlink($pictureFsPath);
    error_log("Error in add_archive.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
