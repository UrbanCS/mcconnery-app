# Consignes Codex - Projet McConnery

Avant toute analyse ou modification, lire en entier :

- `docs/CODEX_HANDOFF.md`
- `git status --short --branch`

Ce dépôt couvre trois systèmes liés : le site Joomla public `mcconnery.ca`, la
PWA sous `/pwa` et les outils de migration/modération exécutés sur cPanel.

## Règles obligatoires

- Ne jamais écraser, afficher ou commiter les fichiers de configuration réels,
  mots de passe, clés VAPID, secrets CRON ou identifiants Joomla/cPanel.
- Le fichier de production `pwa/config/config.php` est conservé sur le serveur.
  Ne jamais le remplacer par `config.example.php` pendant un déploiement.
- Ne pas annuler les changements locaux déjà présents. Les considérer comme du
  travail utilisateur jusqu'à preuve du contraire.
- Ne pas affirmer qu'un changement est en production sans l'avoir vérifié sur
  le serveur ou sur l'URL publique. Un test local ne prouve pas le déploiement.
- Pour les scripts de migration/réparation, exécuter d'abord le dry-run, examiner
  le résumé, puis ajouter `--apply` seulement après validation.
- Ne pas réintroduire les quelque 1 800 anciens avis dans la liste récente de la
  PWA. Le filtrage récent, le dédoublonnage et la correspondance des identifiants
  Joomla sont des protections intentionnelles.
- Après une mise à jour de Helix/JCE/Joomla, vérifier l'affichage des avis, les
  dates, le bouton d'édition en front-end, le widget de sympathies et le CSS du
  template. Une mise à jour peut modifier ou remplacer ces personnalisations.

## Déploiement

Le projet est hébergé sous :

`/home/mcconneryweb/domains/mcconnery.ca/public_html`

La PWA est sous :

`/home/mcconneryweb/domains/mcconnery.ca/public_html/pwa`

Toujours consulter le guide de relève pour les fichiers à téléverser, les
commandes cPanel, le CRON, les migrations Joomla et les validations requises.
