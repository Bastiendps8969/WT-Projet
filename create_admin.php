<?php
header('Content-Type: application/json');
require_once 'config.php';

try {
    $pdo = getDBConnection();

    $adminUsername = 'Admin';
    $adminEmail = 'admin@example.local'; // modifiez si besoin
    $adminPasswordPlain = 'Admin@123'; // changez le mot de passe après création

    // Vérifier si l'utilisateur Admin existe déjà
    $check = $pdo->prepare("SELECT COUNT(*) FROM User WHERE Username = :username");
    $check->execute([':username' => $adminUsername]);

    if ($check->fetchColumn() > 0) {
        echo json_encode(['success' => true, 'message' => 'Admin account already exists.']);
        exit;
    }

    // Hacher le mot de passe
    $hashed = password_hash($adminPasswordPlain, PASSWORD_DEFAULT);

    // Insérer l'utilisateur Admin
    $insert = $pdo->prepare("INSERT INTO User (IdUser, Username, Email, Password, Picture) VALUES (UUID_TO_BIN(UUID()), :username, :email, :password, NULL)");
    $ok = $insert->execute([':username' => $adminUsername, ':email' => $adminEmail, ':password' => $hashed]);

    if ($ok) {
        echo json_encode(['success' => true, 'message' => 'Admin account created.', 'username' => $adminUsername, 'password' => $adminPasswordPlain]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to create Admin account.']);
    }

} catch (\PDOException $e) {
    error_log("DB Error in create_admin.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
?>
