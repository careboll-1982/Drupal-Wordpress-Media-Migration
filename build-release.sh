#!/usr/bin/env bash
set -euo pipefail

project_dir="$(cd "$(dirname "$0")" && pwd)"
dist_dir="$project_dir/dist"

mkdir -p "$dist_dir"
rm -f "$dist_dir/media_transfer.zip" "$dist_dir/drupal-wordpress-media-migration.zip"

(cd "$project_dir/drupal" && zip -qr "$dist_dir/media_transfer.zip" media_transfer)
(cd "$project_dir/wordpress" && zip -qr "$dist_dir/drupal-wordpress-media-migration.zip" drupal-wordpress-media-migration)

echo "Release archives created in $dist_dir"
