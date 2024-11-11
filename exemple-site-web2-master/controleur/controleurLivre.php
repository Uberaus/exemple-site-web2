<?php

require_once 'modele/modeleLivres.php';
require_once 'modele/modelePages.php';
require_once 'modele/modeleCategories.php';

function afficherLivres()
{
  // Récupère tous les livres de la BD.
  $requeteLivres = ModeleLivres::ObtenirLivres();

  // Affiche les livres.
  // La vue va utiliser les variables $requeteLivre
  require_once 'vue/livres.php';
}

function afficherLivresUtilisateurConnecte()
{
  // Si l'utilisateur n'est pas connecté, on le redirige vers la page de connection.
  if (!isset($_SESSION['utilisateur'])) {
    header("Location: index.php?ressource=/connexion");
    return;
  }

  // Récupère l'id de l'utilisateur connecté.
  $idUtilisateur = $_SESSION['utilisateur']['id'];
  // Récupère tous les livres de l'utilisateur courant dans la BD.
  $requeteLivres = ModeleLivres::ObtenirLivresParIdUtilisateur($idUtilisateur);

  // Affiche les livres de l'utilisateur
  require_once 'vue/vosLivres.php';
}

function afficherLivreParId()
{
  // Validation des données reçues.
  if (!isset($_GET['id'])) {
    throw new Exception('Veuillez fournir un identifiant de livre');
  }

  // Récupère les informations du livre de la BD.
  $requeteLivre = ModeleLivres::ObtenirLivreParId($_GET['id']);
  $livre = $requeteLivre->fetch();
  $requeteLivre->closeCursor();
  // S'il n'y a pas de livre qui correspond à l'id, on retourne la page d'erreur (voir catch dans index).
  if (!$livre) {
    throw new Exception('Identifiant de livre non valide');
  }

  // Récupère les pages du livre de la BD.
  $requetePages = ModelePages::ObtenirPagesParLivreId($_GET['id']);

  // Affiche le livre.
  // La vue va utiliser les variables $livre et $requetePages
  require_once 'vue/livre.php';
}

function afficherPublierLivre()
{
  // Récupère toutes les catégories de la BD.
  $requeteCategories = ModeleCategories::ObtenirCategories();

  require_once 'vue/publierLivre.php';
}

function validerDonneesNouveauLivre()
{
  $resultat = [];

  if (
    !isset($_POST['titre']) ||
    !is_string($_POST['titre']) ||
    strlen($_POST['titre']) < 3 ||
    strlen($_POST['titre']) > 255
  ) {
    $resultat[] = 'Le titre est obligatoire et doit être une string entre 3 et 255 caractères.';
  }

  if (
    !isset($_POST['description']) ||
    !is_string($_POST['description']) ||
    strlen($_POST['description']) < 3 ||
    strlen($_POST['description']) > 65535
  ) {
    $resultat[] =  "La description est obligatoire et doit être une string entre 3 et 65535 caractères";
  }

  if (
    !isset($_POST['categories']) ||
    !is_array($_POST['categories']) ||
    sizeof($_POST['categories']) < 1
  ) {
    $resultat[] = "Au moins une catégorie est requise.";
  } else {
    foreach ($_POST['categories'] as $categorie) {
      if (filter_var($categorie, FILTER_VALIDATE_INT) === false) {
        $resultat[] =  "Le tableau de catégories doit avoir des id de catégorie.";
      }
    }
  }

  if (
    !isset($_FILES['imageCouverture']) ||
    is_array($_FILES['imageCouverture']['name'])
  ) {
    $resultat[] = 'L\'image de couverture est obligatoire.';
  }

  if (
    !isset($_FILES['pages']) ||
    !is_array($_FILES['pages']['name'])
  ) {
    $resultat[] = 'Au moins une page est requise.';
  }

  return $resultat;
}

function ajouterLivre()
{
  // Validation si l'utilisateur est connecté
  if (!isset($_SESSION['utilisateur'])) {
    header('Location: index.php?connexion');
    return;
  }

  // Validation des données reçues.
  $erreursDonnees = validerDonneesNouveauLivre();
  if (sizeof($erreursDonnees) > 0) {
    header('Location: index.php?ressource=/publier-livre&erreur=Veuillez fournir tous les éléments du livre.');
    return;
  }

  $cheminVersImagesLivre = obtenirCheminFichierUnique('televersement/');
  $typesAuthorises = ['image/png' => 'png', 'image/jpeg' => 'jpg'];
  $tailleMaximaleMB = 5;
  mkdir($cheminVersImagesLivre['chemin']);

  $infosImageCouverture = preparerFichiersPourTeleversement('imageCouverture', $cheminVersImagesLivre['chemin'], $tailleMaximaleMB, $typesAuthorises);
  $infoPages = preparerFichiersPourTeleversement('pages', $cheminVersImagesLivre['chemin'], $tailleMaximaleMB, $typesAuthorises);

  // S'il y a eu une erreur lors du traitement des images.
  if (
    isset($infosImageCouverture['erreur']) ||
    isset($infoPages['erreur'])
  ) {
    // On supprime les images qui ont fonctionnées.
    removeDir($cheminVersImagesLivre['chemin']);

    // On redirige vers la page de publication et on affiche l'erreur.
    $msgErreur = $infosImageCouverture['erreur'];
    if (!isset($infosImageCouverture['erreur'])) {
      $msgErreur = $infoPages['erreur'];
    }
    header('Location: index.php?ressource=/publier-livre&erreur=' . $msgErreur);
    return;
  }

  $idLivre = ModeleLivres::AjouterLivres($_SESSION['utilisateur']['id'], $_POST['titre'], $_POST['description'], $infosImageCouverture[0]['nomComplet'], $cheminVersImagesLivre['nom'] . '/');
  for ($i = 0; $i < sizeof($_POST['categories']); $i++) {
    ModeleLivres::AjouterCategoriesLivres($idLivre, $_POST['categories'][$i]);
  }
  for ($i = 0; $i < sizeof($infoPages); $i++) {
    ModelePages::AjouterPage($idLivre, $infoPages[$i]['nomComplet']);
  }

  header('Location: index.php?ressource=/livres/{id}&id=' . $idLivre);
}

function telechargerCouvertureLivreParId()
{
  if (!isset($_GET['id'])) {
    http_response_code(400);
    return;
  }

  $requeteLivre = ModeleLivres::ObtenirLivreParId($_GET['id']);
  $livre = $requeteLivre->fetch();
  $requeteLivre->closeCursor();

  if (!$livre) {
    http_response_code(404);
    return;
  }

  $nomFichier = $livre['image_couverture'];
  $cheminFichier = 'televersement/' . $livre['dossier']  . $nomFichier;

  telechargerFichier($cheminFichier, $nomFichier);
}
