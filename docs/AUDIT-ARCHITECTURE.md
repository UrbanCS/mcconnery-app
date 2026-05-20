# Audit et architecture

## 1. Audit de faisabilite

Questions obligatoires:

1. Le site WordPress expose-t-il une API REST exploitable?
   - Oui, `https://www.maisonfunerairemcconnery.ca/wp-json/` repond en JSON.
   - La REST API standard est utile pour verifier WordPress, pages et medias.
   - Les avis de deces ne semblent pas exposes comme posts REST standards: `wp/v2/posts?search=Christiane` retourne une liste vide.

2. Existe-t-il un flux RSS des avis?
   - Oui, le HTML public expose `https://www.maisonfunerairemcconnery.ca/feed/avis-de-deces-xml/`.
   - Ce flux contient `title`, `first_name`, `last_name`, `death_date`, `photo`, `link`, `pubDate`, `description` et `content:encoded`.
   - C'est la meilleure source temporaire pour detecter les nouveaux avis.

3. Les avis sont-ils des posts, custom post types ou contenus de plugin?
   - Le site utilise WordPress avec le plugin `wp-funeral-press` et `wp-funeral-home-cemetery`.
   - Les URLs publiques sont du type `/avis-de-deces/christiane-petrin/2653/`.
   - Les avis ne ressortent pas comme articles standards via REST. Ils sont tres probablement geres par le plugin Funeral Press, probablement dans des tables plugin ou via une route interne.

4. Peut-on extraire les images correctement?
   - Oui. Le flux RSS contient une balise `photo`.
   - La page de detail contient aussi une image pleine taille dans `.wpfh_obit_image a[href]` et une miniature dans l'image.
   - Pour la migration definitive, preferer la photo pleine taille depuis le HTML ou la base WordPress.

5. Quelle est la meilleure methode d'import vers Joomla?
   - Methode preferee si acces cPanel/DB: export direct des tables WordPress/Funeral Press, puis import en articles Joomla dans une categorie "Avis de deces".
   - Methode sure sans acces DB: crawl HTML public pagine, export JSON/CSV, validation manuelle, import Joomla progressif.
   - Methode RSS: bonne pour le MVP et les nouveaux avis, insuffisante seule pour l'historique complet si le flux reste limite aux avis recents.

6. Quelle est la meilleure methode de notification sur cPanel?
   - Web Push API cote navigateur + VAPID.
   - Stockage MySQL des abonnements.
   - Envoi par PHP avec `minishlink/web-push`.
   - Cron cPanel qui execute `backend/cron/check-obituaries.php`.
   - Si Composer n'est pas disponible sur cPanel, installer les dependances localement et uploader `backend/vendor/`.

7. Quelle architecture recommander pour un MVP fiable?
   - PWA statique React/Vite servie sous `https://mcconnery.ca/pwa/`.
   - API PHP sous `https://mcconnery.ca/pwa/api/`.
   - Table MySQL locale comme source stable de l'application.
   - Cron qui lit temporairement le flux RSS WordPress et alimente la table locale.
   - Apres lancement Joomla, basculer `OBITUARY_SOURCE` vers une API/table Joomla ou continuer a alimenter la table dediee.

## 2. Architecture finale choisie

Frontend:

- React + TypeScript + Vite.
- Tailwind CSS.
- Manifest PWA et service worker manuel.
- Build statique deployable dans cPanel.
- Aucune dependance Node.js en production.

Backend:

- PHP 8 procedural, sans framework.
- PDO MySQL/MariaDB.
- Endpoints dans `backend/api`.
- Cron dans `backend/cron`.
- Notifications avec `minishlink/web-push`.

Donnees:

- `obituary_snapshots`: cache local des avis.
- `push_subscriptions`: abonnements navigateurs.
- `notification_logs`: historique d'envoi.
- `migration_logs`: correspondance et audit d'import.

## 3. Strategie PWA

- Interface mobile-first.
- Navigation simple: Accueil, Avis, Detail, Contact.
- Les boutons sont larges et lisibles.
- Les avis sont lus depuis l'API PHP, pas directement depuis WordPress.
- Le service worker met seulement en cache le shell statique. Les appels API restent reseau-first pour eviter les avis obsoletes.

## 4. Strategie notifications

- La permission est demandee uniquement apres clic sur "Activer les notifications".
- Le frontend recupere la cle publique VAPID via `/api/public-config.php`.
- L'abonnement navigateur est enregistre par `/api/subscribe.php`.
- Le cron detecte les nouveaux avis, evite les doublons grace a `source_id`, puis envoie une notification.
- Les abonnements expires sont desactives quand la librairie Web Push signale une expiration.
- `/api/notify-test.php` permet un test protege par secret.

## 5. Strategie migration WordPress vers Joomla

Priorite recommandee:

1. Sauvegarder WordPress et Joomla.
2. Auditer les tables Funeral Press avec `export-wordpress-db.php` si l'acces DB est disponible.
3. Sinon utiliser `export-wordpress-html.php` pour produire un JSON complet depuis les pages publiques.
4. Faire un import test de 3 a 10 avis dans une categorie Joomla dediee.
5. Verifier titres, dates, photos, contenu et aliases.
6. Faire l'import complet.
7. Conserver le fichier JSON source et `migration_logs`.
8. Creer des redirections depuis les anciennes URLs vers les nouveaux articles Joomla si possible.

## 6. Risques techniques

- iOS exige une PWA installee sur l'ecran d'accueil pour recevoir les notifications web dans plusieurs cas.
- Les notifications exigent HTTPS.
- Le flux RSS public peut etre limite aux avis recents.
- Le schema exact des tables Funeral Press doit etre valide sur cPanel avant l'import DB.
- L'import direct Joomla doit etre teste avec une petite serie, car Joomla peut varier selon version, extensions et champs personnalises.
- Les images WordPress peuvent etre des miniatures generees. Le script HTML tente de prendre la photo pleine taille.

## 7. Arborescence du projet

```text
frontend/
  public/
    icon.svg
    manifest.webmanifest
    sw.js
  src/
    App.tsx
    api.ts
    push.ts
    types.ts
    main.tsx
    styles.css
backend/
  api/
    obituaries.php
    obituary.php
    public-config.php
    subscribe.php
    unsubscribe.php
    notify-test.php
    health.php
  config/
    config.example.php
  cron/
    check-obituaries.php
  lib/
    database.php
    dates.php
    http.php
    obituaries.php
    push.php
  migration/
    config.example.php
    export-wordpress-rss.php
    export-wordpress-html.php
    export-wordpress-db.php
    import-joomla-articles.php
database/
  schema.sql
docs/
```
