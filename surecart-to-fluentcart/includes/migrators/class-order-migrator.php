<?php

namespace ScToFct\Migrators;

use ScToFct\IdMapper;
use ScToFct\MigrationLogger;

if (!defined('ABSPATH')) {
    exit;
}

class OrderMigrator extends BaseMigrator
{
    public function getEntityType(): string
    {
        return 'order';
    }

    public function fetchBatch(int $page, int $perPage): array
    {
        $response = \SureCart\Models\Order::with([
            'checkout',
            'checkout.customer',
            'checkout.line_items',
            'checkout.line_items.price',
            'checkout.charges',
            'checkout.billing_address',
            'checkout.shipping_address',
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

        $checkout = $this->getValue($record, 'checkout');
        if (!$checkout) {
            throw new \Exception('Order has no checkout data.');
        }

        // Resolve customer
        $scCustomerId = $this->getCustomerIdFromCheckout($checkout);
        $fctCustomerId = null;
        if ($scCustomerId) {
            $fctCustomerId = IdMapper::getFluentCartId('customer', $scCustomerId);
        }

        // Map status
        $scStatus = $this->getValue($checkout, 'status', 'pending');
        $statusMap = $this->mapStatus($scStatus);

        // Currency
        $currency = strtoupper($this->getValue($checkout, 'currency', 'USD'));

        // Compute line items and totals
        $lineItems = $this->getValue($checkout, 'line_items', []);
        if (is_object($lineItems) && isset($lineItems->data)) {
            $lineItems = $lineItems->data;
        }

        $subtotal = 0;
        $fulfillmentType = 'digital';

        $orderItemsData = [];
        if (!empty($lineItems) && is_array($lineItems)) {
            foreach ($lineItems as $index => $lineItem) {
                $itemData = $this->buildOrderItemData($lineItem, $index);
                $orderItemsData[] = $itemData;
                $subtotal += $itemData['subtotal'];

                if ($itemData['fulfillment_type'] === 'physical') {
                    $fulfillmentType = 'physical';
                }
            }
        }

        // Financial totals
        $taxAmount = (int) $this->getValue($checkout, 'tax_amount', 0);
        $discountAmount = (int) $this->getValue($checkout, 'discount_amount', 0);
        $totalAmount = (int) $this->getValue($checkout, 'total_amount', 0);

        // If SureCart didn't provide a total, calculate it
        if ($totalAmount === 0 && $subtotal > 0) {
            $totalAmount = $subtotal + $taxAmount - $discountAmount;
        }

        // Payment info from charges
        $charges = $this->getValue($checkout, 'charges', []);
        if (is_object($charges) && isset($charges->data)) {
            $charges = $charges->data;
        }

        $totalPaid = 0;
        $totalRefund = 0;
        $paymentMethod = 'offline_payment';
        $paymentMethodTitle = 'SureCart Payment';

        if (!empty($charges) && is_array($charges)) {
            foreach ($charges as $charge) {
                $chargeAmount = (int) $this->getValue($charge, 'amount', 0);
                $chargeRefunded = (int) $this->getValue($charge, 'amount_refunded', 0);
                $totalPaid += $chargeAmount;
                $totalRefund += $chargeRefunded;

                $processor = $this->getValue($charge, 'processor_type', '');
                if ($processor) {
                    $paymentMethodTitle = 'SureCart (' . ucfirst($processor) . ')';
                }
            }
        }

        if ($statusMap['payment_status'] === 'paid') {
            $totalPaid = max($totalPaid, $totalAmount);
        }

        // Generate receipt/invoice numbers
        $maxReceipt = (int) $wpdb->get_var("SELECT MAX(receipt_number) FROM {$wpdb->prefix}fct_orders");
        $receiptNumber = $maxReceipt + 1;
        $invoiceNo = 'SC-' . $receiptNumber;

        $createdAt = $this->getValue($record, 'created_at');
        $createdAtFormatted = $createdAt ? gmdate('Y-m-d H:i:s', strtotime($createdAt)) : current_time('mysql', true);

        $completedAt = null;
        if ($statusMap['status'] === 'completed') {
            $completedAt = $createdAtFormatted;
        }

        // Insert the order
        $orderData = [
            'parent_id'              => 0,
            'status'                 => $statusMap['status'],
            'type'                   => 'payment',
            'mode'                   => 'live',
            'receipt_number'         => $receiptNumber,
            'invoice_no'             => $invoiceNo,
            'fulfillment_type'       => $fulfillmentType,
            'customer_id'            => $fctCustomerId,
            'payment_method'         => $paymentMethod,
            'payment_method_title'   => $paymentMethodTitle,
            'payment_status'         => $statusMap['payment_status'],
            'currency'               => $currency,
            'subtotal'               => $subtotal,
            'discount_tax'           => 0,
            'manual_discount_total'  => 0,
            'coupon_discount_total'  => $discountAmount,
            'shipping_tax'           => 0,
            'shipping_total'         => 0,
            'tax_total'              => $taxAmount,
            'total_amount'           => $totalAmount,
            'total_paid'             => $totalPaid,
            'total_refund'           => $totalRefund,
            'note'                   => '',
            'ip_address'             => '',
            'uuid'                   => wp_generate_uuid4(),
            'completed_at'           => $completedAt,
            'created_at'             => $createdAtFormatted,
            'updated_at'             => current_time('mysql', true),
        ];

        $wpdb->insert($wpdb->prefix . 'fct_orders', $orderData);
        $orderId = (int) $wpdb->insert_id;

        if (!$orderId) {
            throw new \Exception('Failed to insert order into fct_orders.');
        }

        // Insert order items
        foreach ($orderItemsData as $itemData) {
            $itemData['order_id'] = $orderId;
            $wpdb->insert($wpdb->prefix . 'fcv_order_items', $itemData);
        }

        // Insert order transaction
        if (!empty($charges) && is_array($charges)) {
            foreach ($charges as $charge) {
                $this->insertTransaction($orderId, $charge, $currency, $createdAtFormatted, $paymentMethod);
            }
        } else {
            // Create a single transaction record even if no charges
            $this->insertTransaction($orderId, null, $currency, $createdAtFormatted, $paymentMethod, $totalPaid, $statusMap['payment_status']);
        }

        // Insert order addresses
        $this->insertOrderAddress($orderId, $checkout, 'billing');
        $this->insertOrderAddress($orderId, $checkout, 'shipping');

        MigrationLogger::info("Migrated order (SC: {$record->id}) → FluentCart order ID {$orderId}.");

        return $orderId;
    }

    /**
     * Extract SureCart customer ID from checkout data.
     */
    private function getCustomerIdFromCheckout($checkout): ?string
    {
        $customer = $this->getValue($checkout, 'customer');

        if (is_object($customer) && isset($customer->id)) {
            return (string) $customer->id;
        }
        if (is_string($customer)) {
            return $customer;
        }

        return null;
    }

    /**
     * Map SureCart checkout status to FluentCart order + payment status.
     */
    private function mapStatus(string $scStatus): array
    {
        $map = [
            'paid'                 => ['status' => 'completed', 'payment_status' => 'paid'],
            'payment_succeeded'    => ['status' => 'completed', 'payment_status' => 'paid'],
            'succeeded'            => ['status' => 'completed', 'payment_status' => 'paid'],
            'pending'              => ['status' => 'on-hold',   'payment_status' => 'pending'],
            'draft'                => ['status' => 'on-hold',   'payment_status' => 'pending'],
            'payment_intent_created' => ['status' => 'on-hold', 'payment_status' => 'pending'],
            'payment_failed'       => ['status' => 'failed',    'payment_status' => 'failed'],
            'failed'               => ['status' => 'failed',    'payment_status' => 'failed'],
            'canceled'             => ['status' => 'canceled',  'payment_status' => 'failed'],
            'void'                 => ['status' => 'canceled',  'payment_status' => 'failed'],
            'refunded'             => ['status' => 'canceled',  'payment_status' => 'refunded'],
            'partially_refunded'   => ['status' => 'completed', 'payment_status' => 'partially_refunded'],
        ];

        return $map[$scStatus] ?? ['status' => 'on-hold', 'payment_status' => 'pending'];
    }

    /**
     * Build order item data from a SureCart line item.
     */
    private function buildOrderItemData($lineItem, int $index): array
    {
        $quantity = (int) $this->getValue($lineItem, 'quantity', 1);
        $price = $this->getValue($lineItem, 'price');

        $unitPrice = 0;
        $productName = 'Unknown Product';
        $variationTitle = '';
        $fulfillmentType = 'digital';
        $postId = 0;
        $objectId = null;

        if ($price) {
            $unitPrice = (int) $this->getValue($price, 'amount', 0);
            $priceName = $this->getValue($price, 'name', '');
            $priceId = $this->getValue($price, 'id', '');

            // Try to resolve the product via the price's product reference
            $priceProduct = $this->getValue($price, 'product');
            if ($priceProduct) {
                $productName = $this->getValue($priceProduct, 'name', $productName);

                $scProductId = $this->getValue($priceProduct, 'id', '');
                if ($scProductId) {
                    $mappedPostId = IdMapper::getFluentCartId('product', $scProductId);
                    if ($mappedPostId) {
                        $postId = $mappedPostId;
                    }
                }
            } elseif (is_string($this->getValue($price, 'product'))) {
                // Product is a string ID reference
                $mappedPostId = IdMapper::getFluentCartId('product', $this->getValue($price, 'product'));
                if ($mappedPostId) {
                    $postId = $mappedPostId;
                }
            }

            // Resolve variation from price ID
            if ($priceId) {
                $mappedVarId = IdMapper::getFluentCartId('variant', (string) $priceId);
                if ($mappedVarId) {
                    $objectId = $mappedVarId;
                }
            }

            $variationTitle = $priceName ?: $productName;
        }

        // Check product for physical/digital
        $scProductId = $this->getValue($lineItem, 'product');
        if (is_string($scProductId)) {
            $mappedPostId = IdMapper::getFluentCartId('product', $scProductId);
            if ($mappedPostId) {
                $postId = $mappedPostId;
            }
        }

        $subtotal = $quantity * $unitPrice;
        $discountTotal = (int) $this->getValue($lineItem, 'discount_amount', 0);
        $lineTotal = $subtotal - $discountTotal;
        $taxAmount = (int) $this->getValue($lineItem, 'tax_amount', 0);

        return [
            'post_id'          => $postId,
            'fulfillment_type' => $fulfillmentType,
            'payment_type'     => 'onetime',
            'post_title'       => $productName,
            'title'            => $variationTitle,
            'object_id'        => $objectId,
            'cart_index'       => $index + 1,
            'quantity'         => $quantity,
            'unit_price'       => $unitPrice,
            'subtotal'         => $subtotal,
            'tax_amount'       => $taxAmount,
            'discount_total'   => $discountTotal,
            'line_total'       => $lineTotal,
            'created_at'       => current_time('mysql', true),
            'updated_at'       => current_time('mysql', true),
        ];
    }

    /**
     * Insert an order transaction record.
     */
    private function insertTransaction(int $orderId, $charge, string $currency, string $createdAt, string $paymentMethod, ?int $overrideTotal = null, ?string $overrideStatus = null): void
    {
        global $wpdb;

        $total = $overrideTotal ?? (int) $this->getValue($charge, 'amount', 0);
        $chargeStatus = $overrideStatus ?? $this->getValue($charge, 'status', 'pending');

        // Map charge status to transaction status
        $transactionStatusMap = [
            'succeeded'            => 'completed',
            'paid'                 => 'completed',
            'pending'              => 'pending',
            'failed'               => 'failed',
            'refunded'             => 'refunded',
            'partially_refunded'   => 'partially_refunded',
        ];
        $transactionStatus = $transactionStatusMap[$chargeStatus] ?? 'pending';

        $vendorChargeId = $charge ? (string) $this->getValue($charge, 'id', '') : '';
        $cardLast4 = $charge ? $this->getValue($charge, 'card_last_4') : null;
        $cardBrand = $charge ? $this->getValue($charge, 'card_brand', '') : '';
        $paymentMode = $charge ? ($this->getValue($charge, 'live_mode', true) ? 'live' : 'test') : 'live';

        $transactionData = [
            'order_id'       => $orderId,
            'order_type'     => 'payment',
            'transaction_type' => 'charge',
            'vendor_charge_id' => $vendorChargeId,
            'card_last_4'    => $cardLast4,
            'card_brand'     => $cardBrand,
            'payment_method' => $paymentMethod,
            'payment_mode'   => $paymentMode,
            'status'         => $transactionStatus,
            'currency'       => $currency,
            'total'          => $total,
            'uuid'           => wp_generate_uuid4(),
            'created_at'     => $createdAt,
            'updated_at'     => current_time('mysql', true),
        ];

        $wpdb->insert($wpdb->prefix . 'fct_order_transactions', $transactionData);
    }

    /**
     * Insert an order address record.
     */
    private function insertOrderAddress(int $orderId, $checkout, string $type): void
    {
        global $wpdb;

        $addressKey = $type === 'billing' ? 'billing_address' : 'shipping_address';
        $address = $this->getValue($checkout, $addressKey);

        if (!$address) {
            return;
        }

        $firstName = $this->getValue($address, 'first_name', '');
        $lastName = $this->getValue($address, 'last_name', '');
        $name = trim($firstName . ' ' . $lastName);

        if (empty($name)) {
            $name = $this->getValue($address, 'name', '');
        }

        $addressData = [
            'order_id'   => $orderId,
            'type'       => $type,
            'name'       => $name,
            'address_1'  => $this->getValue($address, 'line_1', $this->getValue($address, 'address_1', '')),
            'address_2'  => $this->getValue($address, 'line_2', $this->getValue($address, 'address_2', '')),
            'city'       => $this->getValue($address, 'city', ''),
            'state'      => $this->getValue($address, 'state', ''),
            'postcode'   => $this->getValue($address, 'postal_code', $this->getValue($address, 'postcode', '')),
            'country'    => $this->getValue($address, 'country', ''),
            'created_at' => current_time('mysql', true),
            'updated_at' => current_time('mysql', true),
        ];

        $wpdb->insert($wpdb->prefix . 'fct_order_addresses', $addressData);
    }
}
