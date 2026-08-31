# Drupal module

Author: Carlos F. Rebolledo — <carlos@tuimagen.net>

## Requirements

- Drupal 10 or 11
- Core File and Media modules
- Drush 12.5+ for command-line usage (browser workflows do not require Drush)

## Installation

Copy `media_transfer` to `web/modules/custom` or `docroot/modules/custom`, then
enable **Drupal WordPress Media Migration** on the Extend page.

Browser pages:

- `/admin/content/media-transfer/export`
- `/admin/content/media-transfer/import`

## Drush

Export Drupal media:

    drush media-migration:export /tmp/media.csv \
      --base-url=https://drupal.example.com

Alternatively set Drush's global `--uri=https://drupal.example.com` option and
omit `--base-url`.

Import WordPress attachments using standard bundle names:

    drush media-migration:import /tmp/media.csv --limit=100

Continue in batches:

    drush media-migration:import /tmp/media.csv --offset=100 --limit=100

Custom mappings:

    drush media-migration:import /tmp/media.csv \
      --mapping=image=photos:field_photo,document=files:field_file

Use `--dry-run` to validate metadata. Use `--insecure` only for a trusted source
with a temporarily invalid TLS certificate.

Imports use source identifiers and SHA-256 records to remain restart-safe.
Failures are shown by Drush and written to Drupal's media migration log channel.
