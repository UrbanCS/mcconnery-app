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

Le script suivant configure la page **Avis de deces** pour afficher 50 avis par page en grille, ordonner les avis par ligne de gauche a droite, classer les avis par date de deces du plus recent au plus ancien, enlever la liste de liens numerotes, masquer les resumes et grossir les photos.

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

Apres une mise a jour du template, Helix, JCE ou d'une extension qui touche l'affichage Joomla, relancer cette commande. Elle remet les dates visibles sous les noms, masque seulement les resumes en liste, conserve les photos en grille et force la date de publication comme date affichee.

Si le script ne trouve pas le bon menu, passer l'ID du lien de menu:

```bash
php migration/configure-joomla-obituaries.php --apply --append-css --itemid=380
```

## Bouton Modifier en front-end

Le bouton **Modifier** en front-end est gere par Joomla, pas par la PWA. Il apparait seulement si:

1. l'utilisateur est connecte au front-end;
2. le compte a les droits d'edition sur les articles/pages;
3. les options Joomla du contenu/template autorisent les icones ou l'edition front-end.

Si le bouton disparait apres une mise a jour Helix/Joomla, verifier d'abord les droits du compte et les options d'affichage des articles dans Joomla. Les scripts de la PWA ne retirent pas ce bouton.

## Recherche Joomla

Les avis importes directement en base de donnees ne sont pas toujours indexes automatiquement par Smart Search. Apres un import massif ou une correction, reconstruire l'index.

Depuis la racine Joomla sur cPanel:

```bash
cd ~/domains/mcconnery.ca/public_html
php cli/joomla.php finder:index
```

Le script de configuration affiche aussi la commande exacte avec le chemin de `JOOMLA_ROOT_PATH`.

## Afficher tous les avis dans la PWA

La page **Avis** de la PWA charge maintenant l'historique complet et inclut une barre de recherche par nom, date ou mot-cle.

Pour que la PWA puisse recuperer tous les avis du nouveau site Joomla, verifier que `backend/config/config.php` utilise Joomla comme source:

```php
'OBITUARY_SOURCE' => 'joomla_db',
```

Les valeurs `JOOMLA_DB_HOST`, `JOOMLA_DB_NAME`, `JOOMLA_DB_USER`, `JOOMLA_DB_PASS`, `JOOMLA_TABLE_PREFIX` et `JOOMLA_CATEGORY_ID` doivent aussi etre configurees.

Apres la bascule officielle vers Joomla, remplir la table PWA avec tout l'historique Joomla sans envoyer de notifications:

```bash
cd ~/domains/mcconnery.ca/public_html/pwa
php cron/check-obituaries.php --seed-only --limit=5000
```

Ensuite, garder le cron normal actif. Il verifiera les nouveaux avis Joomla et enverra les notifications seulement pour les avis qui n'existent pas encore dans la PWA:

```bash
/usr/local/bin/php /home/mcconneryweb/domains/mcconnery.ca/public_html/pwa/cron/check-obituaries.php
```

Frequence conseillee: toutes les 15 a 30 minutes.

## Importer les nouveaux avis de l'ancien site

Depuis le dossier `pwa` sur cPanel:

```bash
php migration/export-wordpress-rss.php --limit=10
php migration/import-joomla-articles.php --file=migration/output/obituaries-rss.json --limit=10
php migration/import-joomla-articles.php --file=migration/output/obituaries-rss.json --limit=10 --apply
```

Ne pas ajouter `--update-existing` pour les imports incrementaux.

## Importer les messages de sympathie

Les messages de l'ancien livre de sympathies peuvent etre importes dans la PWA. Les messages importes sont marques comme **approved**. Les nouveaux messages envoyes depuis la PWA ou le widget Joomla sont marques comme **pending** pour approbation.

Importer les messages d'un seul avis:

```bash
cd ~/domains/mcconnery.ca/public_html/pwa
php migration/import-sympathy-messages.php --source-id=2656
php migration/import-sympathy-messages.php --source-id=2656 --apply
```

Importer les messages pour les avis deja importes dans Joomla:

```bash
cd ~/domains/mcconnery.ca/public_html/pwa
php migration/import-sympathy-messages.php --limit=50
php migration/import-sympathy-messages.php --limit=50 --apply
```

## Moderer les messages de sympathie dans Joomla

Une interface de moderation peut etre installee dans l'administration Joomla. Elle affiche les messages en attente et permet de les **approuver**, **modifier**, **refuser** ou **supprimer** sans passer par phpMyAdmin.

Uploader les fichiers mis a jour dans `public_html/pwa`, puis depuis le dossier `pwa` sur cPanel:

```bash
php migration/install-joomla-sympathy-admin.php
php migration/install-joomla-sympathy-admin.php --apply
```

Le script installe le fichier suivant dans Joomla:

```text
administrator/mcconnery-sympathies.php
```

Ouvrir ensuite l'administration Joomla, puis aller directement a:

```text
https://mcconnery.ca/administrator/mcconnery-sympathies.php
```

Il faut etre connecte a Joomla avec un compte autorise. Les messages approuves passent au statut `approved` et deviennent visibles sur la PWA et sur les fiches Joomla.

Si le client se connecte seulement dans le front-end Joomla, installer plutot la version front-end de la page de moderation:

```bash
php migration/install-joomla-sympathy-admin.php --frontend --apply
```

Le script installe alors:

```text
mcconnery-sympathies.php
```

URL:

```text
https://mcconnery.ca/mcconnery-sympathies.php
```

Cette version verifie la session front-end Joomla et redirige vers `/index.php/administrateurs` si l'utilisateur n'est pas connecte. Elle est prevue pour les comptes Joomla de type administrateur/super utilisateur.

## Afficher les messages sur les fiches Joomla

Le widget `joomla-sympathy-widget.js` peut etre ajoute au template Joomla pour afficher les messages et le formulaire directement sur les pages d'articles **Avis de deces**. Il utilise la meme API que la PWA.

Depuis le dossier `pwa` sur cPanel:

```bash
php migration/configure-joomla-sympathy-widget.php
php migration/configure-joomla-sympathy-widget.php --apply
```

Le script ajoute ce fichier dans le template Joomla:

```text
https://mcconnery.ca/pwa/joomla-sympathy-widget.js
```

Le widget detecte automatiquement les avis importes dont l'alias se termine par l'ancien ID WordPress, par exemple `victoria-dumont-whiteduck-2656`.
Pour les avis crees directement dans Joomla sans ancien ID WordPress, le script du template passe aussi l'ID de l'article Joomla au widget. Les messages sont alors lies a un identifiant du type `joomla-1913`.

Si le widget a deja ete installe avant cette logique, reuploader `joomla-sympathy-widget.js`, puis relancer:

```bash
cd ~/domains/mcconnery.ca/public_html/pwa
php migration/configure-joomla-sympathy-widget.php --apply
```

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
