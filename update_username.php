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
    echo json_encode(['success' => false, 'message' => 'Non autorisé. Veuillez vous reconnecter.']);
    exit;
}

// --- VÉRIFICATION ET VALIDATION DES DONNÉES ---

// Vérifie que la méthode POST est utilisée et que le champ 'new_username' est bien présent
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_username'])) {
    
    $new_username = trim($_POST['new_username']);
    
    // 1. Validation de base du nom d'utilisateur
    if (empty($new_username) || strlen($new_username) < 3 || strlen($new_username) > 20) {
        echo json_encode(['success' => false, 'message' => 'Le nom d\'utilisateur doit contenir entre 3 et 20 caractères.']);
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
            echo json_encode(['success' => false, 'message' => 'Ce nom d\'utilisateur est déjà pris.']);
            exit;
        }

        // Mettre à jour le username
        $update = $pdo->prepare("UPDATE User SET Username = :username WHERE BIN_TO_UUID(IdUser) = :user_id");
        $ok = $update->execute([':username' => $new_username, ':user_id' => $current_user_id]);

        if ($ok && $update->rowCount() > 0) {
            // Mettre à jour la session
            $_SESSION['username'] = $new_username;
            echo json_encode(['success' => true, 'message' => 'Nom d\'utilisateur mis à jour avec succès.']);
            exit;
        } else {
            // Aucun changement effectué (peut-être même même valeur) => considérer comme succès silencieux ou message informatif
            echo json_encode(['success' => false, 'message' => 'Aucune modification effectuée.']);
            exit;
        }

    } catch (\PDOException $e) {
        error_log("DB Error in update_username.php: " . $e->getMessage());
        $debug = $GLOBALS['DB_CONFIG']['debug'] ?? false;
        if ($debug) {
            echo json_encode(['success' => false, 'message' => 'Erreur serveur interne: ' . $e->getMessage()]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Erreur serveur interne.']);
        }
        exit;
    }

} else {
    // Si la requête n'est pas POST ou manque de données
    echo json_encode(['success' => false, 'message' => 'Requête invalide ou données manquantes.']);
}

// Termine l'exécution du script
exit;
?>