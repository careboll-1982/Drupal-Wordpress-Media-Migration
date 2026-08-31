# CSV manifest

Both packages use the same UTF-8 CSV columns:

| Column | Purpose |
| --- | --- |
| `media_uuid` | Stable source-media identifier. Drupal exports a UUID; WordPress exports a site-scoped attachment identifier. |
| `bundle` | Suggested destination type: `image`, `document`, `audio`, or `video`. |
| `name` | Media/attachment label. |
| `status` | Published state (`1` or `0`). |
| `langcode` | Source language code. |
| `created` | Unix creation timestamp. |
| `source_field` | Suggested Drupal source field. |
| `file_uuid` | Stable source-file identifier when available. |
| `filename` | Original filename. |
| `mime_type` | MIME type. |
| `size` | Byte size. |
| `sha256` | Optional SHA-256 integrity and duplicate-detection value. |
| `download_url` | Absolute HTTP(S) source URL. |
| `alt` | Image alternative text. |
| `title` | File/image title. |
| `description` | Description or caption. |

Importers match by source identifiers and checksum before downloading. The
manifest contains metadata only; file bytes are fetched from `download_url`.
