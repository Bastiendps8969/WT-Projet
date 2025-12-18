<?php
// add_story.php - Ajoute une nouvelle story ou archive une story existante (Web Service JSON)

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
    echo json_encode(['success' => false, 'message' => 'Authentification requise pour créer une story.']);
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
    $title = trim($_POST['title'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $selectedTags = $_POST['tags'] ?? []; // Peut être absent ou une chaîne si le client envoie autre chose
    if (!is_array($selectedTags)) {
        // Normaliser : si une seule valeur a été envoyée, convertir en tableau ; sinon rendre tableau vide
        if (is_string($selectedTags) && strlen($selectedTags) > 0) {
            $selectedTags = [$selectedTags];
        } else {
            $selectedTags = [];
        }
    }

    if (empty($title) || empty($content)) {
        throw new Exception('Titre et contenu sont obligatoires.');
    }

    // Détecter si l'utilisateur a choisi l'archivage (faire ceci avant la validation des tags)
    $isArchive = false;
    if (isset($_POST['is_archive'])) {
        $val = $_POST['is_archive'];
        if ($val === '1' || $val === 'on' || strtolower($val) === 'true') $isArchive = true;
    }

    // Si ce n'est pas une archive, on exige au moins un tag
    if (!$isArchive) {
        if (empty($selectedTags) || !is_array($selectedTags)) {
            throw new Exception('Au moins un tag est obligatoire.');
        }
    }

    // 3. Gestion de l'upload de l'image
    if (isset($_FILES['picture']) && $_FILES['picture']['error'] === UPLOAD_ERR_OK) {
        $fileTmpPath = $_FILES['picture']['tmp_name'];
        $fileName = basename($_FILES['picture']['name']);
        $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $allowedFileTypes = ['jpg', 'jpeg', 'png', 'gif'];
        
        if (!in_array($fileExtension, $allowedFileTypes)) {
            throw new Exception('Format de fichier non supporté. (JPG, PNG, GIF)');
        }

        // Renommer le fichier pour éviter les conflits (utilisation d'un ID unique)
        $newFileName = uniqid($isArchive ? 'archive_' : 'story_') . '.' . $fileExtension;
        // Chemin fichier absolu
        $destPathFs = $uploadFsDir . $newFileName;
        // Chemin web relatif à stocker en DB / renvoyer
        $destPathWeb = $uploadWebDir . $newFileName;

        if (move_uploaded_file($fileTmpPath, $destPathFs)) {
            $picturePath = $destPathWeb; // stocke 'uploads/xxx.jpg'
            $pictureFsPath = $destPathFs; // pour suppression éventuelle
        } else {
            error_log("Erreur lors du déplacement du fichier uploadé. destFs={$destPathFs}");
        }
    }


    // 4. Connexion à la base de données et démarrage de la transaction
    $pdo = getDBConnection();
    $pdo->beginTransaction();

    if ($isArchive) {
        // 5. Insertion dans la table Archive
        $sqlArchive = "INSERT INTO Archive (IdArchive, Title, Content, Picture, IdUser) 
                       VALUES (UUID_TO_BIN(UUID()), :title, :content, :picture, UUID_TO_BIN(:idUser))";
        $stmtA = $pdo->prepare($sqlArchive);
        $stmtA->bindValue(':title', $title, PDO::PARAM_STR);
        $stmtA->bindValue(':content', $content, PDO::PARAM_STR);
        if ($picturePath !== null) $stmtA->bindValue(':picture', $picturePath, PDO::PARAM_STR);
        else $stmtA->bindValue(':picture', null, PDO::PARAM_NULL);
        $stmtA->bindValue(':idUser', $idUser, PDO::PARAM_STR);
        $stmtA->execute();

        // 6. Récupérer l'IdArchive inséré
        $stmtGet = $pdo->prepare("SELECT BIN_TO_UUID(IdArchive) FROM Archive WHERE IdUser = UUID_TO_BIN(?) ORDER BY CreationDate DESC LIMIT 1");
        $stmtGet->execute([$idUser]);
        $newArchiveId = $stmtGet->fetchColumn();

        // 8. Valider la transaction
        $pdo->commit();

        // 9. Succès
        echo json_encode(['success' => true, 'message' => 'Archive créée avec succès.', 'archive_id' => $newArchiveId]);
        exit;
    } else {
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
            throw new Exception("Erreur lors de la récupération du nouvel IdStory.");
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
        echo json_encode(['success' => true, 'message' => 'Story publiée avec succès.', 'story_id' => $newIdStoryStr]);
        exit;
    }
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
    
    // Log l'erreur (pour le développeur)
    error_log("Erreur dans add_story.php: " . $e->getMessage());
    
    // Retourner un message d'erreur clair au frontend
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
}
?>