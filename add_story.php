<?php
/*
    Fichier : add_story.php
    Rôle :créer une nouvelle story .
    Processus : Vérifie l'authentification via la session, gère l'upload d'image, insère en base.
    Sortie : JSON indiquant succès et identifiants créés (story_id).
*/
// add_story.php - Ajoute une nouvelle story

// Démarre une session pour accéder aux informations de l'utilisateur connecté
session_start();

// Définit le type de contenu de la réponse comme JSON
header('Content-Type: application/json');

// Inclut le fichier de configuration pour la connexion à la base de données
require_once 'config.php';

// **********************************************
// 1. VÉRIFICATION D'AUTHENTIFICATION
// Vérifie si l'utilisateur est connecté et si son UUID est dans la session.
// **********************************************
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || !isset($_SESSION['user_uuid'])) {
    // Retourne une réponse JSON indiquant un accès refusé
    echo json_encode(['success' => false, 'message' => 'Authentication required to create a story.']);
    exit;
}

// L'ID utilisateur (UUID string) est récupéré directement de la session
$idUser = $_SESSION['user_uuid'];
// **********************************************


// Dossier d'upload pour les images (chemin web et chemin fichier)
$uploadWebDir = 'uploads/';
$uploadFsDir = __DIR__ . '/' . $uploadWebDir;
if (!is_dir($uploadFsDir)) {
    mkdir($uploadFsDir, 0777, true);
}

// Initialisation de la variable du chemin de l'image
$picturePath = null; // chemin web (ex: uploads/story_x.jpg)
$pictureFsPath = null; // chemin fichier absolu pour unlink si besoin
$pdo = null; // Initialisation de PDO en dehors du try

try {
    // 2. Validation des données POST
    $title = trim($_POST['titleStory'] ?? '');
    $content = trim($_POST['contentStory'] ?? '');
    $selectedTags = $_POST['tagStory'] ?? []; // accept tagStory[] from form
    if (!is_array($selectedTags)) {
        // Normaliser : si une seule valeur a été envoyée, convertir en tableau ; sinon rendre tableau vide
        if (is_string($selectedTags) && strlen($selectedTags) > 0) {
            $selectedTags = [$selectedTags];
        } else {
            $selectedTags = [];
        }
    }

    if (empty($title) || empty($content)) {
        throw new Exception('Title and content are mandatory.');
    }

    if (!preg_match('/^[A-Za-z0-9\-\/?.,!\s]{1,50}$/', $title)) {
        throw new Exception('Invalid title format.');
    }

    if (!preg_match('/^[A-Za-z0-9\-\/?.,!\s]{1,5000}$/', $content)) {
        throw new Exception('Invalid content format.');
    }

    // Exiger au moins un tag pour toute story publiée
    if (empty($selectedTags) || !is_array($selectedTags)) {
        throw new Exception('At least one tag is required.');
    }

    // 3. Gestion de l'upload de l'image
    if (isset($_FILES['pictureStory']) && $_FILES['pictureStory']['error'] === UPLOAD_ERR_OK) {
        $fileTmpPath = $_FILES['pictureStory']['tmp_name'];
        $fileName = basename($_FILES['pictureStory']['name']);
        $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $allowedFileTypes = ['jpg', 'jpeg', 'png'];
        
        if (!in_array($fileExtension, $allowedFileTypes)) {
            throw new Exception('Unsupported file format. (JPG, PNG)');
        }

        // Renommer le fichier pour éviter les conflits (utilisation d'un ID unique)
        $newFileName = uniqid('story_') . '.' . $fileExtension;
        // Chemin fichier absolu
        $destPathFs = $uploadFsDir . $newFileName;
        // Chemin web relatif à stocker en DB / renvoyer
        $destPathWeb = $uploadWebDir . $newFileName;

        if (move_uploaded_file($fileTmpPath, $destPathFs)) {
            $picturePath = $destPathWeb; // stocke 'uploads/xxx.jpg'
            $pictureFsPath = $destPathFs; // pour suppression éventuelle
        } else {
            error_log("Error moving uploaded file. destFs={$destPathFs}");
        }
    }


    // 4. Connexion à la base de données et démarrage de la transaction
    $pdo = getDBConnection();
    $pdo->beginTransaction();

    // Insertion dans la table Story
    // Insertion dans la table Story (comportement existant)
        $sqlStory = "INSERT INTO Story (IdStory, Title, Content, Picture, IdUser) 
                     VALUES (UUID_TO_BIN(UUID()), :title, :content, :picture, UUID_TO_BIN(:idUser))";
    
        $stmtStory = $pdo->prepare($sqlStory);
        $stmtStory->bindParam(':title', $title);
        $stmtStory->bindParam(':content', $content);
        $stmtStory->bindParam(':picture', $picturePath, $picturePath ? PDO::PARAM_STR : PDO::PARAM_NULL); 
        $stmtStory->bindParam(':idUser', $idUser); // L'UUID string de la session
        $stmtStory->execute();

        // 6. Récupérer l'IdStory nouvellement créé (UUID string) pour l'utiliser dans StoryTag
        // On récupère le dernier IdStory inséré par l'utilisateur courant.
        $stmtGetId = $pdo->prepare("SELECT BIN_TO_UUID(IdStory) AS newIdStory FROM Story WHERE IdUser = UUID_TO_BIN(?) ORDER BY CreationDate DESC LIMIT 1");
        $stmtGetId->execute([$idUser]);
        $newIdStoryStr = $stmtGetId->fetchColumn(); // L'UUID en format string

        if (!$newIdStoryStr) {
            throw new Exception("Error while fetching new IdStory.");
        }

        // 7. Insertion des StoryTags (association Story <-> Tag)
        $sqlTag = "INSERT INTO StoryTag (IdStory, IdTag) VALUES (UUID_TO_BIN(:idStory), UUID_TO_BIN(:idTag))";
        $stmtTag = $pdo->prepare($sqlTag);
        
        foreach ($selectedTags as $tagUuid) {
            $stmtTag->bindValue(':idStory', $newIdStoryStr);
            $stmtTag->bindValue(':idTag', $tagUuid);
            $stmtTag->execute();
        }

        // 8. Valider la transaction (publication)
        $pdo->commit();

        // 9. Succès — renvoyer JSON et terminer
        echo json_encode(['success' => true, 'message' => 'Story successfully published.']);
        exit;
    
} catch (Exception $e) {
    // 10. Gestion des erreurs
    
    // Annuler la transaction en cas d'erreur
    if ($pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    // Supprimer l'image si elle a été uploadée avant l'erreur BDD/validation
    if (!empty($pictureFsPath) && file_exists($pictureFsPath)) {
        unlink($pictureFsPath);
    }
    
    // Log the error (for the developer)
    error_log("Error in add_story.php: " . $e->getMessage());
    
    // Retourner un message d'erreur clair au frontend
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
}
?>