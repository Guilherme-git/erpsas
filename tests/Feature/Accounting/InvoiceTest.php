<?php

use App\Enums\Accounting\InvoiceStatus;
use App\Models\Accounting\Invoice;
use App\Utilities\Currency\CurrencyAccessor;

beforeEach(function () {
    $this->defaultCurrency = CurrencyAccessor::getDefaultCurrency();
    $this->withOfferings();
});

it('creates a basic invoice with line items and calculates totals correctly', function () {
    $invoice = Invoice::factory()
        ->withLineItems()
        ->create();

    $invoice->refresh();

    expect($invoice)
        ->hasLineItems()->toBeTrue()
        ->lineItems->count()->toBe(3)
        ->subtotal->toBeGreaterThan(0)
        ->total->toBeGreaterThan(0)
        ->amount_due->toBe($invoice->total);
});

test('approved invoices are marked as Unsent when not Overdue', function () {
    $invoice = Invoice::factory()
        ->withLineItems()
        ->state([
            'due_date' => now()->addDays(30),
        ])
        ->create();

    $invoice->refresh();

    $invoice->approveDraft();

    expect($invoice)
        ->hasLineItems()->toBeTrue()
        ->status->toBe(InvoiceStatus::Unsent)
        ->wasApproved()->toBeTrue()
        ->approvalTransaction->not->toBeNull();
});

test('approved invoices are marked as Overdue when Overdue', function () {
    $invoice = Invoice::factory()
        ->withLineItems()
        ->state([
            'due_date' => now()->subDays(30),
        ])
        ->create();

    $invoice->refresh();

    $invoice->approveDraft();

    expect($invoice)
        ->hasLineItems()->toBeTrue()
        ->status->toBe(InvoiceStatus::Overdue)
        ->wasApproved()->toBeTrue()
        ->approvalTransaction->not->toBeNull();
});
