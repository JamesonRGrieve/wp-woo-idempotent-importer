<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Wp;

use IdempotentImporter\Store\GiftCardRecord;
use IdempotentImporter\Store\GiftCardStore;
use IdempotentImporter\Store\LedgerEntry;

/**
 * "Gift cards" in the customer's My Account: each of their mirrored gift cards and store credit accounts,
 * its Square card number (to spend online or at the counter) and its ledger: the history from the store it
 * came from, then what was brought into Square. Shown only to the card's own customer, and only when they
 * have one. Square holds the spendable balance; this page shows the balance as the source last reported it.
 */
final class GiftCardAccount
{
    public const ENDPOINT = 'gift-cards';
    private const REWRITE_OPTION = 'iim_gift_card_endpoint';
    private const REWRITE_VERSION = 1;
    private const NUMBER_GROUP = 4;
    private const DATE_FORMAT = 'Y-m-d';
    private const TEXT_DOMAIN = 'wp-woo-idempotent-importer';
    private const KIND_LABELS = [
        'issue' => 'Issued',
        'redeem' => 'Spent',
        'credit' => 'Added',
        'debit' => 'Deducted',
        'adjust' => 'Adjusted',
        'expire' => 'Expired',
        'refund' => 'Refunded to the card',
        'observed' => 'Balance change at the store',
        'activate' => 'Brought into Square',
        'adjust_increment' => 'Added in Square',
        'adjust_decrement' => 'Deducted in Square',
    ];

    public function __construct(private readonly GiftCardStore $cards) {}

    public function register(): void
    {
        add_action('init', $this->addEndpoint(...));
        add_filter('woocommerce_get_query_vars', $this->queryVars(...));
        add_filter('woocommerce_account_menu_items', $this->menuItems(...));
        add_action('woocommerce_account_' . self::ENDPOINT . '_endpoint', $this->render(...));
    }

    public function addEndpoint(): void
    {
        add_rewrite_endpoint(self::ENDPOINT, EP_ROOT | EP_PAGES);
        if (Options::int(self::REWRITE_OPTION) !== self::REWRITE_VERSION) {
            flush_rewrite_rules(false);
            update_option(self::REWRITE_OPTION, self::REWRITE_VERSION, false);
        }
    }

    /**
     * @param array<string, string> $vars
     *
     * @return array<string, string>
     */
    public function queryVars(array $vars): array
    {
        return [...$vars, self::ENDPOINT => self::ENDPOINT];
    }

    /**
     * @param array<string, string> $items
     *
     * @return array<string, string>
     */
    public function menuItems(array $items): array
    {
        if ($this->cards->forUser(get_current_user_id()) === []) {
            return $items;
        }
        $logout = array_slice($items, -1, 1, true);

        return [...array_slice($items, 0, -1, true), self::ENDPOINT => __('Gift cards', self::TEXT_DOMAIN), ...$logout];
    }

    public function render(): void
    {
        $cards = $this->cards->forUser(get_current_user_id());
        if ($cards === []) {
            echo '<p>' . esc_html__('You have no gift cards.', self::TEXT_DOMAIN) . '</p>';

            return;
        }
        foreach ($cards as $card) {
            $this->card($card);
        }
    }

    private function card(GiftCardRecord $card): void
    {
        $title = $card->kind === GiftCardRecord::STORE_CREDIT ? __('Store credit', self::TEXT_DOMAIN) : __('Gift card', self::TEXT_DOMAIN);
        $headingId = 'iim-gift-card-' . $card->id;
        echo '<section class="iim-gift-card" aria-labelledby="' . esc_attr($headingId) . '">';
        echo '<h3 id="' . esc_attr($headingId) . '">' . esc_html($title) . ' ' . esc_html(self::grouped($card->gan)) . '</h3>';
        echo '<p>' . esc_html(sprintf(
            /* translators: %s: the card's balance */
            __('Balance: %s. Spend it online or in store with this number.', self::TEXT_DOMAIN),
            self::price($card->sourceBalance, $card->currency),
        )) . '</p>';
        $ledger = $this->cards->ledger($card->id);
        if ($ledger === []) {
            echo '</section>';

            return;
        }
        echo '<table class="woocommerce-table shop_table"><caption>' . esc_html__('History', self::TEXT_DOMAIN) . '</caption><thead><tr>';
        foreach ([__('Date', self::TEXT_DOMAIN), __('What', self::TEXT_DOMAIN), __('Amount', self::TEXT_DOMAIN), __('Balance after', self::TEXT_DOMAIN)] as $heading) {
            echo '<th scope="col">' . esc_html($heading) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($ledger as $entry) {
            $this->entry($entry, $card->currency);
        }
        echo '</tbody></table></section>';
    }

    private function entry(LedgerEntry $entry, string $currency): void
    {
        $date = $entry->occurredAt === null ? __('Before the move', self::TEXT_DOMAIN) : wp_date(self::DATE_FORMAT, (int) strtotime($entry->occurredAt));
        $label = __(self::KIND_LABELS[$entry->kind] ?? ucfirst(str_replace('_', ' ', $entry->kind)), self::TEXT_DOMAIN);
        echo '<tr><td>' . esc_html((string) $date) . '</td><td>' . esc_html($label) . '</td><td>' . esc_html(self::price($entry->amount, $currency))
            . '</td><td>' . esc_html($entry->balanceAfter === null ? '' : self::price($entry->balanceAfter, $currency)) . '</td></tr>';
    }

    private static function grouped(string $gan): string
    {
        return implode(' ', str_split($gan, self::NUMBER_GROUP));
    }

    private static function price(string $amount, string $currency): string
    {
        return html_entity_decode(wp_strip_all_tags(wc_price((float) $amount, ['currency' => $currency])), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
