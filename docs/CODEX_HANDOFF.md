# Relève complète Codex - McConnery

Dernière mise à jour de cette relève : **18 août 2026**.

Ce document est le point de départ d'un nouveau chat Codex ou d'un nouvel
ordinateur. Il décrit le dépôt local, l'installation cPanel connue, le site
Joomla, la PWA, les notifications Web Push, la migration des avis de décès et
la modération des messages de sympathie.

> Important : les chemins et comportements de production ci-dessous proviennent
> des déploiements et vérifications réalisés pendant le projet. Comme le serveur
> peut changer sans modifier ce dépôt, le prochain Codex doit revérifier cPanel,
> les URL publiques et `git status` avant d'affirmer l'état actuel du site.

## 1. Démarrage sur le nouvel ordinateur

1. Récupérer le dépôt :

   ```bash
   git clone https://github.com/UrbanCS/mcconnery-app.git
   cd mcconnery-app
   ```

2. Lire `AGENTS.md`, puis ce document au complet.
3. Exécuter :

   ```bash
   git status --short --branch
   git remote -v
   ```

4. Vérifier que les changements locaux décrits à la section 15 ont aussi été
   transférés. Ils ne sont pas nécessairement présents sur GitHub.
5. Ne jamais demander ni coller les secrets de production dans le chat. Les
   vérifier directement dans cPanel au besoin.

Prompt conseillé pour le nouveau chat :

```text
Lis AGENTS.md et docs/CODEX_HANDOFF.md en entier. Vérifie ensuite git status.
Ne modifie rien avant de m'avoir résumé l'état local, l'état de production à
revérifier et les changements non commités à préserver.
```

### Transfert le plus sûr

Le dépôt Git distant ne contient pas automatiquement les fichiers modifiés ou
non suivis. Avant de quitter cet ordinateur, utiliser une de ces méthodes :

- copier le dossier complet `mcconnery-app` vers le nouvel ordinateur; ou
- faire relire, commiter et pousser volontairement les changements locaux.

Le dossier est actuellement sous OneDrive, mais il faut tout de même comparer
`git status` sur les deux ordinateurs. Ne jamais ajouter les configurations
réelles au commit.

## 2. Périmètre du projet

Le système comprend :

- le nouveau site public Joomla : `https://mcconnery.ca`;
- la page des avis : `https://mcconnery.ca/index.php/avis-de-deces`;
- la PWA : `https://mcconnery.ca/pwa`;
- l'ancien site WordPress, encore utile comme source historique :
  `https://www.maisonfunerairemcconnery.ca`;
- une base PWA séparée qui contient les instantanés d'avis, les abonnements Push
  et les messages de sympathie;
- des scripts PHP de migration, réparation, configuration Joomla et CRON.

## 3. Dépôt et sources de vérité

Ordre de priorité pour diagnostiquer un problème :

1. état réel dans Joomla et ses tables;
2. configuration réelle de `pwa/config/config.php` sur cPanel;
3. code présent sur le serveur;
4. code de ce dépôt;
5. captures d'écran et historique de conversation.

Fichiers importants :

- `backend/lib/obituaries.php` : lecture Joomla, dates, URL, dédoublonnage,
  filtres récents et recherche;
- `backend/cron/check-obituaries.php` : détection périodique et notifications;
- `backend/api/obituaries.php` et `backend/api/obituary.php` : API PWA;
- `backend/lib/push.php` : envoi Web Push;
- `backend/lib/sympathy.php` et `backend/api/sympathy-messages.php` : messages de
  sympathie;
- `frontend/src/App.tsx` et `frontend/src/api.ts` : écrans PWA et chargement;
- `backend/migration/` : import, réparation et installation Joomla;
- `backend/migration/templates/mcconnery-sympathies-admin.php` : interface de
  modération;
- `docs/JOOMLA-AVIS-DE-DECES.md` : procédure opérateur Joomla.

## 4. Hébergement cPanel connu

Compte/racine observé :

```text
/home/mcconneryweb/domains/mcconnery.ca/public_html
```

Emplacements :

