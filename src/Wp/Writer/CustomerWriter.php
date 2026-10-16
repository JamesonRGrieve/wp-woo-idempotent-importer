<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Wp\Writer;

use IdempotentImporter\Mapping\Desired;
use IdempotentImporter\Mapping\Values;
use IdempotentImporter\Model\Kind;
use IdempotentImporter\Store\Link;
use IdempotentImporter\Wp\Dates;
use IdempotentImporter\Wp\Meta\MetaSync;
use IdempotentImporter\Wp\Meta\WcDataMeta;
use IdempotentImporter\Wp\Meta\WpMeta;
use IdempotentImporter\Wp\Retirement;
use WC_Customer;
use WP_User;

/**
 * Customers → WooCommerce customers. A WordPress user with the same email is adopted rather than
 * duplicated; an adopted user's role is never changed. New customers get an unusable random password
 * and no emails: Shopify passwords can't be exported, so customers reset theirs at cutover.
 */
final class CustomerWriter implements Writer
{
    private const ROLE = 'customer';
    private const PASSWORD_LENGTH = 32;
    private const ADDRESS_FIELDS = ['first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone'];
    private const CONSUMED = ['email', 'username', 'first_name', 'last_name', 'phone', 'locale', 'note', 'created_at', 'billing', 'shipping'];

    public function kind(): Kind
    {
        return Kind::Customer;
    }

    public function write(Desired $desired, ?Link $existing): int
    {
        $f = Values::of($desired);
        $email = $f->nullableString('email');
        $customer = new WC_Customer($this->userId($existing, $email));
        if ($customer->get_id() === 0) {
            $customer->set_username($f->string('username'));
            $customer->set_password(wp_generate_password(self::PASSWORD_LENGTH, true, true));
            $customer->set_role(self::ROLE);
            $customer->set_date_created(Dates::gmt($f->string('created_at')));
        }
        $customer->set_email($email ?? '');
        $customer->set_first_name($f->nullableString('first_name') ?? '');
        $customer->set_last_name($f->nullableString('last_name') ?? '');
        $customer->set_display_name(trim(($f->nullableString('first_name') ?? '') . ' ' . ($f->nullableString('last_name') ?? '')) ?: ($email ?? $f->string('username')));
        $this->address($customer, 'billing', $f->record('billing'));
        $this->address($customer, 'shipping', $f->record('shipping'));
        $customer->set_billing_email($email ?? '');
        $phone = $f->nullableString('phone');
        if ($phone !== null) {
            $customer->set_billing_phone($phone);
        }
        MetaSync::mirror(new WcDataMeta($customer), $desired->gid, $f, self::CONSUMED);
        $id = $customer->save();
        $this->userFields($id, $f->nullableString('locale'), $f->nullableString('note'));

        return $id;
    }

    public function exists(int $targetId): bool
    {
        return get_user_by('id', $targetId) instanceof WP_User;
    }

    public function retire(Link $link): void
    {
        Retirement::mark(WpMeta::user($link->targetId));
    }

    private function userId(?Link $existing, ?string $email): int
    {
        if ($existing !== null && $this->exists($existing->targetId)) {
            return $existing->targetId;
        }
        $adopted = $email === null ? false : get_user_by('email', $email);

        return $adopted instanceof WP_User ? $adopted->ID : 0;
    }

    private function address(WC_Customer $customer, string $type, ?Values $address): void
    {
        foreach (self::ADDRESS_FIELDS as $field) {
            $customer->{"set_{$type}_{$field}"}($address?->nullableString($field) ?? '');
        }
    }

    /** Fields of the WordPress user itself that WC_Customer doesn't manage. */
    private function userFields(int $userId, ?string $locale, ?string $note): void
    {
        $user = get_user_by('id', $userId);
        if (!$user instanceof WP_User) {
            return;
        }
        $changes = [];
        $wpLocale = $locale === null ? '' : str_replace('-', '_', $locale);
        if ($user->locale !== $wpLocale) {
            $changes['locale'] = $wpLocale;
        }
        if ($user->description !== ($note ?? '')) {
            $changes['description'] = $note ?? '';
        }
        if ($changes !== []) {
            wp_update_user(['ID' => $userId, ...$changes]);
        }
    }
}
