<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Source\Wave;

/**
 * The GraphQL operations the Wave adapter sends to Wave's public API, by operation name. Each is validated against
 * Wave's pinned schema, and every fixture is executed back through its operation (tools/validate-wave.php). Lists
 * are oldest first, so a record created mid-run doesn't shift the pages.
 */
final class Operations
{
    public const BUSINESSES = 'Businesses';
    public const PRODUCTS = 'Products';
    public const CUSTOMERS = 'Customers';
    public const INVOICES = 'Invoices';

    private const PAGE_INFO = 'pageInfo { currentPage totalPages totalCount }';
    private const ADDRESS = 'fragment AddressFields on Address { addressLine1 addressLine2 city postalCode province { code name } country { code name } }';

    /** @return array<string, string> operation name → document */
    public static function all(): array
    {
        return [
            self::BUSINESSES => 'query Businesses($page: Int!, $pageSize: Int!) {
  businesses(page: $page, pageSize: $pageSize) {
    ' . self::PAGE_INFO . '
    edges { node { id name isArchived currency { code } } }
  }
}',
            self::PRODUCTS => 'query Products($businessId: ID!, $page: Int!, $pageSize: Int!, $isArchived: Boolean!, $modifiedAtAfter: DateTime) {
  business(id: $businessId) {
    id
    products(page: $page, pageSize: $pageSize, sort: [CREATED_AT_ASC], isArchived: $isArchived, modifiedAtAfter: $modifiedAtAfter) {
      ' . self::PAGE_INFO . '
      edges { node {
        id name description unitPrice isSold isBought isArchived createdAt modifiedAt
        defaultSalesTaxes { id name abbreviation }
        incomeAccount { name }
        expenseAccount { name }
      } }
    }
  }
}',
            self::CUSTOMERS => 'query Customers($businessId: ID!, $page: Int!, $pageSize: Int!, $modifiedAtAfter: DateTime) {
  business(id: $businessId) {
    id
    customers(page: $page, pageSize: $pageSize, sort: [CREATED_AT_ASC], modifiedAtAfter: $modifiedAtAfter) {
      ' . self::PAGE_INFO . '
      edges { node {
        id name firstName lastName displayId email mobile phone fax tollFree website internalNotes isArchived createdAt modifiedAt
        currency { code }
        address { ...AddressFields }
        shippingDetails { name phone instructions address { ...AddressFields } }
      } }
    }
  }
}
' . self::ADDRESS,
            self::INVOICES => 'query Invoices($businessId: ID!, $page: Int!, $pageSize: Int!, $modifiedAtAfter: DateTime) {
  business(id: $businessId) {
    id
    invoices(page: $page, pageSize: $pageSize, sort: [CREATED_AT_ASC], modifiedAtAfter: $modifiedAtAfter) {
      ' . self::PAGE_INFO . '
      edges { node {
        id createdAt modifiedAt status title subhead invoiceNumber poNumber invoiceDate dueDate memo footer exchangeRate
        currency { code }
        customer { id name firstName lastName email phone address { ...AddressFields } }
        subtotal { value }
        discountTotal { value }
        taxTotal { value }
        total { value }
        amountPaid { value }
        amountDue { value }
        discounts { __typename name ... on FixedInvoiceDiscount { amount } ... on PercentageInvoiceDiscount { percentage } }
        items {
          description quantity unitPrice
          subtotal { value }
          total { value }
          product { id name }
          taxes { amount { value } salesTax { id name abbreviation rate } }
        }
        payments { id paymentDate amount paymentMethod memo active transactionId createdAt }
      } }
    }
  }
}
' . self::ADDRESS,
        ];
    }

    public static function document(string $name): string
    {
        return self::all()[$name] ?? throw new \InvalidArgumentException("No Wave operation {$name}.");
    }
}
