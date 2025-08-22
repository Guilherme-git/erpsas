<?php

namespace App\Filament\Exports\Accounting;

use App\Models\Accounting\Estimate;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

class EstimateExporter extends Exporter
{
    protected static ?string $model = Estimate::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('estimate_number'),
            ExportColumn::make('date')
                ->date(),
            ExportColumn::make('expiration_date')
                ->date(),
            ExportColumn::make('client.name'),
            ExportColumn::make('status')
                ->enum(),
            ExportColumn::make('total')
                ->money(),
            ExportColumn::make('subtotal')
                ->money(),
            ExportColumn::make('tax_total')
                ->money(),
            ExportColumn::make('discount_total')
                ->money(),
            ExportColumn::make('discount_rate'),
            ExportColumn::make('currency_code'),
            ExportColumn::make('reference_number'),
            ExportColumn::make('approved_at')
                ->dateTime(),
            ExportColumn::make('accepted_at')
                ->dateTime(),
            ExportColumn::make('declined_at')
                ->dateTime(),
            ExportColumn::make('converted_at')
                ->dateTime(),
            ExportColumn::make('last_sent_at')
                ->dateTime(),
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
        $body = 'Your estimate export has completed and ' . number_format($export->successful_rows) . ' ' . str('row')->plural($export->successful_rows) . ' exported.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' ' . number_format($failedRowsCount) . ' ' . str('row')->plural($failedRowsCount) . ' failed to export.';
        }

        return $body;
    }
}
