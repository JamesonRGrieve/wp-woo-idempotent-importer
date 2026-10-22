<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tests\Unit;

use IdempotentImporter\Mapping\PlaceholderEmail;
use IdempotentImporter\Wp\PlaceholderMail;
use PHPUnit\Framework\TestCase;

final class PlaceholderEmailTest extends TestCase
{
    public function testAPlaceholderIsTheSameEveryRunUnderTheReservedDomain(): void
    {
        self::assertSame('client-42@freshbooks.invalid', PlaceholderEmail::for('freshbooks', 'client', '42'));
        self::assertSame('customer-12@quickbooks-online.invalid', PlaceholderEmail::for('quickbooks_online', 'customer', '12'), 'a host name has no underscore');
        $odd = PlaceholderEmail::for('wave', 'customer', 'QnVzaW5lc3M6MQ==/Customer:7');
        self::assertMatchesRegularExpression('/^customer-[0-9a-f]{20}@wave\.invalid$/', $odd, 'an id an address can\'t hold is hashed');
        self::assertSame($odd, PlaceholderEmail::for('wave', 'customer', 'QnVzaW5lc3M6MQ==/Customer:7'));
    }

    public function testFieldsMarkOnlyAPlaceholder(): void
    {
        self::assertSame(['email' => 'ada@example.com'], PlaceholderEmail::fields(' Ada@Example.com ', 'hubspot', 'contact', '1'));
        self::assertSame(['email' => 'contact-1@hubspot.invalid', 'email_placeholder' => true], PlaceholderEmail::fields(null, 'hubspot', 'contact', '1'));
        self::assertSame(['email' => 'contact-1@hubspot.invalid', 'email_placeholder' => true], PlaceholderEmail::fields('', 'hubspot', 'contact', '1'));
    }

    public function testPlaceholdersAreNeverDeliverable(): void
    {
        self::assertTrue(PlaceholderEmail::isPlaceholder('Contact-1@HubSpot.INVALID'));
        self::assertFalse(PlaceholderEmail::isPlaceholder('ada@invalid.example.com'));
        self::assertSame(['ada@example.com', 'Bo <bo@example.com>'], PlaceholderEmail::deliverable(['ada@example.com', 'contact-1@hubspot.invalid', 'Bo <bo@example.com>', 'Cy <client-3@freshbooks.invalid>']));
    }

    public function testMailToPlaceholdersIsDroppedAndAMessageLeftWithNoRecipientIsNotSent(): void
    {
        $message = PlaceholderMail::withoutPlaceholders([
            'to' => 'contact-1@hubspot.invalid, ada@example.com',
            'subject' => 'Hi',
            'headers' => "From: shop@example.com\r\nCc: client-3@freshbooks.invalid\r\nBcc: bo@example.com, record-9@zoho.invalid",
        ]);

        self::assertSame(['ada@example.com'], $message['to']);
        self::assertSame(['From: shop@example.com', 'Bcc: bo@example.com'], $message['headers'], 'placeholders leave Cc and Bcc too');
        self::assertNull(PlaceholderMail::skipWithoutRecipients(null, $message), 'a message with a real recipient is sent');

        $onlyPlaceholders = PlaceholderMail::withoutPlaceholders(['to' => ['contact-1@hubspot.invalid'], 'headers' => []]);
        self::assertFalse(PlaceholderMail::skipWithoutRecipients(null, $onlyPlaceholders), 'nothing is sent');
        self::assertNull(PlaceholderMail::skipWithoutRecipients(null, ['to' => [], 'headers' => ['Bcc: bo@example.com']]), 'a Bcc-only message is still sent');
        self::assertTrue(PlaceholderMail::skipWithoutRecipients(true, $onlyPlaceholders), "an earlier filter's decision stands");
    }
}
