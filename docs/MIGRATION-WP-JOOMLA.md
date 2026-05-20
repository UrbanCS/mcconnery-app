# Migration WordPress vers Joomla

## Objectif

Importer les avis de deces existants du WordPress actuel vers Joomla sans perdre les textes, photos, dates, slugs et correspondances.

## Mapping recommande

| Source WordPress/Funeral Press | Cible Joomla |
| --- | --- |
| ID avis, ex. `2653` | `migration_logs.source_id`, alias article |
| Nom complet | `#__content.title` |
| Slug URL | `#__content.alias` si possible |
| Contenu necrologie | `#__content.fulltext` |
| Extrait | `#__content.introtext` |
| Date de deces | champ personnalise Joomla ou texte visible |
| Photo | `images/avis-de-deces/{source_id}/photo.ext` + `images` JSON |
| URL ancienne | fichier de redirections ou champ migration |

## Strategie recommandee

1. Sauvegarder WordPress et Joomla.
2. Creer une categorie Joomla "Avis de deces".
3. Faire un export test.
4. Importer 3 a 10 avis en dry-run puis en mode reel.
5. Verifier les photos, le contenu, les accents, les dates et les aliases.
6. Faire l'import complet.
7. Conserver les logs et le JSON d'origine.
8. Ajouter les redirections des anciennes URLs.

## Export par flux RSS

Utile pour les avis recents et pour valider le format.

```bash
php backend/migration/export-wordpress-rss.php --limit=50
```

Sorties:

```text
backend/migration/output/obituaries-rss.json
backend/migration/output/obituaries-rss.csv
```

## Export HTML public

Utile sans acces cPanel WordPress. Le script detecte les pages `pagenum` et visite les pages de detail.

Test avec quelques avis:

```bash
php backend/migration/export-wordpress-html.php --limit=5
```

Export complet:

```bash
php backend/migration/export-wordpress-html.php
```

Sorties:

```text
backend/migration/output/obituaries-html.json
backend/migration/output/obituaries-html.csv
```

## Export direct base WordPress

Copier la configuration:

```bash
cp backend/migration/config.example.php backend/migration/config.php
```

Remplir les valeurs `WORDPRESS_DB_*`, puis auditer les tables candidates:

```bash
php backend/migration/export-wordpress-db.php
```

Apres verification de la bonne table:

```bash
php backend/migration/export-wordpress-db.php --table=wp_nom_de_table --limit=5000
```

Cette methode est la plus propre si la table Funeral Press exacte est identifiee.

## Import Joomla

Dans `backend/migration/config.php`, remplir:

- `JOOMLA_DB_HOST`
- `JOOMLA_DB_NAME`
- `JOOMLA_DB_USER`
- `JOOMLA_DB_PASS`
- `JOOMLA_TABLE_PREFIX`
- `JOOMLA_CATEGORY_ID`
- `JOOMLA_ROOT_PATH`

Dry-run:

```bash
php backend/migration/import-joomla-articles.php \
  --file=backend/migration/output/obituaries-html.json \
  --limit=5
```

Import test reel:

```bash
php backend/migration/import-joomla-articles.php \
  --file=backend/migration/output/obituaries-html.json \
  --limit=5 \
  --apply
```

Import complet seulement apres validation:

```bash
php backend/migration/import-joomla-articles.php \
  --file=backend/migration/output/obituaries-html.json \
  --apply
```

## Images

- Le script tente de telecharger l'image vers `images/avis-de-deces/{source_id}/photo.ext`.
- Verifier les droits d'ecriture de `JOOMLA_ROOT_PATH/images`.
- Si les images WordPress sont des miniatures, preferer l'export HTML ou DB pour recuperer la version pleine taille.

## Redirections

Conserver un fichier de mapping:

```text
source_id, old_url, new_article_id, new_url
```

Options:

- composant Joomla de redirections;
- regles `.htaccess`;
- extension SEO/redirection.

## Controle qualite

- Comparer le nombre total d'avis exportes avec la pagination WordPress.
- Ouvrir au moins 10 avis importes aleatoirement.
- Verifier photos, accents, sauts de ligne, dates et liens.
- Garder `migration_logs` comme preuve de correspondance.
