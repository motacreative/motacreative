<?php

namespace ScToFct;

if (!defined('ABSPATH')) {
    exit;
}

class IdMapper
{
    private static function table()
    {
        global $wpdb;
        return $wpdb->prefix . 'sc_fct_migration_map';
    }

    /**
     * Store a SureCart → FluentCart ID mapping.
     */
    public static function set(string $entityType, string $surecartId, int $fluentcartId, array $meta = []): void
    {
        global $wpdb;

        $wpdb->replace(
            self::table(),
            [
                'entity_type'   => $entityType,
                'surecart_id'   => $surecartId,
                'fluentcart_id' => $fluentcartId,
                'meta'          => !empty($meta) ? wp_json_encode($meta) : null,
                'created_at'    => current_time('mysql', true),
            ],
            ['%s', '%s', '%d', '%s', '%s']
        );
    }

    /**
     * Get the FluentCart ID for a given SureCart entity.
     */
    public static function getFluentCartId(string $entityType, string $surecartId): ?int
    {
        global $wpdb;
        $table = self::table();

        $result = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT fluentcart_id FROM {$table} WHERE entity_type = %s AND surecart_id = %s",
                $entityType,
                $surecartId
            )
        );

        return $result !== null ? (int) $result : null;
    }

    /**
     * Check if a SureCart entity has already been migrated.
     */
    public static function exists(string $entityType, string $surecartId): bool
    {
        return self::getFluentCartId($entityType, $surecartId) !== null;
    }

    /**
     * Get the count of migrated entities for a given type.
     */
    public static function countByType(string $entityType): int
    {
        global $wpdb;
        $table = self::table();

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE entity_type = %s",
                $entityType
            )
        );
    }

    /**
     * Clear all mappings.
     */
    public static function clearAll(): void
    {
        global $wpdb;
        $wpdb->query("TRUNCATE TABLE " . self::table());
    }

    /**
     * Clear mappings for a specific entity type.
     */
    public static function clearType(string $entityType): void
    {
        global $wpdb;
        $wpdb->delete(self::table(), ['entity_type' => $entityType], ['%s']);
    }
}
