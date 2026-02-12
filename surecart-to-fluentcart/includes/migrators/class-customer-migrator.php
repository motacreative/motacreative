<?php

namespace ScToFct\Migrators;

use ScToFct\IdMapper;
use ScToFct\MigrationLogger;

if (!defined('ABSPATH')) {
    exit;
}

class CustomerMigrator extends BaseMigrator
{
    public function getEntityType(): string
    {
        return 'customer';
    }

    public function fetchBatch(int $page, int $perPage): array
    {
        $response = \SureCart\Models\Customer::with([
            'billing_address',
            'shipping_address',
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

        $email = $this->getValue($record, 'email', '');
        $firstName = $this->getValue($record, 'first_name', '');
        $lastName = $this->getValue($record, 'last_name', '');
        $phone = $this->getValue($record, 'phone', '');
        $createdAt = $this->getValue($record, 'created_at');

        if (empty($email)) {
            throw new \Exception('Customer has no email address.');
        }

        // Check if a FluentCart customer already exists with this email (dedup)
        $existingCustomer = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}fct_customers WHERE email = %s LIMIT 1",
                $email
            )
        );

        if ($existingCustomer) {
            MigrationLogger::info("Customer '{$email}' already exists in FluentCart (ID {$existingCustomer->id}), linking.");
            $customerId = (int) $existingCustomer->id;
        } else {
            // Look up WordPress user by email
            $wpUser = get_user_by('email', $email);
            $userId = $wpUser ? $wpUser->ID : null;

            // Extract billing address for top-level customer fields
            $billingAddress = $this->getValue($record, 'billing_address');
            $country = $this->getValue($billingAddress, 'country', '');
            $city = $this->getValue($billingAddress, 'city', '');
            $state = $this->getValue($billingAddress, 'state', '');
            $postcode = $this->getValue($billingAddress, 'postal_code', '');

            $scId = $this->getValue($record, 'id', '');

            $customerData = [
                'user_id'    => $userId,
                'email'      => $email,
                'first_name' => $firstName,
                'last_name'  => $lastName,
                'status'     => 'active',
                'notes'      => "Migrated from SureCart (ID: {$scId})",
                'country'    => $country,
                'city'       => $city,
                'state'      => $state,
                'postcode'   => $postcode,
                'uuid'       => wp_generate_uuid4(),
                'created_at' => $createdAt ? gmdate('Y-m-d H:i:s', strtotime($createdAt)) : current_time('mysql', true),
                'updated_at' => current_time('mysql', true),
            ];

            $wpdb->insert($wpdb->prefix . 'fct_customers', $customerData);
            $customerId = (int) $wpdb->insert_id;

            if (!$customerId) {
                throw new \Exception('Failed to insert customer into fct_customers.');
            }
        }

        // Create billing address
        $this->migrateAddress($record, $customerId, 'billing');

        // Create shipping address
        $this->migrateAddress($record, $customerId, 'shipping');

        MigrationLogger::info("Migrated customer '{$email}' → FluentCart ID {$customerId}.");

        return $customerId;
    }

    /**
     * Create a customer address record.
     */
    private function migrateAddress($record, int $customerId, string $type): void
    {
        global $wpdb;

        $addressKey = $type === 'billing' ? 'billing_address' : 'shipping_address';
        $address = $this->getValue($record, $addressKey);

        if (!$address) {
            return;
        }

        $firstName = $this->getValue($address, 'first_name', $this->getValue($record, 'first_name', ''));
        $lastName = $this->getValue($address, 'last_name', $this->getValue($record, 'last_name', ''));
        $name = trim($firstName . ' ' . $lastName);

        $addressData = [
            'customer_id' => $customerId,
            'is_primary'  => $type === 'billing' ? 1 : 0,
            'type'        => $type,
            'status'      => 'active',
            'label'       => ucfirst($type),
            'name'        => $name,
            'address_1'   => $this->getValue($address, 'line_1', $this->getValue($address, 'address_1', '')),
            'address_2'   => $this->getValue($address, 'line_2', $this->getValue($address, 'address_2', '')),
            'city'        => $this->getValue($address, 'city', ''),
            'state'       => $this->getValue($address, 'state', ''),
            'phone'       => $this->getValue($address, 'phone_number', $this->getValue($address, 'phone', '')),
            'email'       => $this->getValue($record, 'email', ''),
            'postcode'    => $this->getValue($address, 'postal_code', $this->getValue($address, 'postcode', '')),
            'country'     => $this->getValue($address, 'country', ''),
            'created_at'  => current_time('mysql', true),
            'updated_at'  => current_time('mysql', true),
        ];

        $wpdb->insert($wpdb->prefix . 'fct_customer_addresses', $addressData);
    }
}
