<?php
// login.php - WebService de Connexion

// Démarre la session
session_start();

// Définit le type de contenu de la réponse comme JSON
header('Content-Type: application/json');

// Inclut le fichier de configuration pour la connexion à la base de données
require_once 'config.php';

try {
    // 1. Récupérer les données POST (email et mot de passe)
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        echo json_encode(['success' => false, 'message' => 'Please fill in all fields.']);
        exit;
    }

    // 2. Connexion à la base de données
    $pdo = getDBConnection();

    // 3. Récupérer l'utilisateur par email (requête préparée)
    // IMPORTANT : Récupérer également le Username et Picture
    $sql = "SELECT BIN_TO_UUID(IdUser) as IdUser, Username, Password, Picture FROM User WHERE Email = :email";
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':email', $email);
    $stmt->execute();

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // 4. Vérification de l'utilisateur et du mot de passe
    if ($user && password_verify($password, $user['Password'])) {
        
        // 5. Connexion réussie : Enregistrement des informations de session
        $_SESSION['user_uuid'] = $user['IdUser'];
        $_SESSION['username'] = $user['Username'];
        $_SESSION['logged_in'] = true;
        // Stocker la Photo de Profil si existante
        if (!empty($user['Picture'])) {
            $_SESSION['profile_pic'] = $user['Picture'];
        } else {
            $_SESSION['profile_pic'] = null;
        }

        // Suppression du mot de passe de l'objet pour la sécurité
        unset($user['Password']); 

        echo json_encode(['success' => true, 'message' => 'Login successful.', 'user_id' => $user['IdUser']]);
        
    } else {
        // Échec de la connexion
        echo json_encode(['success' => false, 'message' => 'Incorrect email or password.']);
    }

} catch (\PDOException $e) {
    // Erreur de base de données
    error_log("DB Error in login.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error during login.']);

} catch (Exception $e) {
    // Autres erreurs
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>