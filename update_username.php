<?php
// Définit l'en-tête de réponse pour indiquer que le contenu est du JSON
header('Content-Type: application/json');

// Démarre la session PHP
session_start();

// --- VÉRIFICATION DE LA SESSION ---
// Vérifie si l'utilisateur est connecté
if (!isset($_SESSION['user_uuid'])) {
    // Si non connecté, renvoie une erreur 401 (Non autorisé)
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Please log in again.']);
    exit;
}

// --- VÉRIFICATION ET VALIDATION DES DONNÉES ---

// Vérifie que la méthode POST est utilisée et que le champ 'new_username' est bien présent
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_username'])) {
    
    $new_username = trim($_POST['new_username']);
    
    // 1. Validation de base du nom d'utilisateur
    if (empty($new_username)) {
        echo json_encode(['success' => false, 'message' => 'Username cannot be empty.']);
        exit;
    }

    if (!preg_match('/^[A-Za-z0-9\s]{1,20}$/', $new_username)) {
        echo json_encode(['success' => false, 'message' => 'Invalid username format.']);
        exit;
    }

    $current_user_id = $_SESSION['user_uuid'];

    // --- NOUVELLE LOGIQUE : MISE À JOUR EN BASE ---
    require_once 'config.php'; // getDBConnection()

    try {
        $pdo = getDBConnection();

        // Vérifier l'unicité (autre utilisateur n'ayant pas ce username)
        $check = $pdo->prepare("SELECT COUNT(*) FROM User WHERE Username = :username AND BIN_TO_UUID(IdUser) != :user_id");
        $check->execute([':username' => $new_username, ':user_id' => $current_user_id]);

        if ($check->fetchColumn() > 0) {
            echo json_encode(['success' => false, 'message' => 'This username is already taken.']);
            exit;
        }

        // Mettre à jour le username
        $update = $pdo->prepare("UPDATE User SET Username = :username WHERE BIN_TO_UUID(IdUser) = :user_id");
        $ok = $update->execute([':username' => $new_username, ':user_id' => $current_user_id]);

        if ($ok && $update->rowCount() > 0) {
            // Mettre à jour la session
            $_SESSION['username'] = $new_username;
            echo json_encode(['success' => true, 'message' => 'Username updated successfully.']);
            exit;
        } else {
            // Aucun changement effectué (peut-être même même valeur) => considérer comme succès silencieux ou message informatif
            echo json_encode(['success' => false, 'message' => 'No changes made.']);
            exit;
        }

    } catch (\PDOException $e) {
        error_log("DB Error in update_username.php: " . $e->getMessage());
        $debug = $GLOBALS['DB_CONFIG']['debug'] ?? false;
        if ($debug) {
            echo json_encode(['success' => false, 'message' => 'Internal server error: ' . $e->getMessage()]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Internal server error.']);
        }
        exit;
    }

} else {
    // If the request is not POST or data is missing
    echo json_encode(['success' => false, 'message' => 'Invalid request or missing data.']);
}

// Termine l'exécution du script
exit;
?>