```text
Joomla : /home/mcconneryweb/domains/mcconnery.ca/public_html
PWA    : /home/mcconneryweb/domains/mcconnery.ca/public_html/pwa
Config : /home/mcconneryweb/domains/mcconnery.ca/public_html/pwa/config/config.php
```

Le fichier `config.php` de production contient des secrets et n'est pas dans
Git. Les fichiers ignorés incluent notamment :

```text
backend/config/config.php
backend/migration/config.php
backend/logs/*.log
backend/migration/output/*
```

Ne jamais remplacer le fichier de production par un exemple vide.

## 5. Déploiement de la PWA

Correspondance habituelle :

```text
frontend/dist/*       -> public_html/pwa/
backend/api/*         -> public_html/pwa/api/
backend/cron/*        -> public_html/pwa/cron/
backend/lib/*         -> public_html/pwa/lib/
backend/bootstrap.php -> public_html/pwa/bootstrap.php
backend/migration/*   -> public_html/pwa/migration/ (seulement si requis)
vendor/*              -> public_html/pwa/vendor/ si Composer est livré ainsi
```

Le `frontend/dist/` doit être déployé comme un ensemble : `index.html`, les
fichiers publics et tout le dossier d'assets hachés. Un téléversement partiel
peut produire un écran vide ou servir une ancienne version.

Après déploiement :

1. ouvrir `/pwa/` dans une fenêtre privée;
2. faire un rechargement forcé;
3. vérifier la console et l'onglet Réseau;
4. vérifier le service worker et le manifeste;
5. tester Accueil, Avis, recherche, détail, Contact et sympathies;
6. tester sur téléphone installé, car le cache PWA diffère du navigateur.

La PWA utilise le logo McConnery et la couleur principale `#696941`, y compris
les boutons, la navigation active et la couleur de la barre système.

## 6. Configuration de production de la PWA

Valeurs structurelles attendues, sans secrets :

```php
'APP_BASE_URL' => 'https://mcconnery.ca/pwa',
'CURRENT_SITE_URL' => 'https://mcconnery.ca',
'FINAL_SITE_URL' => 'https://mcconnery.ca',
'OBITUARY_SOURCE' => 'joomla_db',
'JOOMLA_CATEGORY_ID' => 18,
'CRON_NOTIFY_ON_FIRST_RUN' => false,
```

La configuration Joomla directe doit aussi contenir les bonnes valeurs :

```text
JOOMLA_DB_HOST
JOOMLA_DB_NAME
JOOMLA_DB_USER
JOOMLA_DB_PASS
JOOMLA_TABLE_PREFIX
JOOMLA_CATEGORY_ID
```

Le nom de base et l'utilisateur peuvent être identiques sur cPanel, mais ce
n'est pas une règle. Les lire dans le `configuration.php` Joomla ou l'interface
cPanel et ne jamais les deviner.

Autres secrets à conserver uniquement sur le serveur : clés VAPID publique et
privée, secret CRON, secret de test Push et mots de passe des bases.

## 7. Avis de décès Joomla

Environ 1 867 avis ont d'abord été importés depuis WordPress; les réparations
ultérieures ont vérifié **1 884 articles** Joomla. Ces nombres sont historiques
et peuvent augmenter.

La catégorie Joomla des avis est l'ID `18`. Le lien de menu configuré pendant
le projet était le menu `#380`, titre `AVIS DE DÉCÈS`, alias
`avis-de-deces`.

Affichage voulu :

- 50 avis par page;
- grille de 4 colonnes;
- ordre décroissant par vraie date de décès;
- nom, photo et date visibles;
- résumé masqué dans la grille;
- clic sur l'avis vers le bon article Joomla;
- photo contenue et non agrandie à toute la largeur;
- barre de recherche au-dessus de la grille.

Commande de configuration :

```bash
cd ~/domains/mcconnery.ca/public_html/pwa
php migration/configure-joomla-obituaries.php
php migration/configure-joomla-obituaries.php --apply --append-css
```

Le CSS a été ajouté à :

```text
/home/mcconneryweb/domains/mcconnery.ca/public_html/templates/ut_seguro/css/template.css
```

