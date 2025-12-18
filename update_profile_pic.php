<?php
// update_profile_pic.php - Gère le téléchargement et la mise à jour de la photo de profil

header('Content-Type: application/json');
session_start();

// Inclure le fichier de configuration de la base de données
require_once 'config.php';

// --- VÉRIFICATION DE LA SESSION ---
if (!isset($_SESSION['user_uuid'])) {
    http_response_code(401); 
    echo json_encode(['success' => false, 'message' => 'Non autorisé. Veuillez vous reconnecter.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['new_profile_pic'])) {
    echo json_encode(['success' => false, 'message' => 'Requête invalide ou fichier manquant.']);
    exit;
}

$user_id = $_SESSION['user_uuid']; // use UUID string
$file = $_FILES['new_profile_pic'];

// Configuration du dossier de destination et extensions autorisées
$upload_web_dir = 'uploads/profile_pics/';
$upload_fs_dir = __DIR__ . '/' . $upload_web_dir;
$allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
$max_size = 5 * 1024 * 1024; // 5 Mo

// 1. Vérifications de base du fichier
if ($file['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'Erreur lors du téléchargement du fichier. Code: ' . $file['error']]);
    exit;
}
if ($file['size'] > $max_size) {
    echo json_encode(['success' => false, 'message' => 'Le fichier est trop volumineux (max 5Mo).']);
    exit;
}

// Vérifier que le fichier est bien une image
$imageInfo = @getimagesize($file['tmp_name']);
if ($imageInfo === false) {
    echo json_encode(['success' => false, 'message' => 'Le fichier téléchargé n\'est pas une image valide.']);
    exit;
}

$file_info = pathinfo($file['name']);
$file_extension = strtolower($file_info['extension']);

if (!in_array($file_extension, $allowed_extensions)) {
    echo json_encode(['success' => false, 'message' => 'Type de fichier non autorisé.']);
    exit;
}

// 2. Définition du nom de fichier sécurisé
// Nettoyer l'UUID pour créer un nom de fichier sûr (retire caractères non-alphanumériques sauf - et _)
$safe_user_id = preg_replace('/[^a-zA-Z0-9_-]/', '', $user_id);
if (empty($safe_user_id)) {
    // fallback si nettoyage échoue
    $safe_user_id = uniqid('user_');
}
$new_file_name = $safe_user_id . '.' . $file_extension;
$destination_fs = $upload_fs_dir . $new_file_name;   // fichier absolu
$destination_web = $upload_web_dir . $new_file_name; // chemin à stocker en DB

// S'assurer que le répertoire de destination existe
if (!is_dir($upload_fs_dir)) {
    mkdir($upload_fs_dir, 0777, true);
}

// 3. Déplacement du fichier temporaire vers la destination permanente
if (move_uploaded_file($file['tmp_name'], $destination_fs)) {
    
    // Le chemin à enregistrer dans la base de données (chemin web relatif)
    $db_path = $destination_web; 

    try {
        $pdo = getDBConnection();

        // Vérifier sommairement la forme de l'UUID en session (debug utile)
        if (!preg_match('/^[0-9a-fA-F]{8}\-[0-9a-fA-F]{4}\-[0-9a-fA-F]{4}\-[0-9a-fA-F]{4}\-[0-9a-fA-F]{12}$/', $user_id)) {
            error_log("update_profile_pic.php - session user_uuid appears invalid: " . var_export($user_id, true));
        }

        // 4. MISE À JOUR EN BASE DE DONNÉES (comparer via BIN_TO_UUID pour éviter UUID_TO_BIN sur paramètre)
        $stmt = $pdo->prepare("UPDATE User SET Picture = :pic_path WHERE BIN_TO_UUID(IdUser) = :user_id");
        $success = $stmt->execute([':pic_path' => $db_path, ':user_id' => $user_id]);

        if ($success && $stmt->rowCount() > 0) {
            // Mettre à jour la session si vous stockez le chemin de l'image
            $_SESSION['profile_pic'] = $db_path; // stocke 'uploads/profile_pics/xxx.jpg'

            echo json_encode([
                'success' => true, 
                'message' => 'Photo de profil mise à jour.',
                'new_pic_url' => $db_path // Renvoyer le chemin pour l'affichage immédiat
            ]);
        } else {
            // Si la DB échoue ou ne touche aucune ligne, on supprime le fichier qui a été uploadé
            if (file_exists($destination_fs)) unlink($destination_fs);
            $dbError = $stmt->errorInfo();
            error_log("update_profile_pic.php - DB update failed for user_uuid={$user_id} - errorInfo: " . json_encode($dbError));
            echo json_encode(['success' => false, 'message' => 'Erreur lors de l\'enregistrement en base de données.']);
        }

    } catch (\PDOException $e) {
        if (file_exists($destination_fs)) unlink($destination_fs);
        error_log("DB Error in update_profile_pic.php: " . $e->getMessage());
        // Exposer le message d'erreur en mode debug uniquement
        $debug = $GLOBALS['DB_CONFIG']['debug'] ?? false;
        if ($debug) {
            echo json_encode(['success' => false, 'message' => 'Erreur serveur interne: ' . $e->getMessage()]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Erreur serveur interne.']);
        }
    }
} else {
    // Échec du déplacement (problème de permissions de dossier)
    error_log("update_profile_pic.php - move_uploaded_file failed. destFs: ".$destination_fs);
    echo json_encode(['success' => false, 'message' => 'Erreur: Impossible de déplacer le fichier. Vérifiez les permissions du dossier "uploads/profile_pics/".']);
}

exit;
?>