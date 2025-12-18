<?php
// get_tags.php - Récupère la liste des tags (Web Service JSON)

// Définit le type de contenu de la réponse comme JSON
header('Content-Type: application/json');

// Inclut le fichier de configuration pour la connexion à la base de données
require_once 'config.php';

try {
    // 1. Obtient une connexion à la base de données
    $pdo = getDBConnection();

    // 2. Prépare la requête SQL pour récupérer tous les tags
    // Utilise BIN_TO_UUID pour afficher les UUID lisibles pour le frontend
    $stmt = $pdo->prepare("SELECT BIN_TO_UUID(IdTag) as IdTag, Title FROM Tag ORDER BY Title ASC");

    // 3. Exécute la requête
    $stmt->execute();

    // 4. Récupère tous les résultats
    $tags = $stmt->fetchAll();

    // 5. Retourne les données JSON
    echo json_encode(['success' => true, 'tags' => $tags]);

} catch (\PDOException $e) {
    // 6. Capture les erreurs de base de données
    // Réponse générique pour éviter d'exposer des détails sensibles
    error_log("DB Error in get_tags.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erreur lors de la récupération des tags.']);

} catch (Exception $e) {
    // 7. Capture les autres exceptions
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>