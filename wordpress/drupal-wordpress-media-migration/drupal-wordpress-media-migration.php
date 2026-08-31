<?php
/**
 * Plugin Name: Drupal WordPress Media Migration
 * Description: Bidirectional CSV-based media migration between Drupal and WordPress.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Author: Carlos F. Rebolledo
 * Author URI: mailto:carlos@tuimagen.net
 * License: GPLv3
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: drupal-wordpress-media-migration
 */

if (!defined('ABSPATH')) {
    exit;
}

final class DWWMM_Migration {
    private static ?array $existing_checksum_index = null;

    public static function register_admin(): void {
        add_media_page(
            'Drupal WordPress Migration',
            'Drupal WordPress Migration',
            'upload_files',
            'drupal-wordpress-media-migration',
            [self::class, 'render_admin_page']
        );
    }

    public static function render_admin_page(): void {
        if (!current_user_can('upload_files')) {
            wp_die(esc_html__('You are not allowed to upload files.', 'drupal-wordpress-media-migration'));
        }
        $job_token = isset($_GET['job']) ? sanitize_key(wp_unslash($_GET['job'])) : '';
        $view = isset($_GET['view']) ? sanitize_key(wp_unslash($_GET['view'])) : 'import';
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Drupal WordPress Media Migration', 'drupal-wordpress-media-migration'); ?></h1>
            <nav class="nav-tab-wrapper">
                <a class="nav-tab <?php echo $view === 'import' ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url(add_query_arg(['page' => 'drupal-wordpress-media-migration', 'view' => 'import'], admin_url('upload.php'))); ?>"><?php echo esc_html__('Import from Drupal', 'drupal-wordpress-media-migration'); ?></a>
                <a class="nav-tab <?php echo $view === 'export' ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url(add_query_arg(['page' => 'drupal-wordpress-media-migration', 'view' => 'export'], admin_url('upload.php'))); ?>"><?php echo esc_html__('Export for Drupal', 'drupal-wordpress-media-migration'); ?></a>
            </nav>
            <?php if ($view === 'export') : ?>
                <p><?php echo esc_html__('Download a CSV manifest containing all WordPress Media Library attachments. Drupal will fetch the files from their current public URLs.', 'drupal-wordpress-media-migration'); ?></p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="dwwmm_media_export">
                    <?php wp_nonce_field('dwwmm_media_export'); ?>
                    <?php submit_button(__('Download CSV for Drupal', 'drupal-wordpress-media-migration')); ?>
                </form>
            <?php elseif (!$job_token) : ?>
                <p><?php echo esc_html__('Upload a CSV exported by the Drupal module. Files are downloaded by WordPress in restartable batches.', 'drupal-wordpress-media-migration'); ?></p>
                <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="dwwmm_media_upload">
                    <?php wp_nonce_field('dwwmm_media_upload'); ?>
                    <table class="form-table"><tbody><tr>
                        <th scope="row"><label for="dwwmm-media-csv"><?php echo esc_html__('Drupal media CSV', 'drupal-wordpress-media-migration'); ?></label></th>
                        <td><input id="dwwmm-media-csv" name="media_csv" type="file" accept=".csv,text/csv" required></td>
                    </tr><tr>
                        <th scope="row"><?php echo esc_html__('SSL verification', 'drupal-wordpress-media-migration'); ?></th>
                        <td><label><input name="allow_insecure_ssl" type="checkbox" value="1"> <?php echo esc_html__('Allow an invalid SSL certificate for source downloads', 'drupal-wordpress-media-migration'); ?></label>
                        <p class="description"><?php echo esc_html__('Use only for a trusted source whose certificate hostname is known to be misconfigured. Verification remains enabled for every other WordPress request.', 'drupal-wordpress-media-migration'); ?></p></td>
                    </tr></tbody></table>
                    <?php submit_button(__('Upload and start import', 'drupal-wordpress-media-migration')); ?>
                </form>
            <?php else : ?>
                <div id="dwwmm-import-progress" data-job="<?php echo esc_attr($job_token); ?>">
                    <p><strong id="dwwmm-import-status"><?php echo esc_html__('Starting import…', 'drupal-wordpress-media-migration'); ?></strong></p>
                    <progress id="dwwmm-import-bar" value="0" max="100" style="width: min(700px, 100%);"></progress>
                    <p id="dwwmm-import-counts"></p>
                    <p>
                        <button type="button" class="button" id="dwwmm-import-toggle"><?php echo esc_html__('Stop after current batch', 'drupal-wordpress-media-migration'); ?></button>
                    </p>
                    <div id="dwwmm-import-errors" style="display:none; max-width:1000px;">
                        <h2><?php echo esc_html__('Import errors', 'drupal-wordpress-media-migration'); ?></h2>
                        <p><?php echo esc_html__('The newest errors are shown first. The same details are also written to the WordPress PHP error log.', 'drupal-wordpress-media-migration'); ?></p>
                        <ol id="dwwmm-import-error-list" style="font-family:monospace; white-space:pre-wrap;"></ol>
                    </div>
                </div>
                <script>
                (() => {
                    const box = document.getElementById('dwwmm-import-progress');
                    const status = document.getElementById('dwwmm-import-status');
                    const counts = document.getElementById('dwwmm-import-counts');
                    const bar = document.getElementById('dwwmm-import-bar');
                    const errorsBox = document.getElementById('dwwmm-import-errors');
                    const errorsList = document.getElementById('dwwmm-import-error-list');
                    const toggle = document.getElementById('dwwmm-import-toggle');
                    let stopped = false;
                    let requestRunning = false;
                    const body = new URLSearchParams({
                        action: 'dwwmm_media_process',
                        nonce: <?php echo wp_json_encode(wp_create_nonce('dwwmm_media_process')); ?>,
                        job: box.dataset.job
                    });
                    toggle.addEventListener('click', () => {
                        stopped = !stopped;
                        toggle.textContent = stopped ? 'Resume import' : 'Stop after current batch';
                        if (stopped) {
                            status.textContent = requestRunning ? 'Stopping after the current batch…' : 'Import paused.';
                        } else {
                            status.textContent = 'Resuming import…';
                            nextBatch();
                        }
                    });
                    async function nextBatch() {
                        if (stopped || requestRunning) return;
                        requestRunning = true;
                        try {
                            const response = await fetch(ajaxurl, {
                                method: 'POST',
                                credentials: 'same-origin',
                                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                                body
                            });
                            const payload = await response.json();
                            if (!payload.success) throw new Error(payload.data?.message || 'Import request failed.');
                            const data = payload.data;
                            requestRunning = false;
                            bar.value = data.percent;
                            counts.textContent = `Created: ${data.created} · Existing/skipped: ${data.skipped} · Failed: ${data.failed}`;
                            status.textContent = data.done ? 'Import complete.' : `Processed ${data.processed} of ${data.total} CSV rows…`;
                            if (data.errors?.length) {
                                errorsBox.style.display = 'block';
                                errorsList.replaceChildren(...data.errors.map(error => {
                                    const item = document.createElement('li');
                                    item.textContent = `CSV row ${error.row} · ${error.name || '(unnamed)'}\n${error.message}\n${error.url || '(no URL)'}`;
                                    return item;
                                }));
                            }
                            if (data.batch_all_failed && !data.done) {
                                stopped = true;
                                toggle.textContent = 'Resume import';
                                status.textContent = 'Import automatically paused because the entire batch failed. Fix the error before resuming.';
                            } else if (!data.done && !stopped) {
                                window.setTimeout(nextBatch, 250);
                            }
                        } catch (error) {
                            requestRunning = false;
                            stopped = true;
                            toggle.textContent = 'Resume import';
                            status.textContent = `Import paused: ${error.message} Refresh this page to resume.`;
                        }
                    }
                    nextBatch();
                })();
                </script>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function handle_admin_export(): void {
        if (!current_user_can('upload_files')) {
            wp_die(esc_html__('You are not allowed to export media.', 'drupal-wordpress-media-migration'));
        }
        check_admin_referer('dwwmm_media_export');
        $temporary = trailingslashit(get_temp_dir()) . 'media-migration-' . bin2hex(random_bytes(16)) . '.csv';
        try {
            self::export_file($temporary);
            nocache_headers();
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="wordpress-media-' . gmdate('Y-m-d-His') . '.csv"');
            header('Content-Length: ' . filesize($temporary));
            readfile($temporary);
        } finally {
            if (file_exists($temporary)) {
                wp_delete_file($temporary);
            }
        }
        exit;
    }

    public static function export_file(string $output): array {
        $handle = fopen($output, 'wb');
        if ($handle === false) {
            throw new RuntimeException("Cannot open CSV for writing: {$output}");
        }
        $columns = [
            'media_uuid', 'bundle', 'name', 'status', 'langcode', 'created',
            'source_field', 'file_uuid', 'filename', 'mime_type', 'size', 'sha256',
            'download_url', 'alt', 'title', 'description',
        ];
        fputcsv($handle, $columns, ',', '"', '');
        $counts = ['exported' => 0, 'skipped' => 0];
        $page = 1;
        do {
            $ids = get_posts([
                'post_type' => 'attachment', 'post_status' => 'inherit',
                'fields' => 'ids', 'posts_per_page' => 200, 'paged' => $page++,
                'orderby' => 'ID', 'order' => 'ASC', 'no_found_rows' => true,
            ]);
            foreach ($ids as $attachment_id) {
                $row = self::build_export_row((int) $attachment_id);
                if ($row === null) {
                    $counts['skipped']++;
                    continue;
                }
                fputcsv($handle, array_map(static fn(string $column) => $row[$column] ?? '', $columns), ',', '"', '');
                $counts['exported']++;
            }
        } while (count($ids) === 200);
        fclose($handle);
        return $counts;
    }

    private static function build_export_row(int $attachment_id): ?array {
        $post = get_post($attachment_id);
        $url = wp_get_attachment_url($attachment_id);
        if (!$post || !$url) {
            return null;
        }
        $mime = (string) get_post_mime_type($attachment_id);
        $major = strtolower((string) strtok($mime, '/'));
        $bundle = in_array($major, ['image', 'audio', 'video'], true) ? $major : 'document';
        $source_fields = [
            'image' => 'field_media_image', 'document' => 'field_media_document',
            'audio' => 'field_media_audio_file', 'video' => 'field_media_video_file',
        ];
        $path = get_attached_file($attachment_id, true);
        $filename = $path ? basename($path) : basename((string) wp_parse_url($url, PHP_URL_PATH));
        $locale = determine_locale();
        $source_site = substr(hash('sha256', home_url('/')), 0, 16);
        return [
            'media_uuid' => 'wordpress-' . $source_site . '-attachment-' . $attachment_id,
            'bundle' => $bundle,
            'name' => $post->post_title ?: $filename,
            'status' => 1,
            'langcode' => strtolower((string) strtok($locale, '_')) ?: 'en',
            'created' => get_post_timestamp($post, 'date') ?: time(),
            'source_field' => $source_fields[$bundle],
            'file_uuid' => 'wordpress-' . $source_site . '-file-' . $attachment_id,
            'filename' => $filename,
            'mime_type' => $mime ?: 'application/octet-stream',
            'size' => $path && is_readable($path) ? filesize($path) : '',
            'sha256' => $path && is_readable($path) ? hash_file('sha256', $path) : '',
            'download_url' => $url,
            'alt' => (string) get_post_meta($attachment_id, '_wp_attachment_image_alt', true),
            'title' => $post->post_title,
            'description' => $post->post_content ?: $post->post_excerpt,
        ];
    }

    public static function handle_admin_upload(): void {
        if (!current_user_can('upload_files')) {
            wp_die(esc_html__('You are not allowed to upload files.', 'drupal-wordpress-media-migration'));
        }
        check_admin_referer('dwwmm_media_upload');
        if (empty($_FILES['media_csv']['tmp_name']) || !is_uploaded_file($_FILES['media_csv']['tmp_name'])) {
            wp_die(esc_html__('No CSV file was uploaded.', 'drupal-wordpress-media-migration'));
        }
        $original_name = sanitize_file_name((string) $_FILES['media_csv']['name']);
        if (strtolower(pathinfo($original_name, PATHINFO_EXTENSION)) !== 'csv') {
            wp_die(esc_html__('The uploaded file must use the .csv extension.', 'drupal-wordpress-media-migration'));
        }
        $token = bin2hex(random_bytes(24));
        $path = trailingslashit(get_temp_dir()) . 'drupal-media-' . $token . '.csv';
        if (!move_uploaded_file($_FILES['media_csv']['tmp_name'], $path)) {
            wp_die(esc_html__('WordPress could not store the uploaded CSV.', 'drupal-wordpress-media-migration'));
        }
        $total = 0;
        try {
            $total = self::count_csv_rows($path);
        } catch (Throwable $error) {
            wp_delete_file($path);
            wp_die(esc_html($error->getMessage()));
        }
        set_transient('dwwmm_media_job_' . $token, [
            'uid' => get_current_user_id(),
            'path' => $path,
            'total' => $total,
            'offset' => 0,
            'created' => 0,
            'skipped' => 0,
            'failed' => 0,
            'errors' => [],
            'allow_insecure_ssl' => !empty($_POST['allow_insecure_ssl']),
        ], DAY_IN_SECONDS);
        wp_safe_redirect(add_query_arg(['page' => 'drupal-wordpress-media-migration', 'job' => $token], admin_url('upload.php')));
        exit;
    }

    public static function process_admin_batch(): void {
        check_ajax_referer('dwwmm_media_process', 'nonce');
        if (!current_user_can('upload_files')) {
            wp_send_json_error(['message' => 'You are not allowed to upload files.'], 403);
        }
        $token = isset($_POST['job']) ? sanitize_key(wp_unslash($_POST['job'])) : '';
        $key = 'dwwmm_media_job_' . $token;
        $job = get_transient($key);
        if (!$job || (int) $job['uid'] !== get_current_user_id() || !is_readable($job['path'])) {
            wp_send_json_error(['message' => 'This import job is missing or expired.'], 404);
        }
        $batch_size = 20;
        $counts = self::import_file($job['path'], (int) $job['offset'], $batch_size, false, !empty($job['allow_insecure_ssl']));
        $job['offset'] = min((int) $job['total'], (int) $job['offset'] + $batch_size);
        foreach (['created', 'skipped', 'failed'] as $counter) {
            $job[$counter] += $counts[$counter];
        }
        if (!empty($counts['errors'])) {
            $job['errors'] = array_slice(array_merge($counts['errors'], $job['errors'] ?? []), 0, 100);
        }
        $done = $job['offset'] >= $job['total'];
        if ($done) {
            wp_delete_file($job['path']);
            delete_transient($key);
        } else {
            set_transient($key, $job, DAY_IN_SECONDS);
        }
        wp_send_json_success([
            'done' => $done,
            'processed' => $job['offset'],
            'total' => $job['total'],
            'percent' => $job['total'] ? round(($job['offset'] / $job['total']) * 100, 1) : 100,
            'created' => $job['created'],
            'skipped' => $job['skipped'],
            'failed' => $job['failed'],
            'errors' => $job['errors'] ?? [],
            'batch_all_failed' => $counts['failed'] === $batch_size && $counts['created'] === 0 && $counts['skipped'] === 0,
        ]);
    }

    private static function count_csv_rows(string $path): int {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Could not read the uploaded CSV.');
        }
        $headers = fgetcsv($handle, null, ',', '"', '');
        if (!$headers || array_diff(['media_uuid', 'name', 'filename', 'download_url'], $headers)) {
            fclose($handle);
            throw new UnexpectedValueException('CSV does not contain the required Drupal media columns.');
        }
        $total = 0;
        while (fgetcsv($handle, null, ',', '"', '') !== false) {
            $total++;
        }
        fclose($handle);
        return $total;
    }

