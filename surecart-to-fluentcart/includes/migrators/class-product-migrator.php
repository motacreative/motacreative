<?php

namespace ScToFct\Migrators;

use ScToFct\IdMapper;
use ScToFct\MigrationLogger;

if (!defined('ABSPATH')) {
    exit;
}

class ProductMigrator extends BaseMigrator
{
    public function getEntityType(): string
    {
        return 'product';
    }

    public function fetchBatch(int $page, int $perPage): array
    {
        $response = \SureCart\Models\Product::with([
            'prices',
            'product_medias',
            'product_medias.media',
            'product_collections',
        ])->paginate([
            'per_page' => $perPage,
            'page'     => $page,
        ]);

        $data = [];
        $total = 0;

        if (is_object($response) && isset($response->data)) {
            $data = $response->data;
            $total = $response->pagination->count ?? 0;
        } elseif (is_array($response)) {
            $data = $response['data'] ?? [];
            $total = $response['pagination']['count'] ?? 0;
        }

        return [
            'data'  => $data,
            'total' => (int) $total,
        ];
    }

    public function migrateOne($record): int
    {
        global $wpdb;

        $name = $this->getValue($record, 'name', 'Untitled Product');
        $description = $this->getValue($record, 'description', '');
        $slug = $this->getValue($record, 'slug', sanitize_title($name));
        $archived = $this->getValue($record, 'archived', false);
        $createdAt = $this->getValue($record, 'created_at');

        // 1. Create the WordPress post (FluentCart CPT)
        $postData = [
            'post_title'   => $name,
            'post_content' => $description,
            'post_excerpt' => wp_trim_words(wp_strip_all_tags($description), 30, '...'),
            'post_status'  => $archived ? 'draft' : 'publish',
            'post_type'    => 'fluent-products',
            'post_name'    => $slug,
            'post_date'    => $createdAt ? gmdate('Y-m-d H:i:s', strtotime($createdAt)) : current_time('mysql'),
        ];

        $postId = wp_insert_post($postData, true);
        if (is_wp_error($postId)) {
            throw new \Exception('wp_insert_post failed: ' . $postId->get_error_message());
        }

        // 2. Sideload product images
        $this->migrateImages($record, $postId);

        // 3. Map product collections to taxonomy terms
        $this->migrateCollections($record, $postId);

        // 4. Create product variations from SureCart prices
        $prices = $this->getValue($record, 'prices', []);
        if (is_object($prices) && isset($prices->data)) {
            $prices = $prices->data;
        }

        $variationIds = [];
        $priceValues = [];
        $fulfillmentType = 'physical';

        // Check if product has metadata indicating digital
        $metadata = $this->getValue($record, 'metadata', []);
        if (is_object($metadata)) {
            $metadata = (array) $metadata;
        }
        if (!empty($metadata['fulfillment_type'])) {
            $fulfillmentType = $metadata['fulfillment_type'];
        }

        // Check if product is recurring (shouldn't be for this migration, but handle gracefully)
        $isRecurring = $this->getValue($record, 'recurring', false);

        if (!empty($prices)) {
            foreach ($prices as $index => $price) {
                $priceAmount = (int) $this->getValue($price, 'amount', 0);
                $compareAmount = (int) $this->getValue($price, 'compare_amount', 0);
                $priceName = $this->getValue($price, 'name', $name);
                $priceId = $this->getValue($price, 'id', '');

                $priceValues[] = $priceAmount;

                $stockStatus = 'in-stock';
                $stock = 0;

                $variationData = [
                    'post_id'            => $postId,
                    'serial_index'       => $index + 1,
                    'variation_title'    => $priceName ?: $name,
                    'payment_type'       => 'onetime',
                    'stock_status'       => $stockStatus,
                    'total_stock'        => $stock,
                    'available'          => $stock,
                    'fulfillment_type'   => $fulfillmentType,
                    'item_status'        => 'active',
                    'item_price'         => $priceAmount,
                    'compare_price'      => $compareAmount > 0 ? $compareAmount : 0,
                    'other_info'         => wp_json_encode(['payment_type' => 'onetime']),
                    'created_at'         => current_time('mysql', true),
                    'updated_at'         => current_time('mysql', true),
                ];

                $wpdb->insert(
                    $wpdb->prefix . 'fct_product_variations',
                    $variationData
                );

                $varId = (int) $wpdb->insert_id;
                $variationIds[] = $varId;

                // Map variant in case order items reference the price ID
                if ($priceId) {
                    IdMapper::set('variant', (string) $priceId, $varId, [
                        'product_post_id' => $postId,
                        'price_name'      => $priceName,
                    ]);
                }
            }
        } else {
            // No prices — create a single zero-price variation as placeholder
            $wpdb->insert(
                $wpdb->prefix . 'fct_product_variations',
                [
                    'post_id'          => $postId,
                    'serial_index'     => 1,
                    'variation_title'  => $name,
                    'payment_type'     => 'onetime',
                    'stock_status'     => 'in-stock',
                    'total_stock'      => 0,
                    'available'        => 0,
                    'fulfillment_type' => $fulfillmentType,
                    'item_status'      => 'active',
                    'item_price'       => 0,
                    'compare_price'    => 0,
                    'other_info'       => wp_json_encode(['payment_type' => 'onetime']),
                    'created_at'       => current_time('mysql', true),
                    'updated_at'       => current_time('mysql', true),
                ]
            );
            $variationIds[] = (int) $wpdb->insert_id;
            $priceValues[] = 0;
        }

        // 5. Create product detail row
        $minPrice = !empty($priceValues) ? min($priceValues) : 0;
        $maxPrice = !empty($priceValues) ? max($priceValues) : 0;
        $variationType = count($variationIds) > 1 ? 'simple_variations' : 'simple';

        $wpdb->insert(
            $wpdb->prefix . 'fct_product_details',
            [
                'post_id'              => $postId,
                'fulfillment_type'     => $fulfillmentType,
                'min_price'            => $minPrice,
                'max_price'            => $maxPrice,
                'default_variation_id' => $variationIds[0] ?? null,
                'variation_type'       => $variationType,
                'stock_availability'   => 'in-stock',
                'created_at'           => current_time('mysql', true),
                'updated_at'           => current_time('mysql', true),
            ]
        );

        MigrationLogger::info("Migrated product '{$name}' → post ID {$postId} with " . count($variationIds) . " variation(s).");

        return $postId;
    }