Une mise à jour de Helix Ultimate/JCE/Joomla peut modifier le template ou ses
règles. Si l'affichage change, comparer le CSS puis relancer le script et vider
le cache, sans écraser les ajouts du webmestre.

## 8. Import WordPress vers Joomla

Procédure courte pour de nouveaux avis encore présents sur l'ancien site :

```bash
cd ~/domains/mcconnery.ca/public_html/pwa
php migration/export-wordpress-rss.php --limit=10
php migration/import-joomla-articles.php \
  --file=migration/output/obituaries-rss.json --limit=10
php migration/import-joomla-articles.php \
  --file=migration/output/obituaries-rss.json --limit=10 --apply
```

La deuxième commande est un dry-run. Examiner les lignes `Import`, `Update`,
`Ignoré déjà importé` et les erreurs d'image avant `--apply`.

Des images historiques peuvent être indisponibles, retourner une erreur HTTP ou
être des PDF non pris en charge. Ne pas confondre une image ignorée avec l'échec
de l'article complet.

Après import ou modification importante :

```bash
cd ~/domains/mcconnery.ca/public_html
php cli/joomla.php finder:index
php cli/joomla.php cache:clean
```

## 9. Dates de décès et ordre

Pour les avis, le champ Joomla `publish_up` est utilisé comme date de décès. Le
libellé d'administration a été remplacé par « Date de décès » avec la constante
fr-FR Administration :

```text
COM_CONTENT_FIELD_PUBLISH_UP_LABEL
```

Ne pas utiliser les constantes Contact ou Module pour ce champ d'article.

Deux causes historiques d'erreurs :

- plusieurs articles importés avaient la date du lot d'importation au lieu de
  la date trouvée dans le texte;
- l'ancien identifiant WordPress ne correspond pas à l'ID d'article Joomla,
  ce qui pouvait ouvrir un avis complètement différent.

Les réparations se trouvent dans :

- `migration/fix-joomla-obituary-dates.php`;
- `backend/lib/obituaries.php`, qui extrait et valide la date du contenu;
- la table/journal de migration, qui associe l'ID source à l'article cible.

Toujours vérifier nom, vraie date dans le texte, image et URL cible sur quelques
avis récents et anciens après une modification de ce code.

## 10. Encodage et doublons de contenu

L'ancien site contenait du texte mal décodé (`dÃ©cès`, caractères de contrôle,
etc.) et certains articles Joomla contenaient deux fois l'introduction.

Script :

```bash
cd ~/domains/mcconnery.ca/public_html/pwa
php migration/fix-joomla-obituary-encoding.php
php migration/fix-joomla-obituary-encoding.php --apply
cd ~/domains/mcconnery.ca/public_html
php cli/joomla.php cache:clean
```

Dernier résultat historique connu :

```text
Articles vérifiés : 1884
Articles modifiés : 1607
Encodages détectés : 1607
Doublons intro retirés : 1606
```

Une recherche Joomla avec surbrillance peut couper visuellement un mot, par
exemple `Mar ie`, à cause des balises de surbrillance. Vérifier la page sans le
paramètre de recherche avant de conclure que le texte en base est corrompu.

## 11. PWA : liste récente, recherche et correspondance Joomla

Après avoir chargé toute l'histoire Joomla dans la base PWA, des doublons, de
fausses dates 2026 et de mauvaises destinations sont apparus. Le correctif
intentionnel est de n'exposer dans la PWA que le sous-ensemble récent et fiable.

Valeurs par défaut actuelles du dépôt :

```php
'CRON_FETCH_LIMIT' => 22,
'PWA_RECENT_MIN_DEATH_DATE' => '2026-01-01',
'PWA_RECENT_SOURCE_ID_MIN' => 2500,
'PWA_RECENT_EXCLUDE_TITLES' => ['Marie-Paule Hins Mahoney'],
'PWA_RECENT_FALLBACK_TITLES' => ['Rodolphe Huneault', 'Kenneth Gabie'],
'JOOMLA_SCAN_LIMIT' => 250,
```

