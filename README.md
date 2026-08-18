# PWA Maison Funeraire McConnery

MVP PWA mobile-first pour les avis de deces de Maison Funeraire McConnery.

Le projet est separe en deux parties:

- `frontend/`: React + TypeScript + Vite + Tailwind, build statique deployable sur cPanel.
- `backend/`: PHP 8 + MySQL/MariaDB, API legere, cron, notifications Web Push et scripts migration.

Documentation:

- [Relève complète pour un nouveau Codex](docs/CODEX_HANDOFF.md)
- [Audit et architecture](docs/AUDIT-ARCHITECTURE.md)
- [Deploiement cPanel](docs/DEPLOIEMENT-CPANEL.md)
- [Migration WordPress vers Joomla](docs/MIGRATION-WP-JOOMLA.md)

Demarrage local du frontend:

```bash
cd frontend
npm install
npm run dev
```

Build statique:

```bash
cd frontend
npm run build
```

Backend:

```bash
cd backend
cp config/config.example.php config/config.php
composer install --no-dev --optimize-autoloader
```

Importer `database/schema.sql` dans MySQL avant d'utiliser l'API.