    public static function import_file(string $csv_path, int $offset = 0, int $limit = 0, bool $dry_run = false, bool $allow_insecure_ssl = false): array {
        if (!is_readable($csv_path)) {
            throw new RuntimeException("CSV is not readable: {$csv_path}");
        }
        $handle = fopen($csv_path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Could not open CSV: {$csv_path}");
        }
        $headers = fgetcsv($handle, null, ',', '"', '');
        $required = ['media_uuid', 'name', 'filename', 'download_url'];
        if (!$headers || array_diff($required, $headers)) {
            fclose($handle);
            throw new UnexpectedValueException('CSV does not contain the required Drupal media columns.');
        }

        $counts = ['created' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => []];
        $data_row = 0;
        $processed = 0;
        while (($values = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $data_row++;
            if ($data_row <= $offset) {
                continue;
            }
            if ($limit > 0 && $processed >= $limit) {
                break;
            }
            $processed++;
            if (count($headers) !== count($values)) {
                $counts['failed']++;
                $error = [
                    'row' => $data_row + 1,
                    'name' => '',
                    'url' => '',
                    'message' => 'Column count does not match the CSV header.',
                ];
                $counts['errors'][] = $error;
                self::warning(self::format_error($error));
                continue;
            }
            $row = array_combine($headers, $values);
            try {
                $existing_id = self::find_existing($row);
                if ($existing_id) {
                    if (!$dry_run) {
                        self::link_drupal_metadata($existing_id, $row);
                    }
                    $counts['skipped']++;
                    continue;
                }
                if (!$dry_run) {
                    self::import_row($row, $allow_insecure_ssl);
                }
                $counts['created']++;
            } catch (Throwable $error) {
                $counts['failed']++;
                $detail = [
                    'row' => $data_row + 1,
                    'name' => sanitize_text_field((string) ($row['name'] ?? '')),
                    'url' => esc_url_raw((string) ($row['download_url'] ?? '')),
                    'message' => $error->getMessage(),
                ];
                $counts['errors'][] = $detail;
                self::warning(self::format_error($detail));
            }
        }
        fclose($handle);
        return $counts;
    }

    private static function find_existing(array $row): int {
        $lookups = [
            '_drupal_media_uuid' => (string) ($row['media_uuid'] ?? ''),
            '_drupal_file_uuid' => (string) ($row['file_uuid'] ?? ''),
            '_drupal_file_sha256' => strtolower((string) ($row['sha256'] ?? '')),
        ];
        foreach ($lookups as $meta_key => $meta_value) {
            if ($meta_value === '') {
                continue;
            }
            $ids = get_posts([
                'post_type' => 'attachment',
                'post_status' => 'inherit',
                'fields' => 'ids',
                'posts_per_page' => 1,
                'meta_key' => $meta_key,
                'meta_value' => $meta_value,
                'no_found_rows' => true,
            ]);
            if ($ids) {
                return (int) $ids[0];
            }
        }

        $checksum = $lookups['_drupal_file_sha256'];
        if ($checksum === '' || !preg_match('/^[a-f0-9]{64}$/', $checksum)) {
            return 0;
        }
        if (defined('WP_CLI') && WP_CLI) {
            $index = self::existing_checksum_index();
            return isset($index[$checksum]) ? (int) $index[$checksum] : 0;
        }
        return self::find_filename_checksum_match($checksum, (string) ($row['filename'] ?? ''));
    }

    private static function find_filename_checksum_match(string $checksum, string $filename): int {
        $filename = sanitize_file_name($filename);
        if ($filename === '') {
            return 0;
        }
        $ids = get_posts([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'fields' => 'ids',
            'posts_per_page' => 50,
            'meta_query' => [[
                'key' => '_wp_attached_file',
                'value' => '/' . $filename,
                'compare' => 'LIKE',
            ]],
            'no_found_rows' => true,
        ]);
        foreach ($ids as $attachment_id) {
            $path = get_attached_file((int) $attachment_id, true);
            if ($path && is_readable($path) && is_file($path) && hash_equals($checksum, hash_file('sha256', $path))) {
                return (int) $attachment_id;
            }
        }
        return 0;
    }

    private static function existing_checksum_index(): array {
        if (self::$existing_checksum_index !== null) {
            return self::$existing_checksum_index;
        }
        self::$existing_checksum_index = [];
        $page = 1;
        do {
            $ids = get_posts([
                'post_type' => 'attachment',
                'post_status' => 'inherit',
                'fields' => 'ids',
                'posts_per_page' => 200,
                'paged' => $page++,
                'orderby' => 'ID',
                'order' => 'ASC',
                'no_found_rows' => true,
            ]);
            foreach ($ids as $attachment_id) {
                $path = get_attached_file((int) $attachment_id, true);
                if (!$path || !is_readable($path) || !is_file($path)) {
                    continue;
                }
                $checksum = hash_file('sha256', $path);
                if ($checksum) {
                    self::$existing_checksum_index[$checksum] ??= (int) $attachment_id;
                }
            }
        } while (count($ids) === 200);
        return self::$existing_checksum_index;
    }

    private static function import_row(array $row, bool $allow_insecure_ssl = false): int {
        $url = esc_url_raw((string) $row['download_url']);
        if (!wp_http_validate_url($url)) {
            throw new UnexpectedValueException('download_url is not a valid public HTTP(S) URL.');
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $temporary = self::download_source_url($url, $allow_insecure_ssl);
        if (is_wp_error($temporary)) {
            throw new RuntimeException($temporary->get_error_message());
        }
        try {
            if (!empty($row['sha256']) && !hash_equals((string) $row['sha256'], hash_file('sha256', $temporary))) {
                throw new RuntimeException('Downloaded file checksum does not match the CSV.');
            }
            $file_array = [
                'name' => sanitize_file_name((string) $row['filename']),
                'tmp_name' => $temporary,
            ];
            $attachment_id = media_handle_sideload($file_array, 0, sanitize_text_field((string) $row['name']), [
                'post_title' => sanitize_text_field((string) ($row['title'] ?: $row['name'])),
                'post_content' => wp_kses_post((string) ($row['description'] ?? '')),
                'post_excerpt' => sanitize_text_field((string) ($row['description'] ?? '')),
                'post_date' => self::wordpress_date((string) ($row['created'] ?? '')),
            ]);
            if (is_wp_error($attachment_id)) {
                throw new RuntimeException($attachment_id->get_error_message());
            }
            $temporary = '';
            self::link_drupal_metadata((int) $attachment_id, $row);
            if (!empty($row['alt'])) {
                update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field((string) $row['alt']));
            }
            return (int) $attachment_id;
        } finally {
            if ($temporary && file_exists($temporary)) {
                wp_delete_file($temporary);
            }
        }
    }

    public static function download_source_url(string $url, bool $allow_insecure_ssl = false) {
        if (!$allow_insecure_ssl) {
            return download_url($url, 300);
        }
        $target_host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        $filter = static function (array $args, string $request_url) use ($target_host): array {
            if (strtolower((string) wp_parse_url($request_url, PHP_URL_HOST)) === $target_host) {
                $args['sslverify'] = false;
            }
            return $args;
        };
        add_filter('http_request_args', $filter, 10, 2);
        try {
            return download_url($url, 300);
        } finally {
            remove_filter('http_request_args', $filter, 10);
        }
    }

    private static function link_drupal_metadata(int $attachment_id, array $row): void {
        if (!empty($row['media_uuid'])) {
            update_post_meta($attachment_id, '_drupal_media_uuid', sanitize_text_field((string) $row['media_uuid']));
        }
        if (!empty($row['file_uuid'])) {
            update_post_meta($attachment_id, '_drupal_file_uuid', sanitize_text_field((string) $row['file_uuid']));
        }
        if (!empty($row['sha256']) && preg_match('/^[a-f0-9]{64}$/i', (string) $row['sha256'])) {
            update_post_meta($attachment_id, '_drupal_file_sha256', strtolower((string) $row['sha256']));
        }
        if (!empty($row['bundle'])) {
            update_post_meta($attachment_id, '_drupal_media_bundle', sanitize_key((string) $row['bundle']));
        }
    }

    private static function wordpress_date(string $timestamp): string {
        return ctype_digit($timestamp) && (int) $timestamp > 0
            ? wp_date('Y-m-d H:i:s', (int) $timestamp)
            : current_time('mysql');
    }

    private static function warning(string $message): void {
        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::warning($message);
        } else {
            error_log('Drupal WordPress Media Migration: ' . $message);
        }
    }