    /**
     * Sideload SureCart product images into WordPress media library.
     */
    private function migrateImages($record, int $postId): void
    {
        $productMedias = $this->getValue($record, 'product_medias', []);
        if (is_object($productMedias) && isset($productMedias->data)) {
            $productMedias = $productMedias->data;
        }

        if (empty($productMedias) || !is_array($productMedias)) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $gallery = [];
        $firstAttachmentId = null;

        foreach ($productMedias as $productMedia) {
            $media = $this->getValue($productMedia, 'media');
            if (!$media) {
                continue;
            }

            $url = $this->getValue($media, 'url', '');
            if (empty($url)) {
                continue;
            }

            $filename = $this->getValue($media, 'filename', basename(wp_parse_url($url, PHP_URL_PATH)));

            try {
                $tmpFile = download_url($url, 30);
                if (is_wp_error($tmpFile)) {
                    MigrationLogger::info("Failed to download image {$url}: " . $tmpFile->get_error_message());
                    continue;
                }

                $fileArray = [
                    'name'     => sanitize_file_name($filename),
                    'tmp_name' => $tmpFile,
                ];

                $attachmentId = media_handle_sideload($fileArray, $postId);
                if (is_wp_error($attachmentId)) {
                    MigrationLogger::info("Failed to sideload image {$url}: " . $attachmentId->get_error_message());
                    if (file_exists($tmpFile)) {
                        wp_delete_file($tmpFile);
                    }
                    continue;
                }

                $attachmentUrl = wp_get_attachment_url($attachmentId);
                $gallery[] = [
                    'id'    => $attachmentId,
                    'url'   => $attachmentUrl,
                    'title' => $filename,
                ];

                if ($firstAttachmentId === null) {
                    $firstAttachmentId = $attachmentId;
                }
            } catch (\Exception $e) {
                MigrationLogger::info("Exception sideloading image {$url}: " . $e->getMessage());
            }
        }

        // Set featured image
        if ($firstAttachmentId) {
            set_post_thumbnail($postId, $firstAttachmentId);
        }

        // Set gallery meta
        if (!empty($gallery)) {
            update_post_meta($postId, 'fluent-products-gallery-image', $gallery);
        }
    }

    /**
     * Map SureCart product collections to FluentCart product-categories taxonomy.
     */
    private function migrateCollections($record, int $postId): void
    {
        $collections = $this->getValue($record, 'product_collections', []);
        if (is_object($collections) && isset($collections->data)) {
            $collections = $collections->data;
        }

        if (empty($collections) || !is_array($collections)) {
            return;
        }

        $termIds = [];

        foreach ($collections as $collection) {
            $collectionId = $this->getValue($collection, 'id', '');
            $collectionName = $this->getValue($collection, 'name', '');
            $collectionSlug = $this->getValue($collection, 'slug', sanitize_title($collectionName));

            if (empty($collectionName)) {
                continue;
            }

            // Check if this collection was already mapped
            $existingTermId = IdMapper::getFluentCartId('collection', (string) $collectionId);
            if ($existingTermId) {
                $termIds[] = $existingTermId;
                continue;
            }

            // Check if term already exists by slug
            $existingTerm = get_term_by('slug', $collectionSlug, 'product-categories');
            if ($existingTerm) {
                $termIds[] = $existingTerm->term_id;
                if ($collectionId) {
                    IdMapper::set('collection', (string) $collectionId, $existingTerm->term_id);
                }
                continue;
            }

            // Create new term
            $result = wp_insert_term($collectionName, 'product-categories', [
                'slug' => $collectionSlug,
            ]);

            if (!is_wp_error($result)) {
                $termIds[] = $result['term_id'];
                if ($collectionId) {
                    IdMapper::set('collection', (string) $collectionId, $result['term_id']);
                }
            }
        }

        if (!empty($termIds)) {
            wp_set_object_terms($postId, $termIds, 'product-categories');
        }
    }
}
