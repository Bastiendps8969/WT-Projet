<?php
// update_password.php - Gère la mise à jour du mot de passe de l'utilisateur

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

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['current_password'], $_POST['new_password'])) {
    echo json_encode(['success' => false, 'message' => 'Requête invalide ou données manquantes.']);
    exit;
}

$current_password = $_POST['current_password'];
$new_password = $_POST['new_password'];
$user_id = $_SESSION['user_uuid']; // use UUID string

// 1. Validation du nouveau mot de passe (taille minimum, complexité, etc.)
if (strlen($new_password) < 8) {
    echo json_encode(['success' => false, 'message' => 'Le nouveau mot de passe doit faire au moins 8 caractères.']);
    exit;
}
if ($current_password === $new_password) {
    echo json_encode(['success' => false, 'message' => 'Le nouveau mot de passe doit être différent du mot de passe actuel.']);
    exit;
}

try {
    $pdo = getDBConnection();

    // 2. RÉCUPÉRATION DU HASH DU MOT DE PASSE ACTUEL (compare via BIN_TO_UUID)
    $stmt = $pdo->prepare("SELECT Password FROM User WHERE BIN_TO_UUID(IdUser) = :user_id");
    $stmt->execute([':user_id' => $user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC); // explicit fetch mode

    if (!$user) {
        error_log("update_password.php - user not found for user_uuid: " . var_export($user_id, true));
        $debug = $GLOBALS['DB_CONFIG']['debug'] ?? false;
        if ($debug) {
            echo json_encode(['success' => false, 'message' => 'Erreur utilisateur introuvable. user_uuid=' . $user_id]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Erreur utilisateur introuvable.']);
        }
        exit;
    }

    $hashed_password = $user['Password'];

    // 3. VÉRIFICATION DU MOT DE PASSE ACTUEL
    if (!password_verify($current_password, $hashed_password)) {
        echo json_encode(['success' => false, 'message' => 'Mot de passe actuel incorrect.']);
        exit;
    }

    // 4. HACHAGE DU NOUVEAU MOT DE PASSE ET MISE À JOUR
    $new_hashed_password = password_hash($new_password, PASSWORD_BCRYPT);

    $stmt = $pdo->prepare("UPDATE User SET Password = :new_password WHERE BIN_TO_UUID(IdUser) = :user_id");
    $success = $stmt->execute([':new_password' => $new_hashed_password, ':user_id' => $user_id]);

    if ($success) {
        // C'est souvent une bonne pratique de forcer la reconnexion après un changement de mot de passe
        session_destroy();
        echo json_encode(['success' => true, 'message' => 'Mot de passe mis à jour. Veuillez vous reconnecter.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Erreur lors de la mise à jour du mot de passe.']);
    }

} catch (\PDOException $e) {
    error_log("DB Error in update_password.php: " . $e->getMessage());
    $debug = $GLOBALS['DB_CONFIG']['debug'] ?? false;
    if ($debug) {
        echo json_encode(['success' => false, 'message' => 'Erreur serveur interne: ' . $e->getMessage()]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Erreur serveur interne.']);
    }
}

exit;
?>