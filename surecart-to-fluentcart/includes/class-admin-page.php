<?php

namespace ScToFct;

if (!defined('ABSPATH')) {
    exit;
}

class AdminPage
{
    public function __construct()
    {
        add_action('admin_menu', [$this, 'addMenuPage']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    public function addMenuPage(): void
    {
        add_management_page(
            'SureCart to FluentCart Migration',
            'SC to FCT Migration',
            'manage_options',
            'sc-to-fct-migration',
            [$this, 'renderPage']
        );
    }

    public function enqueueAssets(string $hook): void
    {
        if ($hook !== 'tools_page_sc-to-fct-migration') {
            return;
        }

        wp_enqueue_style(
            'sc-fct-migration-admin',
            SC_FCT_PLUGIN_URL . 'assets/css/migration-admin.css',
            [],
            SC_FCT_VERSION
        );

        wp_enqueue_script(
            'sc-fct-migration-admin',
            SC_FCT_PLUGIN_URL . 'assets/js/migration-admin.js',
            [],
            SC_FCT_VERSION,
            true
        );

        wp_localize_script('sc-fct-migration-admin', 'scFctMigration', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('sc_fct_migration'),
        ]);
    }

    public function renderPage(): void
    {
        $surecartActive = class_exists('SureCart') || defined('SURECART_PLUGIN_FILE');
        $fluentcartActive = defined('FLUENTCART_PLUGIN_PATH') || class_exists('FluentCart\\App\\App');
        $migrated = [
            'products'  => IdMapper::countByType('product'),
            'customers' => IdMapper::countByType('customer'),
            'orders'    => IdMapper::countByType('order'),
        ];
        ?>
        <div class="wrap sc-fct-wrap">
            <h1>SureCart to FluentCart Migration</h1>

            <div class="sc-fct-dependencies">
                <h2>Plugin Dependencies</h2>
                <div class="sc-fct-dep-grid">
                    <div class="sc-fct-dep <?php echo $surecartActive ? 'sc-fct-dep--ok' : 'sc-fct-dep--error'; ?>">
                        <span class="sc-fct-dep__icon"><?php echo $surecartActive ? '&#10003;' : '&#10007;'; ?></span>
                        <span class="sc-fct-dep__label">SureCart</span>
                        <span class="sc-fct-dep__status"><?php echo $surecartActive ? 'Active' : 'Not Active'; ?></span>
                    </div>
                    <div class="sc-fct-dep <?php echo $fluentcartActive ? 'sc-fct-dep--ok' : 'sc-fct-dep--error'; ?>">
                        <span class="sc-fct-dep__icon"><?php echo $fluentcartActive ? '&#10003;' : '&#10007;'; ?></span>
                        <span class="sc-fct-dep__label">FluentCart</span>
                        <span class="sc-fct-dep__status"><?php echo $fluentcartActive ? 'Active' : 'Not Active'; ?></span>
                    </div>
                </div>
            </div>

            <?php if ($surecartActive && $fluentcartActive) : ?>

            <div class="sc-fct-migration-panel">
                <h2>Migration</h2>
                <p>This will migrate data from SureCart to FluentCart in order: <strong>Products &rarr; Customers &rarr; Orders</strong>.</p>
                <p>Already-migrated records will be skipped automatically.</p>

                <div class="sc-fct-options">
                    <label for="sc-fct-batch-size">Batch size:</label>
                    <select id="sc-fct-batch-size">
                        <option value="5">5</option>
                        <option value="10">10</option>
                        <option value="20" selected>20</option>
                        <option value="50">50</option>
                    </select>
                </div>

                <div class="sc-fct-steps">
                    <div class="sc-fct-step" data-step="products">
                        <div class="sc-fct-step__header">
                            <span class="sc-fct-step__name">Products</span>
                            <span class="sc-fct-step__count">
                                <span class="sc-fct-step__migrated"><?php echo esc_html($migrated['products']); ?></span>
                                /
                                <span class="sc-fct-step__total">?</span>
                            </span>
                        </div>
                        <div class="sc-fct-progress">
                            <div class="sc-fct-progress__bar" style="width: 0%"></div>
                        </div>
                    </div>

                    <div class="sc-fct-step" data-step="customers">
                        <div class="sc-fct-step__header">
                            <span class="sc-fct-step__name">Customers</span>
                            <span class="sc-fct-step__count">
                                <span class="sc-fct-step__migrated"><?php echo esc_html($migrated['customers']); ?></span>
                                /
                                <span class="sc-fct-step__total">?</span>
                            </span>
                        </div>
                        <div class="sc-fct-progress">
                            <div class="sc-fct-progress__bar" style="width: 0%"></div>
                        </div>
                    </div>

                    <div class="sc-fct-step" data-step="orders">
                        <div class="sc-fct-step__header">
                            <span class="sc-fct-step__name">Orders</span>
                            <span class="sc-fct-step__count">
                                <span class="sc-fct-step__migrated"><?php echo esc_html($migrated['orders']); ?></span>
                                /
                                <span class="sc-fct-step__total">?</span>
                            </span>
                        </div>
                        <div class="sc-fct-progress">
                            <div class="sc-fct-progress__bar" style="width: 0%"></div>
                        </div>
                    </div>
                </div>

                <div class="sc-fct-actions">
                    <button type="button" id="sc-fct-start" class="button button-primary button-hero">
                        Start Migration
                    </button>
                    <button type="button" id="sc-fct-reset" class="button button-secondary">
                        Reset Migration Data
                    </button>
                </div>

                <div id="sc-fct-status" class="sc-fct-status" style="display: none;">
                    <span class="spinner is-active"></span>
                    <span id="sc-fct-status-text">Initializing...</span>
                </div>
            </div>

            <div class="sc-fct-log-panel">
                <h2>Activity Log</h2>
                <div id="sc-fct-log" class="sc-fct-log"></div>
            </div>

            <?php else : ?>
            <div class="notice notice-warning inline">
                <p>Both SureCart and FluentCart must be installed and active to use this migration tool.</p>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }
}
