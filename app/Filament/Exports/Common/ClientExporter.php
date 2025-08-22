<?php

namespace App\Filament\Exports\Common;

use App\Models\Common\Client;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

class ClientExporter extends Exporter
{
    protected static ?string $model = Client::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('name'),
            ExportColumn::make('account_number'),
            ExportColumn::make('primaryContact.full_name')
                ->label('Primary contact'),
            ExportColumn::make('primaryContact.email')
                ->label('Email'),
            ExportColumn::make('primaryContact.first_available_phone')
                ->label('Phone'),
            ExportColumn::make('currency_code'),
            ExportColumn::make('balance') // TODO: Potentially find an easier way to calculate this
                ->state(function (Client $record) {
                    return $record->invoices()
                        ->unpaid()
                        ->get()
                        ->sumMoneyInDefaultCurrency('amount_due');
                })
                ->money(),
            ExportColumn::make('overdue_amount')
                ->state(function (Client $record) {
                    return $record->invoices()
                        ->overdue()
                        ->get()
                        ->sumMoneyInDefaultCurrency('amount_due');
                })
                ->money(),
            ExportColumn::make('billingAddress.address_string')
                ->label('Billing address')
                ->enabledByDefault(false),
            ExportColumn::make('billingAddress.address_line_1')
                ->label('Billing address line 1'),
            ExportColumn::make('billingAddress.address_line_2')
                ->label('Billing address line 2'),
            ExportColumn::make('billingAddress.city')
                ->label('Billing city'),
            ExportColumn::make('billingAddress.state.name')
                ->label('Billing state'),
            ExportColumn::make('billingAddress.postal_code')
                ->label('Billing postal code'),
            ExportColumn::make('billingAddress.country.name')
                ->label('Billing country'),
            ExportColumn::make('shippingAddress.recipient')
                ->label('Shipping recipient')
                ->enabledByDefault(false),
            ExportColumn::make('shippingAddress.phone')
                ->label('Shipping phone')
                ->enabledByDefault(false),
            ExportColumn::make('shippingAddress.address_string')
                ->label('Shipping address')
                ->enabledByDefault(false),
            ExportColumn::make('shippingAddress.address_line_1')
                ->label('Shipping address line 1')
                ->enabledByDefault(false),
            ExportColumn::make('shippingAddress.address_line_2')
                ->label('Shipping address line 2')
                ->enabledByDefault(false),
            ExportColumn::make('shippingAddress.city')
                ->label('Shipping city')
                ->enabledByDefault(false),
            ExportColumn::make('shippingAddress.state.name')
                ->label('Shipping state')
                ->enabledByDefault(false),
            ExportColumn::make('shippingAddress.postal_code')
                ->label('Shipping postal code')
                ->enabledByDefault(false),
            ExportColumn::make('shippingAddress.country.name')
                ->label('Shipping country')
                ->enabledByDefault(false),
            ExportColumn::make('shippingAddress.notes')
                ->label('Delivery instructions')
                ->enabledByDefault(false),
            ExportColumn::make('website')
                ->enabledByDefault(false),
            ExportColumn::make('notes')
                ->enabledByDefault(false),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your client export has completed and ' . number_format($export->successful_rows) . ' ' . str('row')->plural($export->successful_rows) . ' exported.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' ' . number_format($failedRowsCount) . ' ' . str('row')->plural($failedRowsCount) . ' failed to export.';
        }

        return $body;
    }
}
