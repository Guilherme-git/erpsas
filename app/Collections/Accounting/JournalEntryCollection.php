<?php

namespace App\Collections\Accounting;

use App\Models\Accounting\JournalEntry;
use App\Utilities\Currency\CurrencyAccessor;
use App\ValueObjects\Money;
use Illuminate\Database\Eloquent\Collection;

class JournalEntryCollection extends Collection
{
    public function sumDebits(): Money
    {
        $total = $this->reduce(static function ($carry, JournalEntry $item) {
            if ($item->type->isDebit()) {
                $amountAsInteger = $item->amount;

                return bcadd($carry, $amountAsInteger, 0);
            }

            return $carry;
        }, 0);

        return new Money($total, CurrencyAccessor::getDefaultCurrency());
    }

    public function sumCredits(): Money
    {
        $total = $this->reduce(static function ($carry, JournalEntry $item) {
            if ($item->type->isCredit()) {
                $amountAsInteger = $item->amount;

                return bcadd($carry, $amountAsInteger, 0);
            }

            return $carry;
        }, 0);

        return new Money($total, CurrencyAccessor::getDefaultCurrency());
    }

    public function areBalanced(): bool
    {
        return $this->sumDebits()->getAmount() === $this->sumCredits()->getAmount();
    }
}
