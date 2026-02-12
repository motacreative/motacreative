<?php

namespace ScToFct\Migrators;

use ScToFct\IdMapper;
use ScToFct\MigrationLogger;

if (!defined('ABSPATH')) {
    exit;
}

abstract class BaseMigrator
{
    protected int $batchSize = 20;

    public function __construct(int $batchSize = 20)
    {
        $this->batchSize = $batchSize;
    }

    /**
     * Return the entity type string used in the mapping table.
     */
    abstract public function getEntityType(): string;

    /**
     * Fetch a page of records from SureCart.
     *
     * @return array{data: array, total: int}
     */
    abstract public function fetchBatch(int $page, int $perPage): array;

    /**
     * Migrate a single SureCart record into FluentCart.
     *
     * @param object $record The SureCart model instance.
     * @return int|false The FluentCart ID on success, false on failure.
     */
    abstract public function migrateOne($record);

    /**
     * Process one batch of records.
     *
     * @return array{total: int, processed: int, skipped: int, errors: array, has_more: bool}
     */
    public function processBatch(int $page): array
    {
        $result = $this->fetchBatch($page, $this->batchSize);
        $processed = 0;
        $skipped = 0;
        $errors = [];

        foreach ($result['data'] as $record) {
            $scId = $this->getSureCartId($record);

            if (!$scId) {
                $errors[] = ['id' => 'unknown', 'message' => 'Could not extract SureCart ID from record.'];
                continue;
            }

            // Skip if already migrated (idempotency)
            if (IdMapper::exists($this->getEntityType(), $scId)) {
                $skipped++;
                continue;
            }

            try {
                $fctId = $this->migrateOne($record);
                if ($fctId) {
                    IdMapper::set($this->getEntityType(), $scId, $fctId);
                    $processed++;
                } else {
                    $msg = 'migrateOne returned false.';
                    MigrationLogger::error($this->getEntityType(), $scId, $msg);
                    $errors[] = ['id' => $scId, 'message' => $msg];
                }
            } catch (\Exception $e) {
                MigrationLogger::error($this->getEntityType(), $scId, $e->getMessage());
                $errors[] = ['id' => $scId, 'message' => $e->getMessage()];
            }
        }

        $totalProcessedSoFar = IdMapper::countByType($this->getEntityType());

        return [
            'total'     => $result['total'],
            'processed' => $totalProcessedSoFar,
            'batch_new' => $processed,
            'skipped'   => $skipped,
            'errors'    => $errors,
            'has_more'  => ($page * $this->batchSize) < $result['total'],
        ];
    }

    /**
     * Extract the SureCart ID from a record.
     */
    protected function getSureCartId($record): ?string
    {
        if (is_object($record) && isset($record->id)) {
            return (string) $record->id;
        }
        if (is_array($record) && isset($record['id'])) {
            return (string) $record['id'];
        }
        return null;
    }

    /**
     * Safely get a nested value from an object or array.
     */
    protected function getValue($data, string $key, $default = null)
    {
        $keys = explode('.', $key);
        $current = $data;

        foreach ($keys as $k) {
            if (is_object($current)) {
                $current = $current->{$k} ?? null;
            } elseif (is_array($current)) {
                $current = $current[$k] ?? null;
            } else {
                return $default;
            }

            if ($current === null) {
                return $default;
            }
        }

        return $current;
    }
}
