<?php

namespace App\Filament\Exports\Accounting;

use App\Models\Accounting\Bill;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

class BillExporter extends Exporter
{
    protected static ?string $model = Bill::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('bill_number'),
            ExportColumn::make('date')
                ->date(),
            ExportColumn::make('due_date')
                ->date(),
            ExportColumn::make('vendor.name'),
            ExportColumn::make('status')
                ->enum(),
            ExportColumn::make('total')
                ->money(),
            ExportColumn::make('amount_paid')
                ->money(),
            ExportColumn::make('amount_due')
                ->money(),
            ExportColumn::make('subtotal')
                ->money(),
            ExportColumn::make('tax_total')
                ->money(),
            ExportColumn::make('discount_total')
                ->money(),
            ExportColumn::make('discount_rate'),
            ExportColumn::make('currency_code'),
            ExportColumn::make('order_number'),
            ExportColumn::make('paid_at')
                ->dateTime(),
            ExportColumn::make('notes')
                ->enabledByDefault(false),
            ExportColumn::make('discount_method')
                ->enabledByDefault(false)
                ->enum(),
            ExportColumn::make('discount_computation')
                ->enabledByDefault(false)
                ->enum(),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your bill export has completed and ' . number_format($export->successful_rows) . ' ' . str('row')->plural($export->successful_rows) . ' exported.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' ' . number_format($failedRowsCount) . ' ' . str('row')->plural($failedRowsCount) . ' failed to export.';
        }

        return $body;
    }
}
