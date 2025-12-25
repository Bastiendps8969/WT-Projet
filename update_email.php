<?php
// update_email.php - Gère la mise à jour de l'adresse e-mail de l'utilisateur

header('Content-Type: application/json');
session_start();

// Inclure le fichier de configuration de la base de données
require_once 'config.php';

// --- VÉRIFICATION DE LA SESSION ---
if (!isset($_SESSION['user_uuid'])) {
    http_response_code(401); // Non autorisé
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Please log in again.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['new_email'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

$new_email = trim($_POST['new_email']);
$user_id = $_SESSION['user_uuid']; // Correction ici

// 1. Validation de l'adresse email
if (empty($new_email) || !filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'The provided email address is invalid.']);
    exit;
}

try {
    $pdo = getDBConnection(); // Fonction définie dans config.php

    // 2. VÉRIFIER L'UNICITÉ DE L'EMAIL
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM User WHERE Email = :email AND BIN_TO_UUID(IdUser) != :user_id");
    $stmt->execute([':email' => $new_email, ':user_id' => $user_id]);
    
    if ($stmt->fetchColumn() > 0) {
        echo json_encode(['success' => false, 'message' => 'This email is already used by another account.']);
        exit;
    }
    
    // 3. MISE À JOUR EN BASE DE DONNÉES (comparaison via BIN_TO_UUID pour éviter UUID_TO_BIN sur paramètre)
    $stmt = $pdo->prepare("UPDATE User SET Email = :email WHERE BIN_TO_UUID(IdUser) = :user_id");
    $success = $stmt->execute([':email' => $new_email, ':user_id' => $user_id]);

    if ($success) {
        // Mettre à jour la session si nécessaire
        $_SESSION['email'] = $new_email; 
        echo json_encode(['success' => true, 'message' => 'Email updated successfully.']);
    } else {
        // Ajout d'un log pour le debug
        error_log("Error saving to database (update_email.php)");
        echo json_encode(['success' => false, 'message' => 'Error saving to database.']);
    }

} catch (\PDOException $e) {
    error_log("DB Error in update_email.php: " . $e->getMessage());
    $debug = $GLOBALS['DB_CONFIG']['debug'] ?? false;
    if ($debug) {
        echo json_encode(['success' => false, 'message' => 'Internal server error: ' . $e->getMessage()]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Internal server error.']);
    }
}

exit;
?>