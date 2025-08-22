<?php

namespace App\Filament\Exports\Accounting;

use App\Models\Accounting\Transaction;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

class TransactionExporter extends Exporter
{
    protected static ?string $model = Transaction::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('posted_at')
                ->date(),
            ExportColumn::make('description'),
            ExportColumn::make('amount')
                ->money(),
            ExportColumn::make('account.name')
                ->label('Category'),
            ExportColumn::make('bankAccount.account.name')
                ->label('Account'),
            ExportColumn::make('type')
                ->enum(),
            ExportColumn::make('payeeable.name')
                ->label('Payee'),
            ExportColumn::make('payment_method')
                ->enum(),
            ExportColumn::make('notes')
                ->enabledByDefault(false),
            ExportColumn::make('transactionable_type')
                ->label('Source type')
                ->formatStateUsing(static function ($state) {
                    return class_basename($state);
                })
                ->enabledByDefault(false),
            ExportColumn::make('payeeable_type')
                ->label('Payee type')
                ->formatStateUsing(static function ($state) {
                    return class_basename($state);
                })
                ->enabledByDefault(false),
            ExportColumn::make('is_payment')
                ->enabledByDefault(false),
            ExportColumn::make('reviewed')
                ->enabledByDefault(false),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your transaction export has completed and ' . number_format($export->successful_rows) . ' ' . str('row')->plural($export->successful_rows) . ' exported.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' ' . number_format($failedRowsCount) . ' ' . str('row')->plural($failedRowsCount) . ' failed to export.';
        }

        return $body;
    }
}
