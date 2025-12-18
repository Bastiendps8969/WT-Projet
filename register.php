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
    $passwordConfirm = $_POST['password_confirm'] ?? '';

    // Validation des champs vides
    if (empty($username) || empty($email) || empty($password) || empty($passwordConfirm)) {
        throw new Exception('Tous les champs sont obligatoires.');
    }

    // Validation de la longueur du mot de passe
    if (strlen($password) < 8) {
        throw new Exception('Le mot de passe doit contenir au moins 8 caractères.');
    }

    // Validation de la confirmation du mot de passe
    if ($password !== $passwordConfirm) {
        throw new Exception('Les mots de passe ne correspondent pas.');
    }

    // Validation simple du format de l'email
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Format d\'email invalide.');
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
        throw new Exception('Cet email ou ce nom d\'utilisateur est déjà utilisé.');
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
        echo json_encode(['success' => true, 'message' => 'Compte créé avec succès. Vous pouvez maintenant vous connecter.']);
    } else {
        throw new Exception('Erreur lors de l\'enregistrement de l\'utilisateur.');
    }

} catch (\PDOException $e) {
    // Erreur de base de données
    error_log("DB Error in register.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erreur du serveur (base de données).']);

} catch (Exception $e) {
    // Erreur de validation ou autre
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>