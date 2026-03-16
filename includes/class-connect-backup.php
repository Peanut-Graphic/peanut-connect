<?php
/**
 * Peanut Connect Backup Handler
 *
 * Manages site backups (database + files) and restore operations.
 *
 * @since 3.4.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Peanut_Connect_Backup {

    /**
     * Create a full site backup
     *
     * @param array $params Optional parameters (type, storage_driver).
     * @return array|WP_Error Backup result or error.
     */
    public static function create_backup(array $params = []): array|WP_Error {
        $backup_dir = WP_CONTENT_DIR . '/peanut-backups';
        if (!file_exists($backup_dir)) {
            wp_mkdir_p($backup_dir);
            // Add .htaccess to prevent direct access
            file_put_contents($backup_dir . '/.htaccess', 'deny from all');
        }

        $timestamp = date('Y-m-d-His');
        $site_slug = sanitize_title(get_bloginfo('name'));
        $backup_name = "{$site_slug}-{$timestamp}";
        $backup_path = "{$backup_dir}/{$backup_name}";

        // 1. Export database
        $db_file = self::export_database($backup_path);
        if (is_wp_error($db_file)) {
            return $db_file;
        }

        // 2. Create zip of wp-content (excluding peanut-backups, cache, upgrade dirs)
        $zip_file = self::create_zip($backup_path, $db_file);
        if (is_wp_error($zip_file)) {
            return $zip_file;
        }

        // 3. Clean up temp DB file
        @unlink($db_file);

        $size = filesize($zip_file);

        // 4. If cloud storage params provided, upload and clean local
        $storage_path = $zip_file;
        if (!empty($params['storage_driver']) && $params['storage_driver'] !== 'local') {
            $upload_result = self::upload_to_cloud($zip_file, $params);
            if (is_wp_error($upload_result)) {
                return $upload_result;
            }
            $storage_path = $upload_result;
            @unlink($zip_file); // Clean local after upload
        }

        return [
            'success' => true,
            'storage_path' => $storage_path,
            'size_bytes' => $size,
            'backup_name' => $backup_name,
            'created_at' => current_time('mysql'),
        ];
    }

    /**
     * Export database tables to a SQL file
     *
     * @param string $backup_path Base path for the backup file (without extension).
     * @return string|WP_Error Path to the SQL file or error.
     */
    private static function export_database(string $backup_path): string|WP_Error {
        global $wpdb;
        $db_file = $backup_path . '.sql';

        // Get all tables for this site
        $tables = $wpdb->get_col($wpdb->prepare(
            "SHOW TABLES LIKE %s",
            $wpdb->esc_like($wpdb->prefix) . '%'
        ));
        if (empty($tables)) {
            return new WP_Error('no_tables', __('No database tables found.', 'peanut-connect'));
        }

        $handle = fopen($db_file, 'w');
        if (!$handle) {
            return new WP_Error('file_error', __('Could not create database export file.', 'peanut-connect'));
        }

        // Write header
        fwrite($handle, "-- Peanut Connect Database Backup\n");
        fwrite($handle, "-- Generated: " . current_time('mysql') . "\n");
        fwrite($handle, "-- Site: " . get_site_url() . "\n\n");
        fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\n\n");

        foreach ($tables as $table) {
            // CREATE TABLE
            $create = $wpdb->get_row("SHOW CREATE TABLE `{$table}`", ARRAY_N);
            if ($create) {
                fwrite($handle, "DROP TABLE IF EXISTS `{$table}`;\n");
                fwrite($handle, $create[1] . ";\n\n");
            }

            // INSERT rows in batches of 100
            $offset = 0;
            $batch_size = 100;
            do {
                $rows = $wpdb->get_results(
                    $wpdb->prepare("SELECT * FROM `{$table}` LIMIT %d OFFSET %d", $batch_size, $offset),
                    ARRAY_A
                );
                foreach ($rows as $row) {
                    $values = array_map(function ($v) use ($wpdb) {
                        return $v === null ? 'NULL' : "'" . $wpdb->_real_escape($v) . "'";
                    }, $row);
                    $columns = array_map(function ($c) {
                        return "`{$c}`";
                    }, array_keys($row));
                    fwrite($handle, "INSERT INTO `{$table}` (" . implode(',', $columns) . ") VALUES (" . implode(',', $values) . ");\n");
                }
                $offset += $batch_size;
            } while (count($rows) === $batch_size);

            fwrite($handle, "\n");
        }

        fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($handle);

        return $db_file;
    }

    /**
     * Create a zip archive of wp-content and the database export
     *
     * @param string $backup_path Base path for the backup file (without extension).
     * @param string $db_file     Path to the database SQL file.
     * @return string|WP_Error Path to the zip file or error.
     */
    private static function create_zip(string $backup_path, string $db_file): string|WP_Error {
        $zip_file = $backup_path . '.zip';

        if (!class_exists('ZipArchive')) {
            return new WP_Error('zip_missing', __('ZipArchive PHP extension is not available.', 'peanut-connect'));
        }

        $zip = new ZipArchive();
        if ($zip->open($zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return new WP_Error('zip_error', __('Could not create zip archive.', 'peanut-connect'));
        }

        // Add database export
        $zip->addFile($db_file, 'database.sql');

        // Add wp-content directory (excluding large/cache dirs)
        $exclude_dirs = ['peanut-backups', 'cache', 'upgrade', 'wflogs', 'ai1wm-backups'];
        $wp_content = WP_CONTENT_DIR;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($wp_content, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            $file_path = $file->getRealPath();
            $relative_path = substr($file_path, strlen($wp_content) + 1);

            // Skip excluded directories
            $skip = false;
            foreach ($exclude_dirs as $exclude) {
                if (strpos($relative_path, $exclude . DIRECTORY_SEPARATOR) === 0 || strpos($relative_path, $exclude . '/') === 0) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) {
                continue;
            }

            // Skip files larger than 50MB
            if ($file->getSize() > 50 * 1024 * 1024) {
                continue;
            }

            $zip->addFile($file_path, 'wp-content/' . $relative_path);
        }

        $zip->close();
        return $zip_file;
    }

    /**
     * Upload backup to cloud storage
     *
     * Placeholder for future S3/R2 integration. Currently returns local path.
     *
     * @param string $local_file Path to the local backup file.
     * @param array  $params     Cloud storage parameters.
     * @return string|WP_Error Storage path or error.
     */
    private static function upload_to_cloud(string $local_file, array $params): string|WP_Error {
        // For now, return local path — cloud upload will be implemented with S3/R2 SDK
        // Hub can fetch the file via a separate download endpoint if needed
        return $local_file;
    }

    /**
     * Restore from a backup file
     *
     * Downloads the backup archive, extracts it, restores the database
     * and wp-content files, then clears all caches.
     *
     * @param string $backup_url URL to download the backup archive from.
     * @return array|WP_Error Restore result or error.
     */
    public static function restore_backup(string $backup_url): array|WP_Error {
        // 1. Download backup
        $temp_file = download_url($backup_url, 300); // 5 min timeout
        if (is_wp_error($temp_file)) {
            return $temp_file;
        }

        // 2. Extract zip
        $extract_dir = WP_CONTENT_DIR . '/peanut-backups/restore-' . time();
        wp_mkdir_p($extract_dir);

        // WordPress provides unzip_file() — requires file.php
        require_once ABSPATH . 'wp-admin/includes/file.php';
        $unzip = unzip_file($temp_file, $extract_dir);
        @unlink($temp_file);

        if (is_wp_error($unzip)) {
            self::cleanup_dir($extract_dir);
            return $unzip;
        }

        // 3. Restore database if SQL file exists
        $sql_file = $extract_dir . '/database.sql';
        if (file_exists($sql_file)) {
            $result = self::import_database($sql_file);
            if (is_wp_error($result)) {
                self::cleanup_dir($extract_dir);
                return $result;
            }
        }

        // 4. Restore wp-content files
        $wp_content_backup = $extract_dir . '/wp-content';
        if (is_dir($wp_content_backup)) {
            self::copy_directory($wp_content_backup, WP_CONTENT_DIR);
        }

        // 5. Clean up
        self::cleanup_dir($extract_dir);

        // 6. Clear caches
        wp_cache_flush();
        if (function_exists('opcache_reset')) {
            opcache_reset();
        }

        return [
            'success' => true,
            'message' => __('Backup restored successfully.', 'peanut-connect'),
            'restored_at' => current_time('mysql'),
        ];
    }

    /**
     * Import a SQL dump into the database
     *
     * @param string $sql_file Path to the SQL file.
     * @return true|WP_Error True on success or error.
     */
    private static function import_database(string $sql_file): true|WP_Error {
        global $wpdb;
        $sql = file_get_contents($sql_file);
        if (!$sql) {
            return new WP_Error('sql_read', __('Could not read SQL file.', 'peanut-connect'));
        }

        // Split by semicolons followed by newline (handles most cases)
        $statements = array_filter(array_map('trim', explode(";\n", $sql)));
        foreach ($statements as $statement) {
            if (empty($statement) || strpos($statement, '--') === 0) {
                continue;
            }
            $wpdb->query($statement); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }
        return true;
    }

    /**
     * Recursively copy a directory
     *
     * @param string $source Source directory path.
     * @param string $dest   Destination directory path.
     */
    private static function copy_directory(string $source, string $dest): void {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            $target = $dest . DIRECTORY_SEPARATOR . substr($item->getPathname(), strlen($source) + 1);
            if ($item->isDir()) {
                wp_mkdir_p($target);
            } else {
                copy($item->getPathname(), $target);
            }
        }
    }

    /**
     * Recursively remove a directory and its contents
     *
     * @param string $dir Directory path to remove.
     */
    private static function cleanup_dir(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }

    /**
     * Run a health check — verify the site is responding properly
     *
     * @return array Health check result with healthy status, status code, and fatal error flag.
     */
    public static function health_check(): array {
        $response = wp_remote_get(get_site_url(), ['timeout' => 15, 'sslverify' => false]);

        if (is_wp_error($response)) {
            return ['healthy' => false, 'error' => $response->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($response);
        $healthy = $code >= 200 && $code < 400;

        // Check for PHP fatal errors in error log
        $error_log = ini_get('error_log');
        $recent_fatal = false;
        if ($error_log && file_exists($error_log)) {
            $size = filesize($error_log);
            $read_from = max(0, $size - 5000);
            $log_content = file_get_contents($error_log, false, null, $read_from); // Last ~5KB
            $recent_fatal = strpos($log_content, 'Fatal error') !== false
                && strpos($log_content, date('Y-m-d H:i', time() - 300)) !== false; // Last 5 min
        }

        return [
            'healthy' => $healthy && !$recent_fatal,
            'status_code' => $code,
            'recent_fatal_error' => $recent_fatal,
        ];
    }
}
