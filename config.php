<?php
// config.php - Configuration de la base de données avec PDO

// Configuration de la base de données
$DB_CONFIG = [
    'host' => 'localhost',         // Nom du serveur
    'username' => 'root',          // Nom d'utilisateur pour la base de données
    'password' => '',              // Mot de passe pour la base de données
    'dbname' => 'WTDataBase',      // Nom de la base de données
    'charset' => 'utf8mb4',         // Encodage
    'debug' => true                // Mettre à false en production
];

// Fonction pour obtenir une connexion à la base de données (PDO)
function getDBConnection() {
    global $DB_CONFIG;
    
    // Construction du DSN (Data Source Name)
    $dsn = "mysql:host={$DB_CONFIG['host']};dbname={$DB_CONFIG['dbname']};charset={$DB_CONFIG['charset']}";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // Afficher les erreurs sous forme d'exceptions
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // Retourner les résultats sous forme de tableau associatif
        PDO::ATTR_EMULATE_PREPARES   => false,                  // Désactiver l'émulation des requêtes préparées pour la sécurité
    ];

    try {
        // Crée une connexion PDO
        $pdo = new PDO($dsn, $DB_CONFIG['username'], $DB_CONFIG['password'], $options);
        return $pdo;
    } catch (\PDOException $e) {
        // En cas d'échec de la connexion, lance une exception
        throw new \PDOException("Database connection error: " . $e->getMessage(), (int)$e->getCode());
    }
}

// Fonction utilitaire pour générer un UUID binaire (nécessaire pour vos clés BINARY(16))
// C'est une implémentation simplifiée pour le besoin de l'exemple.
function generateUuidBinary() {
    // Génère un UUID version 4
    $uuid = (string) Ramsey\Uuid\Uuid::uuid4(); // Nécessite une librairie comme ramsey/uuid en prod
    // Pour cet exemple simple, nous allons simuler un UUID aléatoire qui devra être converti en binaire dans MySQL
    // En PHP, vous devriez utiliser `UUID_TO_BIN(UUID())` dans la requête SQL ou une librairie.
    // Pour simplifier l'exemple sans librairie externe, nous allons utiliser BINARY(16) vide dans le code PHP.
    // L'ajout en base doit se faire via `UNHEX(REPLACE(UUID(),'-',''))` ou en utilisant `UUID_TO_BIN(UUID())` directement dans la requête.
    // Pour la suite du code, nous allons **supposer** que le champ est rempli côté MySQL par `UUID_TO_BIN(UUID())` ou que le backend gère la conversion.
    // Pour `add_story.php`, je vais créer une version simplifiée.
    // Si la colonne est BINARY(16), la bonne pratique est de stocker BIN(UUID).
    // Pour l'exemple, nous allons générer un ID binaire simple pour le test.
    // En attendant la mise en place d'une librairie, la requête SQL sera ajustée.
    return null; // Retourne null pour le moment et utilise UUID_TO_BIN(UUID()) dans la requête.
}
?>