Le nombre 22 est actuellement la taille du lot récent affiché et surveillé.
Lorsqu'un nouvel avis Joomla valide est publié, il entre dans ce lot et l'avis le
plus ancien en sort normalement : le total visible demeure donc généralement à
22. Pour faire croître la liste à 23, 24, etc., il faut modifier consciemment la
limite du front-end et celle du CRON, puis revérifier les performances et le
filtrage des avis historiques.

La recherche PWA doit rester limitée au même ensemble récent. Elle ne doit pas
faire réapparaître les quelque 1 800 avis historiques ni utiliser leurs dates de
lot. Le filtrage, le dédoublonnage et la construction d'URL sont centralisés dans
`backend/lib/obituaries.php`.

Exceptions historiques connues :

- `Marie-Paule Hins Mahoney` avait une date/rang incohérent et est exclue de la
  liste PWA récente;
- `Rodolphe Huneault` et `Kenneth Gabie` avaient besoin d'une récupération de
  secours pour rester dans l'ordre récent;
- `Raymond Lafrenière` est un décès de 2017 et ne doit pas être traité comme un
  avis récent de 2026.

Ne pas augmenter le CRON à `--limit=5000` en production courante. Cette commande
a servi au seed historique, pas au flux normal.

## 12. CRON et notifications Web Push

Tâche cPanel connue : toutes les 15 minutes.

```text
Minute : */15
Heure, jour, mois, semaine : *
```

Commande :

```bash
/usr/local/bin/php /home/mcconneryweb/domains/mcconnery.ca/public_html/pwa/cron/check-obituaries.php
```

Fonctionnement attendu :

1. le client publie un article dans la catégorie Joomla des avis;
2. le CRON lit les avis Joomla;
3. il compare les identifiants/instantanés déjà connus;
4. un avis réellement nouveau est enregistré;
5. les abonnés Push actifs reçoivent une notification;
6. la PWA affiche le nouvel avis.

Test de source sans notification :

```bash
cd ~/domains/mcconnery.ca/public_html/pwa
php cron/check-obituaries.php --seed-only --limit=22
```

Un seed historique de 1 859 éléments a déjà été fait sans notifications. Cette
opération explique pourquoi la base PWA peut contenir beaucoup plus de lignes
que la liste visible. Le premier seed doit rester silencieux grâce à
`CRON_NOTIFY_ON_FIRST_RUN=false`.

Une notification n'est pas garantie uniquement parce que l'article existe : le
CRON doit tourner, l'article doit être publié dans la bonne catégorie, la base
Joomla doit être joignable et l'abonnement Push doit encore être valide.

## 13. Messages de sympathie

Les messages historiques de l'ancien site ont été importés. La PWA et le site
Joomla utilisent la même API/table de sympathies.

Flux attendu :

1. un visiteur soumet un message sur le site ou dans la PWA;
2. le message est enregistré avec le statut `pending`;
3. il n'est pas affiché publiquement tant qu'il n'est pas approuvé;
4. un administrateur peut l'approuver, le modifier, le refuser ou le supprimer;
5. après approbation, il apparaît sur l'avis correspondant.

Installation/configuration :

```bash
cd ~/domains/mcconnery.ca/public_html/pwa
php migration/configure-joomla-sympathy-widget.php --apply
php migration/install-joomla-sympathy-admin.php --apply
php migration/install-joomla-sympathy-admin.php --frontend --apply
```

Import historique, toujours en dry-run d'abord :

```bash
php migration/import-sympathy-messages.php --limit=50
php migration/import-sympathy-messages.php --limit=50 --apply
```

Pour un avis précis :

```bash
php migration/import-sympathy-messages.php --source-id=2656
php migration/import-sympathy-messages.php --source-id=2656 --apply
```

Interfaces :

```text
Back-end : https://mcconnery.ca/administrator/mcconnery-sympathies.php
Front-end: https://mcconnery.ca/mcconnery-sympathies.php
Login FE : https://mcconnery.ca/index.php/administrateurs
```

La session front-end Joomla est distincte de la session `/administrator`. Le
client se connecte sur la page Administrateurs, puis ouvre l'interface front-end
de sympathies. L'interface back-end exige une session d'administration Joomla.

