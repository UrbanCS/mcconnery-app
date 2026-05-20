# Deploiement cPanel

## Prerequis

- Domaine en HTTPS.
- PHP 8.0 ou plus recent.
- MySQL/MariaDB.
- Extension PHP PDO, JSON, SimpleXML, DOM, OpenSSL.
- Composer localement ou sur cPanel pour installer `minishlink/web-push`.

## Etapes

1. Construire le frontend:

```bash
cd frontend
npm install
npm run build
```

2. Uploader le contenu de `frontend/dist/` vers:

```text
public_html/pwa/
```

3. Uploader le contenu de `backend/` vers le meme dossier:

```text
public_html/pwa/api/
public_html/pwa/cron/
public_html/pwa/config/
public_html/pwa/lib/
public_html/pwa/vendor/
public_html/pwa/bootstrap.php
```

4. Creer une base MySQL dans cPanel.

5. Importer `database/schema.sql` avec phpMyAdmin.

6. Copier la configuration:

```bash
cp public_html/pwa/config/config.example.php public_html/pwa/config/config.php
```

7. Remplir `config.php`:

- `DB_HOST`
- `DB_NAME`
- `DB_USER`
- `DB_PASS`
- `APP_BASE_URL=https://mcconnery.ca/pwa`
- `FINAL_SITE_URL=https://mcconnery.ca`
- `WORDPRESS_OBITUARY_FEED=https://www.maisonfunerairemcconnery.ca/feed/avis-de-deces-xml/`
- `CRON_SECRET`
- `NOTIFY_TEST_SECRET`
- cles VAPID

8. Installer les dependances PHP:

```bash
cd backend
composer install --no-dev --optimize-autoloader
```

Si Composer n'est pas disponible sur cPanel, executer cette commande localement puis uploader le dossier `backend/vendor/`.

9. Generer les cles VAPID:

```bash
cd backend
vendor/bin/web-push generate:vapid
```

Copier la cle publique et la cle privee dans `config.php`.

10. Tester la sante API:

```bash
curl https://mcconnery.ca/pwa/api/health.php
```

11. Tester l'abonnement depuis le telephone:

- Ouvrir `https://mcconnery.ca/pwa/`.
- Installer la PWA si necessaire.
- Cliquer sur "Activer les notifications".

12. Tester une notification:

```bash
curl -X POST https://mcconnery.ca/pwa/api/notify-test.php \
  -H 'Content-Type: application/json' \
  -H 'X-Notify-Test-Secret: VOTRE_SECRET'
```

13. Configurer le cron cPanel:

```bash
/usr/local/bin/php /home/ACCOUNT/public_html/pwa/cron/check-obituaries.php
```

Frequence conseillee: toutes les 15 a 30 minutes.

14. Tester le cron manuellement:

```bash
php public_html/pwa/cron/check-obituaries.php
```

Premier passage: le script alimente la table sans notification, sauf option `--notify-initial`.

15. Basculer vers Joomla apres lancement:

- Garder l'application sur `mcconnery.ca/pwa`.
- Configurer Joomla comme source future ou continuer a remplir `obituary_snapshots`.
- Mettre a jour `FINAL_SITE_URL`, `JOOMLA_API_BASE` et les URLs de contact.

## Compatibilite iPhone et Android

- Android Chrome supporte bien l'installation PWA et les notifications Web Push.
- iPhone supporte les notifications web pour les PWA installees sur l'ecran d'accueil, avec exigences iOS.
- Sur iPhone, demander a l'utilisateur d'ajouter la PWA a l'ecran d'accueil avant de s'attendre a une reception fiable.
- HTTPS est obligatoire.

## Securite

- Ne jamais exposer `VAPID_PRIVATE_KEY`.
- Garder `config.php` hors git.
- Proteger `notify-test.php` par `NOTIFY_TEST_SECRET`.
- Lancer les scripts migration seulement depuis CLI ou avec acces cPanel controle.
- Garder des sauvegardes DB avant chaque import.
