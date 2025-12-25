<?php
/*
    Fichier : check_session.php
    Rôle : Vérifie l'état de la session côté serveur et retourne un JSON indiquant si l'utilisateur est connecté.
    Utilisation : Requêté par le front-end pour adapter l'interface (afficher avatar, liens, etc.).
*/
header('Content-Type: application/json');
session_start();

// Vérification stricte : il faut que user_uuid ET logged_in soient présents et valides
if (isset($_SESSION['user_uuid']) && isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    echo json_encode([
        'status' => 'connected',
        'username' => $_SESSION['username'] ?? null,
        'profile_pic' => $_SESSION['profile_pic'] ?? null,
        // optionnel : 'email' => $_SESSION['email'] ?? null,
    ]);
} else {
    echo json_encode(['status' => 'disconnected']);
}

exit;
?>