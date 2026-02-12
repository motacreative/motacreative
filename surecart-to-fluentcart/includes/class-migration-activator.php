<?php

namespace ScToFct;

if (!defined('ABSPATH')) {
    exit;
}

class MigrationActivator
{
    public static function activate()
    {
        self::createMappingTable();
        self::initState();
    }

    private static function createMappingTable()
    {
        global $wpdb;

        $table = $wpdb->prefix . 'sc_fct_migration_map';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            entity_type VARCHAR(50) NOT NULL,
            surecart_id VARCHAR(255) NOT NULL,
            fluentcart_id BIGINT(20) UNSIGNED NOT NULL,
            meta TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY entity_surecart (entity_type, surecart_id(191)),
            KEY entity_fluentcart (entity_type, fluentcart_id)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    private static function initState()
    {
        if (get_option('_sc_fct_migration_state') === false) {
            update_option('_sc_fct_migration_state', [
                'status' => 'idle',
                'step'   => '',
                'page'   => 0,
            ]);
        }
    }
}
