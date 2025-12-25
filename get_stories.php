<?php
/*
    Fichier : get_stories.php
    Rôle : Web service retournant les stories en JSON,
                 avec auteurs, tags et métadonnées. Utilisé par la page principale et listes.
    Remarque : garde la sortie strictement JSON pour éviter d'endommager le parseur côté client.
*/
// get_stories.php - Récupère toutes les stories avec leurs auteurs et tags

// *******************************************************
// TRÈS IMPORTANT : Assurer que seule une réponse JSON est envoyée
// *******************************************************
header('Content-Type: application/json');

// Inclut le fichier de configuration pour la connexion à la base de données
require_once 'config.php';

// --- NOUVEAU : debug flag et affichage d'erreurs si activé ---
$debug = $GLOBALS['DB_CONFIG']['debug'] ?? false;
if ($debug) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
}

// --- NOUVEAU : démarrer la session pour connaître l'utilisateur courant ---
session_start();
$currentUserUuid = $_SESSION['user_uuid'] ?? null;
$currentUsername = $_SESSION['username'] ?? null;
$debug = $GLOBALS['DB_CONFIG']['debug'] ?? false;

try {
    // 1. Obtient une connexion à la base de données
    $pdo = getDBConnection();

    // Construire la requête SQL pour retourner les stories publiées
    $sql = "
        SELECT
            BIN_TO_UUID(S.IdStory) AS IdStory,
            S.Title AS StoryTitle,
            S.Content,
            S.Picture,
            S.CreationDate,
            BIN_TO_UUID(U.IdUser) AS IdUser,
            U.Username,
            GROUP_CONCAT(T.Title SEPARATOR '|||') AS Tags
        FROM
            Story S
        JOIN
            User U ON S.IdUser = U.IdUser
        LEFT JOIN
            StoryTag ST ON S.IdStory = ST.IdStory
        LEFT JOIN
            Tag T ON ST.IdTag = T.IdTag
        GROUP BY
            S.IdStory, S.Title, S.Content, S.Picture, S.CreationDate, U.IdUser, U.Username
        ORDER BY
            S.CreationDate DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $storiesRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);
 
     // 5. Traitement des données pour structurer les tags en tableau (si nécessaire)
     $stories = [];
     foreach ($storiesRaw as $story) {
         // --- Normaliser le contenu : conserver les sauts de ligne (convertir CRLF -> LF) ---
         if (isset($story['Content'])) {
             $story['Content'] = str_replace(["\r\n", "\r"], "\n", $story['Content']);
         }
 
         // Normaliser le chemin de l'image : convertir en URL absolue si nécessaire
         if (!empty($story['Picture'])) {
             $pic = $story['Picture'];
             // Si c'est déjà une URL absolue, on la conserve
             if (strpos($pic, 'http://') === 0 || strpos($pic, 'https://') === 0) {
                 $story['Picture'] = $pic;
             } else {
                 // Construire l'URL absolue basée sur la requête courante et le répertoire du script
                 $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                 $host = $_SERVER['HTTP_HOST'];
                 $scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
                 // s'assurer que $pic commence par une slash
                 $picPath = '/' . ltrim($pic, '/\\');
                 // Résultat exemple: http://localhost/WTProjet/uploads/...
                 $story['Picture'] = $scheme . '://' . $host . $scriptDir . $picPath;
             }
         }
 
         // Convertir la chaîne de tags en tableau (ou tableau vide)
         if (isset($story['Tags']) && $story['Tags'] !== null && $story['Tags'] !== '') {
             $story['Tags'] = explode('|||', $story['Tags']);
         } else {
            $story['Tags'] = [];
         }

        // --- NOUVEAU : indiquer si l'utilisateur courant peut supprimer la story ---
        // Admin user (nom d'utilisateur exact 'Admin') peut supprimer tout, sinon seul l'auteur peut supprimer
        $isAdmin = ($currentUsername !== null && $currentUsername === 'Admin');
        $story['can_delete'] = $isAdmin || ($currentUserUuid !== null && isset($story['IdUser']) && $story['IdUser'] === $currentUserUuid);

        // Indiquer si l'utilisateur courant peut éditer la story (seul l'auteur peut éditer)
        $story['can_edit'] = ($currentUserUuid !== null && isset($story['IdUser']) && $story['IdUser'] === $currentUserUuid);
        $stories[] = $story;
     }
 
    // 6. Retourne les données JSON (préserver l'UTF-8 et slashs)
    echo json_encode(['success' => true, 'stories' => $stories], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (\PDOException $e) {
    // 7. Capture les erreurs de base de données et retourne un JSON d'erreur
    error_log("DB Error in get_stories.php: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    http_response_code(500);
    if ($debug) {
        echo json_encode(['success' => false, 'message' => 'Error retrieving stories. (DB)', 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error retrieving stories. (DB)']);
    }

} catch (Exception $e) {
    // 8. Capture les autres exceptions et retourne un JSON d'erreur
    error_log("Error in get_stories.php: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    http_response_code(500);
    if ($debug) {
        echo json_encode(['success' => false, 'message' => 'Unknown server error.', 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Unknown server error.']);
    }
}
?>