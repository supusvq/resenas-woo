<?php
namespace MRG;

if (!defined('ABSPATH')) {
    exit;
}

class Database
{
    public static function create_tables()
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $reviews_table = $wpdb->prefix . 'mrg_reviews';
        $logs_table = $wpdb->prefix . 'mrg_email_logs';

        $sql_reviews = "CREATE TABLE {$reviews_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            place_id VARCHAR(255) NOT NULL,
            review_id VARCHAR(255) NOT NULL,
            author_name VARCHAR(255) NOT NULL,
            author_photo TEXT NULL,
            rating TINYINT NOT NULL DEFAULT 5,
            review_text LONGTEXT NULL,
            review_date DATETIME NULL,
            relative_time VARCHAR(100) NULL,
            is_anonymous TINYINT(1) NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY review_id (review_id),
            KEY place_id (place_id),
            KEY rating (rating),
            KEY review_date (review_date),
            KEY active (active)
        ) {$charset_collate};";

        $sql_logs = "CREATE TABLE {$logs_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id BIGINT UNSIGNED NOT NULL,
            customer_name VARCHAR(255) NULL,
            customer_email VARCHAR(255) NOT NULL,
            status VARCHAR(50) NOT NULL,
            scheduled_at DATETIME NULL,
            sent_at DATETIME NULL,
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            error_message LONGTEXT NULL,
            technical_log LONGTEXT NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY order_id (order_id),
            KEY status (status),
            KEY customer_email (customer_email),
            KEY idx_status_attempts_updated (status, attempts, updated_at)
        ) {$charset_collate};";

        dbDelta($sql_reviews);
        dbDelta($sql_logs);

        self::verify_columns_robustly($reviews_table, $logs_table);
    }

    private static function verify_columns_robustly($reviews_table, $logs_table)
    {
        global $wpdb;

        if (!self::table_exists($reviews_table)) {
            return;
        }

        $column_active = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM {$reviews_table} LIKE %s", 'active'));
        if (!is_array($column_active) || empty($column_active)) {
            $wpdb->query("ALTER TABLE {$reviews_table} ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1 AFTER is_anonymous");
            $wpdb->query("ALTER TABLE {$reviews_table} ADD INDEX active (active)");
        }

        if (!self::table_exists($logs_table)) {
            return;
        }

        $columns_logs = $wpdb->get_results("SHOW COLUMNS FROM {$logs_table}");
        if (!is_array($columns_logs)) {
            return;
        }

        $cols_names = wp_list_pluck($columns_logs, 'Field');

        if (!in_array('attempts', $cols_names, true)) {
            $wpdb->query("ALTER TABLE {$logs_table} ADD COLUMN attempts TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER sent_at");
        }

        if (!in_array('technical_log', $cols_names, true)) {
            $wpdb->query("ALTER TABLE {$logs_table} ADD COLUMN technical_log LONGTEXT NULL AFTER error_message");
        }

        if (!in_array('created_at', $cols_names, true)) {
            $wpdb->query("ALTER TABLE {$logs_table} ADD COLUMN created_at DATETIME NULL AFTER technical_log");
        }

        if (!in_array('updated_at', $cols_names, true)) {
            $wpdb->query("ALTER TABLE {$logs_table} ADD COLUMN updated_at DATETIME NULL AFTER created_at");
        }

        $indexes = $wpdb->get_results("SHOW INDEX FROM {$logs_table}");
        if (!is_array($indexes)) {
            return;
        }

        $idx_names = wp_list_pluck($indexes, 'Key_name');
        if (!in_array('idx_status_attempts_updated', $idx_names, true)) {
            $wpdb->query("ALTER TABLE {$logs_table} ADD INDEX idx_status_attempts_updated (status, attempts, updated_at)");
        }
    }

    private static function table_exists($table_name)
    {
        global $wpdb;

        $found_table = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name));
        return $found_table === $table_name;
    }

    public static function maybe_upgrade()
    {
        $installed_ver = get_option('mrg_version', '1.0');
        if (version_compare($installed_ver, MRG_VERSION, '<')) {
            self::create_tables();
            self::migrate_settings();
            update_option('mrg_version', MRG_VERSION);
        }
    }

    private static function migrate_settings()
    {
        $settings = get_option('mrg_settings', []);
        if (!is_array($settings)) {
            return;
        }

        $changed = false;

        if (!array_key_exists('email_review_url', $settings)) {
            $settings['email_review_url'] = '';
            $changed = true;
        }

        if (!empty($settings['email_template']) && is_string($settings['email_template'])) {
            $updated_template = str_replace(
                ['{texto_intro_resena}', '{texto_boton_resena}'],
                [
                    __('Tu opinión nos ayuda a seguir mejorando y a que otros clientes conozcan nuestro trabajo.', 'mis-resenas-de-google'),
                    __('Escribir reseña', 'mis-resenas-de-google'),
                ],
                $settings['email_template']
            );

            if ($updated_template !== $settings['email_template']) {
                $settings['email_template'] = $updated_template;
                $changed = true;
            }
        }

        foreach (['email_review_button_text', 'email_review_intro_text'] as $legacy_key) {
            if (array_key_exists($legacy_key, $settings)) {
                unset($settings[$legacy_key]);
                $changed = true;
            }
        }

        if ($changed) {
            update_option('mrg_settings', $settings);
        }
    }
}
