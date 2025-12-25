<?php
// register.php - WebService d'Inscription d'un nouvel utilisateur

// Définit le type de contenu de la réponse comme JSON
header('Content-Type: application/json');

// Inclut le fichier de configuration pour la connexion à la base de données
require_once 'config.php';

try {
    // 1. Récupération et validation des données POST
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    // Validation des champs vides (pas de confirmation requise)
    if (empty($username) || empty($email) || empty($password)) {
        throw new Exception('All fields are required.');
    }

    // Validation de la longueur du mot de passe
    if (strlen($password) < 8) {
        throw new Exception('Password must be at least 8 characters.');
    }

    // (No password confirmation check requested)

    // Validation simple du format de l'email
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Invalid email format.');
    }

    // 2. Hachage du mot de passe (Bcrypt recommandé)
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    
    // 3. Connexion à la base de données
    $pdo = getDBConnection();

    // 4. Vérification de l'unicité de l'email et du nom d'utilisateur
    $sqlCheck = "SELECT COUNT(*) FROM User WHERE Email = :email OR Username = :username";
    $stmtCheck = $pdo->prepare($sqlCheck);
    $stmtCheck->bindParam(':email', $email);
    $stmtCheck->bindParam(':username', $username);
    $stmtCheck->execute();
    
    if ($stmtCheck->fetchColumn() > 0) {
        throw new Exception('This email or username is already in use.');
    }

    // 5. Insertion du nouvel utilisateur
    // Nous utilisons UUID_TO_BIN(UUID()) pour générer un nouvel UUID binaire pour IdUser
    $sqlInsert = "
        INSERT INTO User (IdUser, Username, Email, Password, Picture) 
        VALUES (UUID_TO_BIN(UUID()), :username, :email, :password, NULL)
    ";
    
    $stmtInsert = $pdo->prepare($sqlInsert);
    $stmtInsert->bindParam(':username', $username);
    $stmtInsert->bindParam(':email', $email);
    $stmtInsert->bindParam(':password', $hashedPassword);
    
    if ($stmtInsert->execute()) {
        echo json_encode(['success' => true, 'message' => 'Account created successfully. You can now log in.']);
    } else {
        throw new Exception('Error saving the user.');
    }

} catch (\PDOException $e) {
    // Erreur de base de données
    error_log("DB Error in register.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error (database).']);

} catch (Exception $e) {
    // Erreur de validation ou autre
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>