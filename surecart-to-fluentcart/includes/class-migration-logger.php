<?php

namespace ScToFct;

if (!defined('ABSPATH')) {
    exit;
}

class MigrationLogger
{
    private static function getLogDir(): string
    {
        $dir = wp_upload_dir()['basedir'] . '/sc-fct-migration';
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        return $dir;
    }

    private static function getLogFile(): string
    {
        return self::getLogDir() . '/migration.log';
    }

    public static function info(string $message): void
    {
        self::write('INFO', $message);
    }

    public static function error(string $entityType, string $surecartId, string $message): void
    {
        self::write('ERROR', "[{$entityType}:{$surecartId}] {$message}");

        // Also store recent errors in transient for UI display
        $errors = get_transient('_sc_fct_migration_errors') ?: [];
        $errors[] = [
            'type'    => $entityType,
            'sc_id'   => $surecartId,
            'message' => $message,
            'time'    => current_time('mysql'),
        ];
        // Keep last 100 errors
        $errors = array_slice($errors, -100);
        set_transient('_sc_fct_migration_errors', $errors, HOUR_IN_SECONDS);
    }

    public static function getRecentErrors(int $limit = 50): array
    {
        $errors = get_transient('_sc_fct_migration_errors') ?: [];
        return array_slice($errors, -$limit);
    }

    public static function clearErrors(): void
    {
        delete_transient('_sc_fct_migration_errors');
    }

    private static function write(string $level, string $message): void
    {
        $timestamp = current_time('Y-m-d H:i:s');
        $line = "[{$timestamp}] [{$level}] {$message}" . PHP_EOL;
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        file_put_contents(self::getLogFile(), $line, FILE_APPEND | LOCK_EX);
    }
}
