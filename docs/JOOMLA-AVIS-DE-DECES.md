# Avis de deces dans Joomla

## Ajouter un nouvel avis

1. Aller dans l'administration Joomla.
2. Ouvrir **Contenu** > **Articles** > **Nouveau**.
3. Mettre le nom de la personne dans **Titre**.
4. Choisir la categorie **Avis de deces**.
5. Ajouter le texte de l'avis dans le contenu de l'article.
6. Dans l'onglet **Images et liens**, choisir la photo dans **Image d'introduction** et, au besoin, dans **Image de l'article complet**.
7. Dans **Publication**, mettre la date du deces dans **Debut de publication**.
   Cette date sert a l'affichage et au classement des avis.
8. Laisser le statut a **Publie**.
9. Cliquer **Enregistrer & Fermer**.

## Reglages de la page liste

Le script suivant configure la page **Avis de deces** pour afficher 50 avis par page en grille, ordonner les avis par ligne, enlever la liste de liens numerotes, masquer les resumes et grossir les photos.

Depuis le dossier `pwa` sur cPanel:

```bash
php migration/configure-joomla-obituaries.php --apply --append-css
```

Dry-run avant modification:

```bash
php migration/configure-joomla-obituaries.php
```

Options utiles:

```bash
php migration/configure-joomla-obituaries.php --apply --append-css --intro=50 --leading=0 --columns=4 --links=0
```

Si le script ne trouve pas le bon menu, passer l'ID du lien de menu:

```bash
php migration/configure-joomla-obituaries.php --apply --append-css --itemid=380
```

## Recherche Joomla

Les avis importes directement en base de donnees ne sont pas toujours indexes automatiquement par Smart Search. Apres un import massif ou une correction, reconstruire l'index.

Depuis la racine Joomla sur cPanel:

```bash
cd ~/domains/mcconnery.ca/public_html
php cli/joomla.php finder:index
```

Le script de configuration affiche aussi la commande exacte avec le chemin de `JOOMLA_ROOT_PATH`.

## Importer les nouveaux avis de l'ancien site

Depuis le dossier `pwa` sur cPanel:

```bash
php migration/export-wordpress-rss.php --limit=10
php migration/import-joomla-articles.php --file=migration/output/obituaries-rss.json --limit=10
php migration/import-joomla-articles.php --file=migration/output/obituaries-rss.json --limit=10 --apply
```

Ne pas ajouter `--update-existing` pour les imports incrementaux.

## Faire apparaitre les avis importes dans le back-end Articles

Si les avis existent sur le site public, mais ne sortent pas dans **Contenu** > **Articles**, synchroniser les associations de workflow Joomla:

```bash
cd ~/domains/mcconnery.ca/public_html/pwa
php migration/import-joomla-articles.php --sync-workflows --apply
```

## Renommer "Debut de publication"

Pour aider le client, on peut renommer le libelle Joomla **Debut de publication** en **Date de deces** avec une substitution de traduction.

Dans Joomla:

1. Aller dans **Systeme** > **Langues** > **Substitutions**.
2. Choisir la langue **Administration** dans le filtre **Langue & Client**.
3. Cliquer **Nouveau**.
4. Utiliser la constante:
   ```text
   JGLOBAL_FIELD_PUBLISH_UP_LABEL
   ```
5. Mettre le texte:
   ```text
   Date de deces
   ```
6. Enregistrer.

Note: cette substitution change le libelle d'administration partout ou Joomla utilise ce champ de publication.
