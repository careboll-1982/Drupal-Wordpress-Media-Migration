# Drupal WordPress Media Migration

A free, open-source toolkit for moving Media Library assets in either direction:

- Drupal media → WordPress attachments
- WordPress attachments → Drupal media

It transfers files through a shared CSV manifest, verifies SHA-256 checksums,
preserves common metadata, detects duplicates, supports restartable batches,
and provides both browser interfaces and command-line workflows.

## Packages

- `drupal/media_transfer` — Drupal 10/11 module
- `wordpress/drupal-wordpress-media-migration` — WordPress 6.x plugin
- `dist/` — installable ZIP archives generated for releases

Install only the package needed on each endpoint, or install both when sites
must exchange media in both directions.

## Drupal → WordPress

1. Install and enable the Drupal module.
2. Open **Content → Media migration → Export**, or run:

       drush media-migration:export /tmp/media.csv --base-url=https://drupal.example.com

3. Install and activate the WordPress plugin.
4. Open **Media → Drupal WordPress Migration**, upload the CSV, and start the
   restartable browser import; or run:

       wp media-migration import /tmp/media.csv

## WordPress → Drupal

1. In WordPress, open **Media → Drupal WordPress Migration → Export for Drupal**
   and download the manifest; or run:

       wp media-migration export /tmp/media.csv

2. In Drupal, open **Content → Media migration → Import**, select the target
   bundle/source-field mappings, and upload the manifest; or run:

       drush media-migration:import /tmp/media.csv

Standard Drupal defaults are `image/field_media_image`,
`document/field_media_document`, `audio/field_media_audio_file`, and
`video/field_media_video_file`. Sites with different machine names can provide
their own mapping in the Drupal import form or command options.

## Operational notes

- Source URLs must remain reachable until the import finishes.
- Imports are idempotent: reruns skip known identifiers and checksums.
- Browser imports pause automatically if an entire batch fails.
- Detailed row errors are shown in the UI and written to application logs.
- The invalid-certificate option reduces transport security. Use it only for a
  trusted source host while its certificate is being repaired.
- Delete public CSV manifests after migration.

See [the manifest specification](docs/manifest.md) and package READMEs for full
installation and command examples. See [troubleshooting](docs/troubleshooting.md)
when an import pauses or fails.

Ready to publish? Follow the [GitHub publishing steps](docs/publishing.md).

## Author

Carlos F. Rebolledo — <carlos@tuimagen.net>

## License

[GNU General Public License version 3](LICENSE) (GPL-3.0-only)
