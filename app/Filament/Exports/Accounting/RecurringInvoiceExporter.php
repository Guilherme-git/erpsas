<?php

namespace App\Filament\Exports\Accounting;

use App\Models\Accounting\RecurringInvoice;
use Carbon\Carbon;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

class RecurringInvoiceExporter extends Exporter
{
    protected static ?string $model = RecurringInvoice::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('order_number'),
            ExportColumn::make('client.name'),
            ExportColumn::make('status')
                ->enum(),
            ExportColumn::make('schedule')
                ->formatStateUsing(function ($state, RecurringInvoice $record) {
                    return $record->getScheduleDescription();
                }),
            ExportColumn::make('timeline')
                ->formatStateUsing(function ($state, RecurringInvoice $record) {
                    return $record->getTimelineDescription();
                }),
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
            ExportColumn::make('payment_terms')
                ->enum(),
            ExportColumn::make('start_date')
                ->date(),
            ExportColumn::make('end_date')
                ->date(),
            ExportColumn::make('next_date')
                ->date(),
            ExportColumn::make('last_date')
                ->date(),
            ExportColumn::make('approved_at')
                ->dateTime(),
            ExportColumn::make('ended_at')
                ->dateTime(),
            ExportColumn::make('occurrences_count'),
            ExportColumn::make('max_occurrences'),
            ExportColumn::make('send_time')
                ->formatStateUsing(function (?Carbon $state) {
                    return $state?->format('H:i');
                }),
            ExportColumn::make('frequency')
                ->enabledByDefault(false)
                ->enum(),
            ExportColumn::make('interval_type')
                ->enabledByDefault(false)
                ->enum(),
            ExportColumn::make('interval_value')
                ->enabledByDefault(false),
            ExportColumn::make('month')
                ->enabledByDefault(false)
                ->enum(),
            ExportColumn::make('day_of_month')
                ->enabledByDefault(false)
                ->enum(),
            ExportColumn::make('day_of_week')
                ->enabledByDefault(false)
                ->enum(),
            ExportColumn::make('end_type')
                ->enabledByDefault(false)
                ->enum(),
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
        $body = 'Your recurring invoice export has completed and ' . number_format($export->successful_rows) . ' ' . str('row')->plural($export->successful_rows) . ' exported.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' ' . number_format($failedRowsCount) . ' ' . str('row')->plural($failedRowsCount) . ' failed to export.';
        }

        return $body;
    }
}
