<?php

namespace ScToFct;

if (!defined('ABSPATH')) {
    exit;
}

class AjaxHandler
{
    public function __construct()
    {
        add_action('wp_ajax_sc_fct_migrate_batch', [$this, 'handleBatch']);
        add_action('wp_ajax_sc_fct_reset', [$this, 'handleReset']);
        add_action('wp_ajax_sc_fct_count', [$this, 'handleCount']);
    }

    /**
     * Process a single migration batch.
     */
    public function handleBatch(): void
    {
        $this->verifyRequest();

        $step = sanitize_text_field(wp_unslash($_POST['step'] ?? ''));
        $page = absint($_POST['page'] ?? 1);
        $batchSize = absint($_POST['batch_size'] ?? 20);

        if (!in_array($step, ['products', 'customers', 'orders'], true)) {
            wp_send_json_error(['message' => 'Invalid migration step.']);
        }

        // Simple lock mechanism
        $lock = get_transient('_sc_fct_migration_lock');
        if ($lock && $lock !== $step) {
            wp_send_json_error(['message' => 'Another migration step is currently running.']);
        }
        set_transient('_sc_fct_migration_lock', $step, 5 * MINUTE_IN_SECONDS);

        // Load migrator classes
        require_once SC_FCT_PLUGIN_PATH . 'includes/migrators/class-base-migrator.php';

        $migrator = $this->getMigrator($step, $batchSize);
        if (!$migrator) {
            delete_transient('_sc_fct_migration_lock');
            wp_send_json_error(['message' => "Could not initialize migrator for step: {$step}"]);
        }

        try {
            $result = $migrator->processBatch($page);
        } catch (\Exception $e) {
            delete_transient('_sc_fct_migration_lock');
            MigrationLogger::error($step, 'batch', $e->getMessage());
            wp_send_json_error(['message' => $e->getMessage()]);
        }

        // Clear lock if this step is done
        if (!$result['has_more']) {
            delete_transient('_sc_fct_migration_lock');
        }

        wp_send_json_success([
            'step'      => $step,
            'page'      => $page,
            'total'     => $result['total'],
            'processed' => $result['processed'],
            'batch_new' => $result['batch_new'],
            'skipped'   => $result['skipped'],
            'errors'    => $result['errors'],
            'has_more'  => $result['has_more'],
            'next_page' => $result['has_more'] ? $page + 1 : null,
        ]);
    }

    /**
     * Reset all migration data.
     */
    public function handleReset(): void
    {
        $this->verifyRequest();

        IdMapper::clearAll();
        MigrationLogger::clearErrors();
        delete_transient('_sc_fct_migration_lock');
        delete_option('_sc_fct_migration_state');

        MigrationLogger::info('Migration data reset by admin.');

        wp_send_json_success(['message' => 'Migration data has been reset.']);
    }

    /**
     * Get counts of SureCart entities (for initial UI display).
     */
    public function handleCount(): void
    {
        $this->verifyRequest();

        $counts = [
            'products'  => 0,
            'customers' => 0,
            'orders'    => 0,
        ];

        try {
            $productResponse = \SureCart\Models\Product::paginate(['per_page' => 1, 'page' => 1]);
            if (is_object($productResponse) && isset($productResponse->pagination)) {
                $counts['products'] = (int) ($productResponse->pagination->count ?? 0);
            } elseif (is_array($productResponse)) {
                $counts['products'] = (int) ($productResponse['pagination']['count'] ?? 0);
            }
        } catch (\Exception $e) {
            // Silent — will show 0
        }

        try {
            $customerResponse = \SureCart\Models\Customer::paginate(['per_page' => 1, 'page' => 1]);
            if (is_object($customerResponse) && isset($customerResponse->pagination)) {
                $counts['customers'] = (int) ($customerResponse->pagination->count ?? 0);
            } elseif (is_array($customerResponse)) {
                $counts['customers'] = (int) ($customerResponse['pagination']['count'] ?? 0);
            }
        } catch (\Exception $e) {
            // Silent
        }

        try {
            $orderResponse = \SureCart\Models\Order::paginate(['per_page' => 1, 'page' => 1]);
            if (is_object($orderResponse) && isset($orderResponse->pagination)) {
                $counts['orders'] = (int) ($orderResponse->pagination->count ?? 0);
            } elseif (is_array($orderResponse)) {
                $counts['orders'] = (int) ($orderResponse['pagination']['count'] ?? 0);
            }
        } catch (\Exception $e) {
            // Silent
        }

        wp_send_json_success($counts);
    }

    /**
     * Verify nonce and capability.
     */
    private function verifyRequest(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions.'], 403);
        }

        $nonce = sanitize_text_field(wp_unslash($_POST['_nonce'] ?? ''));
        if (!wp_verify_nonce($nonce, 'sc_fct_migration')) {
            wp_send_json_error(['message' => 'Invalid security token. Please refresh the page.'], 403);
        }
    }

    /**
     * Get the migrator instance for the given step.
     */
    private function getMigrator(string $step, int $batchSize): ?\ScToFct\Migrators\BaseMigrator
    {
        switch ($step) {
            case 'products':
                require_once SC_FCT_PLUGIN_PATH . 'includes/migrators/class-product-migrator.php';
                return new \ScToFct\Migrators\ProductMigrator($batchSize);

            case 'customers':
                require_once SC_FCT_PLUGIN_PATH . 'includes/migrators/class-customer-migrator.php';
                return new \ScToFct\Migrators\CustomerMigrator($batchSize);

            case 'orders':
                require_once SC_FCT_PLUGIN_PATH . 'includes/migrators/class-order-migrator.php';
                return new \ScToFct\Migrators\OrderMigrator($batchSize);

            default:
                return null;
        }
    }
}