Le lien « Voir l'avis » doit utiliser l'article Joomla cible, pas l'ancien ID
WordPress. Si une mauvaise personne s'ouvre, inspecter la correspondance dans le
journal de migration avant de modifier l'URL à la main.

## 14. Édition Joomla en front-end

Le client utilise surtout le front-end Joomla parce qu'il est plus simple que le
panneau d'administration. Le bouton Modifier dépend :

- de la connexion front-end;
- des groupes et permissions de l'utilisateur;
- des droits Modifier/Modifier ses éléments/Modifier l'état de la catégorie;
- de l'affichage du template Helix/JCE.

Une mise à jour de Helix Ultimate ou JCE peut faire disparaître le bouton sans
supprimer les droits. Vérifier d'abord l'ACL, puis le rendu du template et enfin
le cache. Ne pas donner un accès Super User uniquement pour contourner un défaut
d'affichage.

## 15. Travail local non commité à préserver

État observé lors de la création de cette relève :

```text
 M backend/lib/http.php
 M backend/migration/configure-joomla-obituaries.php
 M docs/JOOMLA-AVIS-DE-DECES.md
?? backend/migration/fix-joomla-obituary-encoding.php
```

Résumé :

- `backend/lib/http.php` améliore la détection/réparation du mojibake, y compris
  les caractères C1 et le caractère de remplacement;
- `configure-joomla-obituaries.php` contient les règles CSS et paramètres de la
  grille/détail des avis après les changements Helix/JCE;
- `JOOMLA-AVIS-DE-DECES.md` documente les mises à jour du template et le bouton
  d'édition front-end;
- `fix-joomla-obituary-encoding.php` est le script de réparation massive ayant
  servi aux 1 884 articles.

Ne pas restaurer ou supprimer ces fichiers pour « nettoyer » Git. Il faut les
relire, les tester puis les commiter séparément si le propriétaire le demande.

## 16. Validations avant de déclarer le projet terminé

### Site Joomla

- page 1 : 4 colonnes, noms/photos/dates et pagination cohérents;
- ordre décroissant conforme à la vraie date dans le texte;
- un avis récent et plusieurs anciens ouvrent la bonne personne;
- aucun résumé visible dans la grille;
- détail : image à taille raisonnable, métadonnées sous le nom;
- caractères accentués corrects, sans intro dupliquée;
- recherche et index Smart Search fonctionnels;
- widget de sympathies visible et sans `Failed to fetch`.

### PWA

- chargement rapide de la liste récente;
- aucune répétition ni ancien avis injecté comme 2026;
- recherche limitée à l'ensemble récent attendu;
- détail, image et bouton Source vers le bon article;
- envoi de sympathie en attente de modération;
- navigation, logo, couleur `#696941`, manifeste et service worker;
- test sur téléphone installé après purge/rechargement du cache.

### Notifications

- CRON actif toutes les 15 minutes;
- exécution manuelle sans erreur;
- test avec un nouvel article de test contrôlé;
- une seule notification par nouvel avis;
- aucune notification envoyée pendant un seed ou une réparation historique.

### Modération

- accès front-end avec un compte front-end autorisé;
- accès back-end avec un compte administrateur;
- approbation, modification, refus et suppression;
- « Voir l'avis » ouvre la bonne fiche Joomla.

## 17. Ce qui n'est pas prouvé par ce document

Cette relève ne prouve pas à elle seule que :

- le CRON tourne encore aujourd'hui;
- les identifiants de production n'ont pas changé;
- les abonnements Push sont encore valides;
- le dernier code local a été téléversé;
- les mises à jour Joomla n'ont pas remplacé du CSS;
- GitHub contient les changements non commités.

Le prochain Codex doit distinguer clairement : **implémenté localement**,
**téléversé sur cPanel**, **testé par commande** et **vérifié sur le site public**.

## 18. Documents complémentaires

- `docs/DEPLOIEMENT-CPANEL.md`
- `docs/AUDIT-ARCHITECTURE.md`
- `docs/MIGRATION-WP-JOOMLA.md`
- `docs/JOOMLA-AVIS-DE-DECES.md`

En cas de contradiction, ce document décrit la relève la plus récente, mais le
code et l'état réel du serveur demeurent les sources de vérité finales.