    private static function format_error(array $error): string {
        return sprintf(
            'CSV row %d; media "%s"; URL "%s"; error: %s',
            (int) ($error['row'] ?? 0),
            (string) ($error['name'] ?? ''),
            (string) ($error['url'] ?? ''),
            (string) ($error['message'] ?? 'Unknown error')
        );
    }
}

add_action('admin_menu', [DWWMM_Migration::class, 'register_admin']);
add_action('admin_post_dwwmm_media_upload', [DWWMM_Migration::class, 'handle_admin_upload']);
add_action('admin_post_dwwmm_media_export', [DWWMM_Migration::class, 'handle_admin_export']);
add_action('wp_ajax_dwwmm_media_process', [DWWMM_Migration::class, 'process_admin_batch']);

if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('media-migration import', function (array $args, array $assoc_args): void {
        $path = $args[0] ?? '';
        if ($path === '') {
            WP_CLI::error('Provide the CSV path or public HTTPS URL.');
        }
        $temporary = '';
        if (preg_match('#^https?://#i', $path)) {
            if (!wp_http_validate_url($path)) {
                WP_CLI::error('The CSV URL is not a valid public HTTP(S) URL.');
            }
            require_once ABSPATH . 'wp-admin/includes/file.php';
            $temporary = DWWMM_Migration::download_source_url($path, isset($assoc_args['insecure']));
            if (is_wp_error($temporary)) {
                WP_CLI::error($temporary->get_error_message());
            }
            $path = $temporary;
        }
        try {
            $counts = DWWMM_Migration::import_file(
                $path,
                max(0, (int) ($assoc_args['offset'] ?? 0)),
                max(0, (int) ($assoc_args['limit'] ?? 0)),
                isset($assoc_args['dry-run']),
                isset($assoc_args['insecure'])
            );
        } finally {
            if ($temporary && file_exists($temporary)) {
                wp_delete_file($temporary);
            }
        }
        WP_CLI::log(sprintf('Created %d; skipped %d; failed %d.', $counts['created'], $counts['skipped'], $counts['failed']));
        if ($counts['failed'] > 0) {
            WP_CLI::halt(1);
        }
        WP_CLI::success('Media import finished.');
    }, [
        'shortdesc' => 'Imports a Drupal media CSV into the Media Library.',
        'synopsis' => [
            ['type' => 'positional', 'name' => 'csv', 'description' => 'Absolute path or public HTTPS URL of the CSV manifest.'],
            ['type' => 'assoc', 'name' => 'offset', 'optional' => true, 'default' => 0],
            ['type' => 'assoc', 'name' => 'limit', 'optional' => true, 'default' => 0],
            ['type' => 'flag', 'name' => 'dry-run', 'optional' => true],
            ['type' => 'flag', 'name' => 'insecure', 'optional' => true, 'description' => 'Allow invalid SSL certificates only for source CSV and media downloads.'],
        ],
    ]);

    WP_CLI::add_command('media-migration export', function (array $args): void {
        $output = $args[0] ?? '';
        if ($output === '') {
            WP_CLI::error('Provide an output CSV path.');
        }
        $counts = DWWMM_Migration::export_file($output);
        WP_CLI::success(sprintf(
            'Exported %d attachments; skipped %d.',
            $counts['exported'],
            $counts['skipped']
        ));
    }, [
        'shortdesc' => 'Exports WordPress attachments to a Drupal-compatible CSV.',
        'synopsis' => [
            ['type' => 'positional', 'name' => 'output', 'description' => 'Output CSV path.'],
        ],
    ]);
}
