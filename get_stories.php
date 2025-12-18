<?php
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

    // --- NOUVEAU : lecture du paramètre 'archived' (par défaut 0 -> stories publiées)
    $archived = 0;
    if (isset($_GET['archived'])) {
        $val = strtolower(trim($_GET['archived']));
        $archived = ($val === '1' || $val === 'true' || $val === 'yes') ? 1 : 0;
    }

    // --- NOUVEAU : vérifier si la colonne 'Archived' existe dans la table Story ---
    $hasArchived = false;
    try {
        $colStmt = $pdo->prepare("SHOW COLUMNS FROM Story LIKE 'Archived'");
        $colStmt->execute();
        $hasArchived = ($colStmt->fetch(PDO::FETCH_ASSOC) !== false);
    } catch (\PDOException $colEx) {
        // En cas d'erreur ici, on assume que la colonne n'existe pas
        $hasArchived = false;
    }

    // --- Construire la requête SQL en fonction de l'existence de la colonne Archived ---
    if ($hasArchived) {
        $sql = "
            SELECT
                BIN_TO_UUID(S.IdStory) AS IdStory,
                S.Title AS StoryTitle,
                S.Content,
                S.Picture,
                S.CreationDate,
                BIN_TO_UUID(U.IdUser) AS IdUser,
                U.Username,
                S.Archived AS Archived,
                GROUP_CONCAT(T.Title SEPARATOR '|||') AS Tags
            FROM
                Story S
            JOIN
                User U ON S.IdUser = U.IdUser
            LEFT JOIN
                StoryTag ST ON S.IdStory = ST.IdStory
            LEFT JOIN
                Tag T ON ST.IdTag = T.IdTag
            WHERE
                COALESCE(S.Archived, 0) = :archived
            GROUP BY
                S.IdStory, S.Title, S.Content, S.Picture, S.CreationDate, U.IdUser, U.Username, S.Archived
            ORDER BY
                S.CreationDate DESC
        ";

        // Exécute la requête en liant explicitement un entier pour :archived
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':archived', (int)$archived, PDO::PARAM_INT);
        $stmt->execute();

        // Récupère tous les résultats en mode associatif
        $storiesRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } else {
        // Si la colonne Archived n'existe pas
        if ($archived === 1) {
            // La table 'Archive' existe (structure fournie) : lire les archives depuis Archive
            $sql = "
                SELECT
                    BIN_TO_UUID(A.IdArchive) AS IdStory,
                    A.Title AS StoryTitle,
                    A.Content,
                    A.Picture,
                    A.CreationDate,
                    BIN_TO_UUID(U.IdUser) AS IdUser,
                    U.Username,
                    1 AS Archived
                FROM
                    Archive A
                JOIN
                    User U ON A.IdUser = U.IdUser
                ORDER BY
                    A.CreationDate DESC
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute();
            $storiesRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
             // archived == 0 : retourner toutes les stories (aucun filtre Archived disponible)
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
         }
     }
 
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

        // normaliser Archived en int (utile côté client) - si absent, forcer 0
        $story['Archived'] = isset($story['Archived']) ? (int)$story['Archived'] : 0;

        // --- NOUVEAU : indiquer si l'utilisateur courant peut supprimer la story ---
        // Admin user (nom d'utilisateur exact 'Admin') peut supprimer tout, sinon seul l'auteur peut supprimer
        $isAdmin = ($currentUsername !== null && $currentUsername === 'Admin');
        $story['can_delete'] = $isAdmin || ($currentUserUuid !== null && isset($story['IdUser']) && $story['IdUser'] === $currentUserUuid);

        // NOUVEAU : les archives NE SONT PAS modifiables — can_edit = false pour Archived == 1
        $story['can_edit'] = ((int)$story['Archived'] === 0) && ($currentUserUuid !== null && isset($story['IdUser']) && $story['IdUser'] === $currentUserUuid);

        // Calcul du score total (tous votes) et du nombre d'utilisateurs distincts pour chaque type
        $scoreStmt = $pdo->prepare("
            SELECT 
                SUM(CASE WHEN Type=1 THEN 1 ELSE 0 END) AS upvotes,
                SUM(CASE WHEN Type=0 THEN 1 ELSE 0 END) AS downvotes
            FROM StoryReaction WHERE IdStory = UUID_TO_BIN(:idStory)
        ");
        $scoreStmt->execute([':idStory' => $story['IdStory']]);
        $score = $scoreStmt->fetch(PDO::FETCH_ASSOC);

        $story['score'] = (int)($score['upvotes'] ?? 0) - (int)($score['downvotes'] ?? 0);

        // Version 1 : nombre d'utilisateurs distincts ayant upvote
        $upvoteUsersStmt = $pdo->prepare("
            SELECT COUNT(DISTINCT IdUser) AS upvote_users
            FROM StoryReaction
            WHERE IdStory = UUID_TO_BIN(:idStory) AND Type = 1
        ");
        $upvoteUsersStmt->execute([':idStory' => $story['IdStory']]);
        $upvoteUsers = $upvoteUsersStmt->fetchColumn();
        $story['upvotes'] = (int)$upvoteUsers;

        // Version 2 : nombre d'utilisateurs distincts ayant downvote
        $downvoteUsersStmt = $pdo->prepare("
            SELECT COUNT(DISTINCT IdUser) AS downvote_users
            FROM StoryReaction
            WHERE IdStory = UUID_TO_BIN(:idStory) AND Type = 0
        ");
        $downvoteUsersStmt->execute([':idStory' => $story['IdStory']]);
        $downvoteUsers = $downvoteUsersStmt->fetchColumn();
        $story['downvotes'] = (int)$downvoteUsers;

        // Ajout : vote de l'utilisateur courant
        $userVote = null;
        if ($currentUserUuid) {
            $voteStmt = $pdo->prepare("SELECT Type FROM StoryReaction WHERE IdStory = UUID_TO_BIN(:idStory) AND IdUser = UUID_TO_BIN(:idUser) LIMIT 1");
            $voteStmt->execute([':idStory' => $story['IdStory'], ':idUser' => $currentUserUuid]);
            $voteRow = $voteStmt->fetch(PDO::FETCH_ASSOC);
            if ($voteRow !== false) $userVote = ($voteRow['Type'] == 1 ? 'up' : 'down');
        }
        $story['user_vote'] = $userVote;

        $stories[] = $story;
     }
 
    // 6. Retourne les données JSON (préserver l'UTF-8 et slashs)
    echo json_encode(['success' => true, 'stories' => $stories], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (\PDOException $e) {
    // 7. Capture les erreurs de base de données et retourne un JSON d'erreur
    error_log("DB Error in get_stories.php: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    http_response_code(500);
    if ($debug) {
        echo json_encode(['success' => false, 'message' => 'Erreur lors de la récupération des stories. (BDD)', 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Erreur lors de la récupération des stories. (BDD)']);
    }

} catch (Exception $e) {
    // 8. Capture les autres exceptions et retourne un JSON d'erreur
    error_log("Error in get_stories.php: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    http_response_code(500);
    if ($debug) {
        echo json_encode(['success' => false, 'message' => 'Erreur inconnue du serveur.', 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Erreur inconnue du serveur.']);
    }
}
?>