# WordPress plugin

Author: Carlos F. Rebolledo — <carlos@tuimagen.net>

## Requirements

- WordPress 6.0+
- PHP 8.0+
- A public URL for each source file during migration

## Installation

Zip the `drupal-wordpress-media-migration` directory or use the ZIP in the
repository's `dist` directory. Upload it under **Plugins → Add New → Upload
Plugin**, then activate **Drupal WordPress Media Migration**.

Open **Media → Drupal WordPress Migration**.

## Drupal → WordPress

Use the **Import from Drupal** tab. The importer runs 20 rows per browser
request and supports Stop/Resume, automatic failure pausing, detailed errors,
SHA-256 verification, and duplicate detection.

WP-CLI:

    wp media-migration import /tmp/media.csv --limit=100
    wp media-migration import /tmp/media.csv --offset=100 --limit=100

The manifest may also be a public HTTPS URL. Add `--dry-run` to validate rows or
`--insecure` only when a trusted source has a temporarily invalid certificate.

## WordPress → Drupal

Use the **Export for Drupal** tab to download all Media Library attachments.

WP-CLI:

    wp media-migration export /tmp/wordpress-media.csv

The exporter maps MIME types to `image`, `document`, `audio`, and `video`, and
includes public URLs, checksums, timestamps, titles, captions/descriptions, and
image alt text. Select matching destination media bundles in Drupal's importer.

## Duplicate handling

Drupal imports are matched in WordPress by Drupal media UUID, Drupal file UUID,
stored SHA-256, and local checksum. Existing matches are linked to the source
metadata instead of uploaded again.
