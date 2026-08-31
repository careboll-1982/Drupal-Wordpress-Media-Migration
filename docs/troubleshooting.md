# Troubleshooting

## Every row fails

Read the first detailed row error before continuing. Browser imports pause when
a complete batch fails. Common causes are:

- source URLs return 403 or 404;
- the source TLS certificate does not match its hostname;
- the destination rejects a file extension or exceeds an upload limit;
- the Drupal media bundle/source-field mapping is incorrect;
- downloaded bytes do not match the manifest checksum.

Fix the cause and upload the manifest again to retry rows already counted as
failed. Do not globally disable TLS verification.

## Drupal does not list the Drush commands

The module must be deployed and enabled. Rebuild caches, then run:

    drush list --filter=media-migration

Drush 12.5 or newer is required for the module's autowired command class.

## Files exist but are duplicated

Keep the identifier and checksum columns unchanged. Importers store source IDs
and SHA-256 values to make reruns idempotent. Manually editing these columns can
prevent matching.

## Private or protected files

The manifest does not carry authentication. Make files temporarily accessible
to the destination server, use signed URLs with sufficient lifetime, or copy
the files through a separately secured channel and customize the importer.

