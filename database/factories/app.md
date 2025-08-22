
# --- File --- ./Accounting/AccountSubtypeFactory.php

<?php

namespace Database\Factories\Accounting;

use App\Models\Accounting\AccountSubtype;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccountSubtype>
 */
class AccountSubtypeFactory extends Factory
{
    protected $model = AccountSubtype::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            //
        ];
    }
}

# --- File --- ./Accounting/BillFactory.php

<?php

namespace Database\Factories\Accounting;

use App\Enums\Accounting\AdjustmentComputation;
use App\Enums\Accounting\BillStatus;
use App\Enums\Accounting\DocumentDiscountMethod;
use App\Enums\Accounting\PaymentMethod;
use App\Models\Accounting\Bill;
use App\Models\Accounting\DocumentLineItem;
use App\Models\Banking\BankAccount;
use App\Models\Common\Vendor;
use App\Models\Company;
use App\Models\Setting\DocumentDefault;
use App\Utilities\RateCalculator;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Bill>
 */
class BillFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected $model = Bill::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $billDate = $this->faker->dateTimeBetween('-1 year', '-1 day');

        return [
            'company_id' => 1,
            'vendor_id' => function (array $attributes) {
                return Vendor::where('company_id', $attributes['company_id'])->inRandomOrder()->value('id')
                    ?? Vendor::factory()->state([
                        'company_id' => $attributes['company_id'],
                    ]);
            },
            'bill_number' => $this->faker->unique()->numerify('BILL-####'),
            'order_number' => $this->faker->unique()->numerify('PO-####'),
            'date' => $billDate,
            'due_date' => $this->faker->dateTimeInInterval($billDate, '+6 months'),
            'status' => BillStatus::Open,
            'discount_method' => $this->faker->randomElement(DocumentDiscountMethod::class),
            'discount_computation' => AdjustmentComputation::Percentage,
            'discount_rate' => function (array $attributes) {
                $discountMethod = DocumentDiscountMethod::parse($attributes['discount_method']);

                if ($discountMethod?->isPerDocument()) {
                    return $this->faker->numberBetween(50000, 200000); // 5% - 20%
                }

                return 0;
            },
            'currency_code' => function (array $attributes) {
                $vendor = Vendor::find($attributes['vendor_id']);

                return $vendor->currency_code ??
                    Company::find($attributes['company_id'])->default->currency_code ??
                    'USD';
            },
            'notes' => $this->faker->sentence,
            'created_by' => 1,
            'updated_by' => 1,
        ];
    }

    public function withLineItems(int $count = 3): static
    {
        return $this->afterCreating(function (Bill $bill) use ($count) {
            // Clear existing line items first
            $bill->lineItems()->delete();

            DocumentLineItem::factory()
                ->count($count)
                ->forBill($bill)
                ->create();

            $this->recalculateTotals($bill);
        });
    }

    public function initialized(): static
    {
        return $this->afterCreating(function (Bill $bill) {
            $this->performInitialization($bill);
        });
    }

    public function partial(int $maxPayments = 4): static
    {
        return $this->afterCreating(function (Bill $bill) use ($maxPayments) {
            $this->performInitialization($bill);
            $this->performPayments($bill, $maxPayments, BillStatus::Partial);
        });
    }

    public function paid(int $maxPayments = 4): static
    {
        return $this->afterCreating(function (Bill $bill) use ($maxPayments) {
            $this->performInitialization($bill);
            $this->performPayments($bill, $maxPayments, BillStatus::Paid);
        });
    }

    public function overdue(): static
    {
        return $this
            ->state([
                'due_date' => now()->subDays($this->faker->numberBetween(1, 30)),
            ])
            ->afterCreating(function (Bill $bill) {
                $this->performInitialization($bill);
            });
    }

    protected function performInitialization(Bill $bill): void
    {
        if ($bill->wasInitialized()) {
            return;
        }

        $postedAt = Carbon::parse($bill->date)
            ->addHours($this->faker->numberBetween(1, 24));

        if ($postedAt->isAfter(now())) {
            $postedAt = Carbon::parse($this->faker->dateTimeBetween($bill->date, now()));
        }

        $bill->createInitialTransaction($postedAt);
    }

    protected function performPayments(Bill $bill, int $maxPayments, BillStatus $billStatus): void
    {
        $bill->refresh();

        $amountDue = $bill->getRawOriginal('amount_due');

        $totalAmountDue = match ($billStatus) {
            BillStatus::Partial => (int) floor($amountDue * 0.5),
            default => $amountDue,
        };

        if ($totalAmountDue <= 0 || empty($totalAmountDue)) {
            return;
        }

        $paymentCount = $this->faker->numberBetween(1, $maxPayments);
        $paymentAmount = (int) floor($totalAmountDue / $paymentCount);
        $remainingAmount = $totalAmountDue;

        $initialPaymentDate = Carbon::parse($bill->initialTransaction->posted_at);
        $maxPaymentDate = now();

        $paymentDates = [];

        for ($i = 0; $i < $paymentCount; $i++) {
            $amount = $i === $paymentCount - 1 ? $remainingAmount : $paymentAmount;

            if ($amount <= 0) {
                break;
            }

            if ($i === 0) {
                $postedAt = $initialPaymentDate->copy()->addDays($this->faker->numberBetween(1, 15));
            } else {
                $postedAt = $paymentDates[$i - 1]->copy()->addDays($this->faker->numberBetween(1, 10));
            }

            if ($postedAt->isAfter($maxPaymentDate)) {
                $postedAt = Carbon::parse($this->faker->dateTimeBetween($initialPaymentDate, $maxPaymentDate));
            }

            $paymentDates[] = $postedAt;

            $data = [
                'posted_at' => $postedAt,
                'amount' => $amount,
                'payment_method' => $this->faker->randomElement(PaymentMethod::class),
                'bank_account_id' => BankAccount::where('company_id', $bill->company_id)->inRandomOrder()->value('id'),
                'notes' => $this->faker->sentence,
            ];

            $bill->recordPayment($data);
            $remainingAmount -= $amount;
        }

        if ($billStatus === BillStatus::Paid && ! empty($paymentDates)) {
            $latestPaymentDate = max($paymentDates);
            $bill->updateQuietly([
                'status' => $billStatus,
                'paid_at' => $latestPaymentDate,
            ]);
        }
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Bill $bill) {
            DocumentLineItem::factory()
                ->count(3)
                ->forBill($bill)
                ->create();

            $this->recalculateTotals($bill);

            $number = DocumentDefault::getBaseNumber() + $bill->id;

            $bill->updateQuietly([
                'bill_number' => "BILL-{$number}",
                'order_number' => "PO-{$number}",
            ]);

            if ($bill->wasInitialized() && $bill->shouldBeOverdue()) {
                $bill->updateQuietly([
                    'status' => BillStatus::Overdue,
                ]);
            }
        });
    }

    protected function recalculateTotals(Bill $bill): void
    {
        $bill->refresh();

        if (! $bill->hasLineItems()) {
            return;
        }

        $subtotalCents = $bill->lineItems()->sum('subtotal');
        $taxTotalCents = $bill->lineItems()->sum('tax_total');

        $discountTotalCents = 0;

        if ($bill->discount_method?->isPerLineItem()) {
            $discountTotalCents = $bill->lineItems()->sum('discount_total');
        } elseif ($bill->discount_method?->isPerDocument() && $bill->discount_rate) {
            if ($bill->discount_computation?->isPercentage()) {
                $scaledRate = RateCalculator::parseLocalizedRate($bill->discount_rate);
                $discountTotalCents = RateCalculator::calculatePercentage($subtotalCents, $scaledRate);
            } else {
                $discountTotalCents = $bill->getRawOriginal('discount_rate');
            }
        }

        $grandTotalCents = $subtotalCents + $taxTotalCents - $discountTotalCents;

        $bill->update([
            'subtotal' => $subtotalCents,
            'tax_total' => $taxTotalCents,
            'discount_total' => $discountTotalCents,
            'total' => $grandTotalCents,
        ]);
    }
}

# --- File --- ./Accounting/BudgetAllocationFactory.php

<?php

namespace Database\Factories\Accounting;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Accounting\BudgetAllocation>
 */
class BudgetAllocationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            //
        ];
    }
}

# --- File --- ./Accounting/TransactionFactory.php

<?php

namespace Database\Factories\Accounting;

use App\Enums\Accounting\AccountType;
use App\Enums\Accounting\TransactionType;
use App\Models\Accounting\Account;
use App\Models\Accounting\AccountSubtype;
use App\Models\Accounting\Transaction;
use App\Models\Banking\BankAccount;
use App\Models\Company;
use App\Models\Setting\CompanyDefault;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected $model = Transaction::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => 1,
            'bank_account_id' => 1,
            'account_id' => $this->faker->numberBetween(2, 30),
            'type' => $this->faker->randomElement([TransactionType::Deposit, TransactionType::Withdrawal]),
            'description' => $this->faker->sentence,
            'notes' => $this->faker->paragraph,
            'amount' => $this->faker->numberBetween(100, 5000),
            'reviewed' => $this->faker->boolean,
            'posted_at' => $this->faker->dateTimeBetween('-2 years'),
            'created_by' => 1,
            'updated_by' => 1,
        ];
    }

    public function forCompanyAndBankAccount(Company $company, BankAccount $bankAccount): static
    {
        return $this->state(function (array $attributes) use ($bankAccount, $company) {
            $type = $this->faker->randomElement([TransactionType::Deposit, TransactionType::Withdrawal]);

            $associatedAccountTypes = match ($type) {
                TransactionType::Deposit => [
                    AccountType::CurrentLiability,
                    AccountType::NonCurrentLiability,
                    AccountType::Equity,
                    AccountType::OperatingRevenue,
                    AccountType::NonOperatingRevenue,
                    AccountType::ContraExpense,
                ],
                TransactionType::Withdrawal => [
                    AccountType::OperatingExpense,
                    AccountType::NonOperatingExpense,
                    AccountType::CurrentLiability,
                    AccountType::NonCurrentLiability,
                    AccountType::Equity,
                    AccountType::ContraRevenue,
                ],
            };

            $accountIdForBankAccount = $bankAccount->account->id;

            $excludedSubtypes = AccountSubtype::where('company_id', $company->id)
                ->whereIn('name', ['Sales Taxes', 'Purchase Taxes', 'Sales Discounts', 'Purchase Discounts'])
                ->pluck('id');

            $account = Account::whereIn('type', $associatedAccountTypes)
                ->where('company_id', $company->id)
                ->whereNotIn('subtype_id', $excludedSubtypes)
                ->whereKeyNot($accountIdForBankAccount)
                ->inRandomOrder()
                ->first();

            if (! $account) {
                $account = Account::where('company_id', $company->id)
                    ->whereKeyNot($accountIdForBankAccount)
                    ->inRandomOrder()
                    ->firstOrFail();
            }

            return [
                'company_id' => $company->id,
                'bank_account_id' => $bankAccount->id,
                'account_id' => $account->id,
                'type' => $type,
            ];
        });
    }

    public function forDefaultBankAccount(): static
    {
        return $this->state(function (array $attributes) {
            $defaultBankAccount = CompanyDefault::first()->bankAccount;

            return [
                'bank_account_id' => $defaultBankAccount->id,
            ];
        });
    }

    public function forBankAccount(?BankAccount $bankAccount = null): static
    {
        return $this->state(function (array $attributes) use ($bankAccount) {
            $bankAccount = $bankAccount ?? BankAccount::factory()->create();

            return [
                'bank_account_id' => $bankAccount->id,
            ];
        });
    }

    public function forDestinationBankAccount(?Account $account = null): static
    {
        return $this->state(function (array $attributes) use ($account) {
            $destinationBankAccount = $account ?? Account::factory()->withBankAccount('Destination Bank Account')->create();

            return [
                'account_id' => $destinationBankAccount->id,
            ];
        });
    }

    public function forUncategorizedRevenue(): static
    {
        return $this->state(function (array $attributes) {
            $account = Account::where('type', AccountType::UncategorizedRevenue)->firstOrFail();

            return [
                'account_id' => $account->id,
            ];
        });
    }

    public function forUncategorizedExpense(): static
    {
        return $this->state(function (array $attributes) {
            $account = Account::where('type', AccountType::UncategorizedExpense)->firstOrFail();

            return [
                'account_id' => $account->id,
            ];
        });
    }

    public function forAccount(Account $account): static
    {
        return $this->state([
            'account_id' => $account->id,
        ]);
    }

    public function forType(TransactionType $type, int $amount): static
    {
        return $this->state(compact('type', 'amount'));
    }

    public function asDeposit(int $amount): static
    {
        return $this->state(function () use ($amount) {
            return [
                'type' => TransactionType::Deposit,
                'amount' => $amount,
            ];
        });
    }

    public function asWithdrawal(int $amount): static
    {
        return $this->state(function () use ($amount) {
            return [
                'type' => TransactionType::Withdrawal,
                'amount' => $amount,
            ];
        });
    }

    public function asJournal(int $amount): static
    {
        return $this->state(function () use ($amount) {
            return [
                'type' => TransactionType::Journal,
                'amount' => $amount,
            ];
        });
    }

    public function asTransfer(int $amount): static
    {
        return $this->state(function () use ($amount) {
            return [
                'type' => TransactionType::Transfer,
                'amount' => $amount,
            ];
        });
    }
}

# --- File --- ./Accounting/RecurringInvoiceFactory.php

<?php

namespace Database\Factories\Accounting;

use App\Enums\Accounting\AdjustmentComputation;
use App\Enums\Accounting\DayOfMonth;
use App\Enums\Accounting\DayOfWeek;
use App\Enums\Accounting\DocumentDiscountMethod;
use App\Enums\Accounting\EndType;
use App\Enums\Accounting\Frequency;
use App\Enums\Accounting\IntervalType;
use App\Enums\Accounting\Month;
use App\Enums\Accounting\RecurringInvoiceStatus;
use App\Enums\Setting\PaymentTerms;
use App\Models\Accounting\DocumentLineItem;
use App\Models\Accounting\RecurringInvoice;
use App\Models\Common\Client;
use App\Models\Company;
use App\Utilities\RateCalculator;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<RecurringInvoice>
 */
class RecurringInvoiceFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected $model = RecurringInvoice::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => 1,
            'client_id' => function (array $attributes) {
                return Client::where('company_id', $attributes['company_id'])->inRandomOrder()->value('id')
                    ?? Client::factory()->state([
                        'company_id' => $attributes['company_id'],
                    ]);
            },
            'header' => 'Invoice',
            'subheader' => 'Invoice',
            'order_number' => $this->faker->unique()->numerify('ORD-####'),
            'payment_terms' => PaymentTerms::Net30,
            'status' => RecurringInvoiceStatus::Draft,
            'discount_method' => $this->faker->randomElement(DocumentDiscountMethod::class),
            'discount_computation' => AdjustmentComputation::Percentage,
            'discount_rate' => function (array $attributes) {
                $discountMethod = DocumentDiscountMethod::parse($attributes['discount_method']);

                if ($discountMethod?->isPerDocument()) {
                    return $this->faker->numberBetween(50000, 200000); // 5% - 20%
                }

                return 0;
            },
            'currency_code' => function (array $attributes) {
                $client = Client::find($attributes['client_id']);

                return $client->currency_code ??
                    Company::find($attributes['company_id'])->default->currency_code ??
                    'USD';
            },
            'terms' => $this->faker->sentence,
            'footer' => $this->faker->sentence,
            'created_by' => 1,
            'updated_by' => 1,
        ];
    }

    public function withLineItems(int $count = 3): static
    {
        return $this->afterCreating(function (RecurringInvoice $recurringInvoice) use ($count) {
            // Clear existing line items first
            $recurringInvoice->lineItems()->delete();

            DocumentLineItem::factory()
                ->count($count)
                ->forInvoice($recurringInvoice)
                ->create();

            $this->recalculateTotals($recurringInvoice);
        });
    }

    public function configure(): static
    {
        return $this->afterCreating(function (RecurringInvoice $recurringInvoice) {
            DocumentLineItem::factory()
                ->count(3)
                ->forInvoice($recurringInvoice)
                ->create();

            $this->recalculateTotals($recurringInvoice);
        });
    }

    public function withSchedule(
        ?Frequency $frequency = null,
        ?Carbon $startDate = null,
        ?EndType $endType = null
    ): static {
        return $this->afterCreating(function (RecurringInvoice $recurringInvoice) use ($frequency, $startDate, $endType) {
            $this->performScheduleSetup($recurringInvoice, $frequency, $startDate, $endType);
        });
    }

    public function withDailySchedule(Carbon $startDate, EndType $endType): static
    {
        return $this->afterCreating(function (RecurringInvoice $recurringInvoice) use ($startDate, $endType) {
            $recurringInvoice->updateQuietly([
                'frequency' => Frequency::Daily,
                'start_date' => $startDate,
                'end_type' => $endType,
            ]);
        });
    }

    public function withWeeklySchedule(Carbon $startDate, EndType $endType): static
    {
        return $this->afterCreating(function (RecurringInvoice $recurringInvoice) use ($startDate, $endType) {
            $recurringInvoice->updateQuietly([
                'frequency' => Frequency::Weekly,
                'day_of_week' => DayOfWeek::from($startDate->dayOfWeek),
                'start_date' => $startDate,
                'end_type' => $endType,
            ]);
        });
    }

    public function withMonthlySchedule(Carbon $startDate, EndType $endType): static
    {
        return $this->afterCreating(function (RecurringInvoice $recurringInvoice) use ($startDate, $endType) {
            $recurringInvoice->updateQuietly([
                'frequency' => Frequency::Monthly,
                'day_of_month' => DayOfMonth::from($startDate->day),
                'start_date' => $startDate,
                'end_type' => $endType,
            ]);
        });
    }

    public function withYearlySchedule(Carbon $startDate, EndType $endType): static
    {
        return $this->afterCreating(function (RecurringInvoice $recurringInvoice) use ($startDate, $endType) {
            $recurringInvoice->updateQuietly([
                'frequency' => Frequency::Yearly,
                'month' => Month::from($startDate->month),
                'day_of_month' => DayOfMonth::from($startDate->day),
                'start_date' => $startDate,
                'end_type' => $endType,
            ]);
        });
    }

    public function withCustomSchedule(
        Carbon $startDate,
        EndType $endType,
        ?IntervalType $intervalType = null,
        ?int $intervalValue = null
    ): static {
        return $this->afterCreating(function (RecurringInvoice $recurringInvoice) use ($intervalType, $intervalValue, $startDate, $endType) {
            $intervalType ??= $this->faker->randomElement(IntervalType::class);
            $intervalValue ??= match ($intervalType) {
                IntervalType::Day => $this->faker->numberBetween(1, 7),
                IntervalType::Week => $this->faker->numberBetween(1, 4),
                IntervalType::Month => $this->faker->numberBetween(1, 3),
                IntervalType::Year => 1,
            };

            $state = [
                'frequency' => Frequency::Custom,
                'interval_type' => $intervalType,
                'interval_value' => $intervalValue,
                'start_date' => $startDate,
                'end_type' => $endType,
            ];

            // Add interval-specific attributes
            switch ($intervalType) {
                case IntervalType::Day:
                    // No additional attributes needed
                    break;

                case IntervalType::Week:
                    $state['day_of_week'] = DayOfWeek::from($startDate->dayOfWeek);

                    break;

                case IntervalType::Month:
                    $state['day_of_month'] = DayOfMonth::from($startDate->day);

                    break;

                case IntervalType::Year:
                    $state['month'] = Month::from($startDate->month);
                    $state['day_of_month'] = DayOfMonth::from($startDate->day);

                    break;
            }

            $recurringInvoice->updateQuietly($state);
        });
    }

    public function endAfter(int $occurrences = 12): static
    {
        return $this->state([
            'end_type' => EndType::After,
            'max_occurrences' => $occurrences,
        ]);
    }

    public function endOn(?Carbon $endDate = null): static
    {
        $endDate ??= now()->addMonths($this->faker->numberBetween(1, 12));

        return $this->state([
            'end_type' => EndType::On,
            'end_date' => $endDate,
        ]);
    }

    public function autoSend(string $sendTime = '09:00'): static
    {
        return $this->state([
            'auto_send' => true,
            'send_time' => $sendTime,
        ]);
    }

    public function approved(): static
    {
        return $this->afterCreating(function (RecurringInvoice $recurringInvoice) {
            $this->performApproval($recurringInvoice);
        });
    }

    public function active(): static
    {
        return $this->afterCreating(function (RecurringInvoice $recurringInvoice) {
            if (! $recurringInvoice->hasSchedule()) {
                $this->performScheduleSetup($recurringInvoice);
                $recurringInvoice->refresh();
            }

            $this->performApproval($recurringInvoice);
        });
    }

    public function ended(): static
    {
        return $this->afterCreating(function (RecurringInvoice $recurringInvoice) {
            if (! $recurringInvoice->canBeEnded()) {
                if (! $recurringInvoice->hasSchedule()) {
                    $this->performScheduleSetup($recurringInvoice);
                    $recurringInvoice->refresh();
                }

                $this->performApproval($recurringInvoice);
            }

            $endedAt = $recurringInvoice->last_date
                ? $recurringInvoice->last_date->copy()->addDays($this->faker->numberBetween(1, 7))
                : now()->subDays($this->faker->numberBetween(1, 30));

            $recurringInvoice->updateQuietly([
                'ended_at' => $endedAt,
                'status' => RecurringInvoiceStatus::Ended,
            ]);
        });
    }

    protected function performScheduleSetup(
        RecurringInvoice $recurringInvoice,
        ?Frequency $frequency = null,
        ?Carbon $startDate = null,
        ?EndType $endType = null
    ): void {
        $frequency ??= $this->faker->randomElement(Frequency::class);
        $endType ??= EndType::Never;

        // Adjust the start date range based on frequency
        $startDate = match ($frequency) {
            Frequency::Daily => Carbon::parse($this->faker->dateTimeBetween('-30 days')), // At most 30 days back
            default => $startDate ?? Carbon::parse($this->faker->dateTimeBetween('-1 year')),
        };

        match ($frequency) {
            Frequency::Daily => $this->performDailySchedule($recurringInvoice, $startDate, $endType),
            Frequency::Weekly => $this->performWeeklySchedule($recurringInvoice, $startDate, $endType),
            Frequency::Monthly => $this->performMonthlySchedule($recurringInvoice, $startDate, $endType),
            Frequency::Yearly => $this->performYearlySchedule($recurringInvoice, $startDate, $endType),
            Frequency::Custom => $this->performCustomSchedule($recurringInvoice, $startDate, $endType),
        };
    }

    protected function performDailySchedule(RecurringInvoice $recurringInvoice, Carbon $startDate, EndType $endType): void
    {
        $recurringInvoice->updateQuietly([
            'frequency' => Frequency::Daily,
            'start_date' => $startDate,
            'end_type' => $endType,
        ]);
    }

    protected function performWeeklySchedule(RecurringInvoice $recurringInvoice, Carbon $startDate, EndType $endType): void
    {
        $recurringInvoice->updateQuietly([
            'frequency' => Frequency::Weekly,
            'day_of_week' => DayOfWeek::from($startDate->dayOfWeek),
            'start_date' => $startDate,
            'end_type' => $endType,
        ]);
    }

    protected function performMonthlySchedule(RecurringInvoice $recurringInvoice, Carbon $startDate, EndType $endType): void
    {
        $recurringInvoice->updateQuietly([
            'frequency' => Frequency::Monthly,
            'day_of_month' => DayOfMonth::from($startDate->day),
            'start_date' => $startDate,
            'end_type' => $endType,
        ]);
    }

    protected function performYearlySchedule(RecurringInvoice $recurringInvoice, Carbon $startDate, EndType $endType): void
    {
        $recurringInvoice->updateQuietly([
            'frequency' => Frequency::Yearly,
            'month' => Month::from($startDate->month),
            'day_of_month' => DayOfMonth::from($startDate->day),
            'start_date' => $startDate,
            'end_type' => $endType,
        ]);
    }

    protected function performCustomSchedule(
        RecurringInvoice $recurringInvoice,
        Carbon $startDate,
        EndType $endType,
        ?IntervalType $intervalType = null,
        ?int $intervalValue = null
    ): void {
        $intervalType ??= $this->faker->randomElement(IntervalType::class);
        $intervalValue ??= match ($intervalType) {
            IntervalType::Day => $this->faker->numberBetween(1, 7),
            IntervalType::Week => $this->faker->numberBetween(1, 4),
            IntervalType::Month => $this->faker->numberBetween(1, 3),
            IntervalType::Year => 1,
        };

        $state = [
            'frequency' => Frequency::Custom,
            'interval_type' => $intervalType,
            'interval_value' => $intervalValue,
            'start_date' => $startDate,
            'end_type' => $endType,
        ];

        // Add interval-specific attributes
        switch ($intervalType) {
            case IntervalType::Day:
                // No additional attributes needed
                break;

            case IntervalType::Week:
                $state['day_of_week'] = DayOfWeek::from($startDate->dayOfWeek);

                break;

            case IntervalType::Month:
                $state['day_of_month'] = DayOfMonth::from($startDate->day);

                break;

            case IntervalType::Year:
                $state['month'] = Month::from($startDate->month);
                $state['day_of_month'] = DayOfMonth::from($startDate->day);

                break;
        }

        $recurringInvoice->updateQuietly($state);
    }

    protected function performApproval(RecurringInvoice $recurringInvoice): void
    {
        if (! $recurringInvoice->hasSchedule()) {
            $this->performScheduleSetup($recurringInvoice);
            $recurringInvoice->refresh();
        }

        $approvedAt = $recurringInvoice->start_date
            ? $recurringInvoice->start_date->copy()->subDays($this->faker->numberBetween(1, 7))
            : now()->subDays($this->faker->numberBetween(1, 30));

        $recurringInvoice->approveDraft($approvedAt);
    }

    protected function recalculateTotals(RecurringInvoice $recurringInvoice): void
    {
        $recurringInvoice->refresh();

        if (! $recurringInvoice->hasLineItems()) {
            return;
        }

        $subtotalCents = $recurringInvoice->lineItems()->sum('subtotal');
        $taxTotalCents = $recurringInvoice->lineItems()->sum('tax_total');

        $discountTotalCents = 0;

        if ($recurringInvoice->discount_method?->isPerLineItem()) {
            $discountTotalCents = $recurringInvoice->lineItems()->sum('discount_total');
        } elseif ($recurringInvoice->discount_method?->isPerDocument() && $recurringInvoice->discount_rate) {
            if ($recurringInvoice->discount_computation?->isPercentage()) {
                $scaledRate = RateCalculator::parseLocalizedRate($recurringInvoice->discount_rate);
                $discountTotalCents = RateCalculator::calculatePercentage($subtotalCents, $scaledRate);
            } else {
                $discountTotalCents = $recurringInvoice->getRawOriginal('discount_rate');
            }
        }

        $grandTotalCents = $subtotalCents + $taxTotalCents - $discountTotalCents;

        $recurringInvoice->update([
            'subtotal' => $subtotalCents,
            'tax_total' => $taxTotalCents,
            'discount_total' => $discountTotalCents,
            'total' => $grandTotalCents,
        ]);
    }
}

# --- File --- ./Accounting/BudgetItemFactory.php

<?php

namespace Database\Factories\Accounting;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Accounting\BudgetItem>
 */
class BudgetItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            //
        ];
    }
}

# --- File --- ./Accounting/BudgetFactory.php

<?php

namespace Database\Factories\Accounting;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Accounting\Budget>
 */
class BudgetFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            //
        ];
    }
}

# --- File --- ./Accounting/AdjustmentFactory.php

<?php

namespace Database\Factories\Accounting;

use App\Enums\Accounting\AdjustmentCategory;
use App\Enums\Accounting\AdjustmentComputation;
use App\Enums\Accounting\AdjustmentScope;
use App\Enums\Accounting\AdjustmentType;
use App\Models\Accounting\Account;
use App\Models\Accounting\Adjustment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Adjustment>
 */
class AdjustmentFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected $model = Adjustment::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startDate = $this->faker->dateTimeBetween('now', '+1 year');
        $endDate = $this->faker->dateTimeBetween($startDate, Carbon::parse($startDate)->addYear());

        /** @var AdjustmentComputation $computation */
        $computation = $this->faker->randomElement(AdjustmentComputation::class);

        $rate = $computation->isFixed()
            ? $this->faker->numberBetween(5, 100) * 100 // $5 - $100 for fixed amounts
            : $this->faker->numberBetween(3, 25) * 10000; // 3% - 25% for percentages

        return [
            'rate' => $rate,
            'computation' => $computation,
            'category' => $this->faker->randomElement(AdjustmentCategory::class),
            'type' => $this->faker->randomElement(AdjustmentType::class),
            'scope' => $this->faker->randomElement(AdjustmentScope::class),
            'start_date' => $startDate,
            'end_date' => $endDate,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Adjustment $adjustment) {
            if ($adjustment->account_id === null) {
                $account = Account::factory()->create();
                $adjustment->account()->associate($account);
            }
        });
    }

    /**
     * Define a sales tax adjustment.
     */
    public function salesTax(?string $name = null, ?string $description = null): self
    {
        $name = $name ?? 'Sales Tax';
        $account = Account::factory()->forSalesTax($name, $description)->create();

        return $this->state([
            'category' => AdjustmentCategory::Tax,
            'type' => AdjustmentType::Sales,
            'account_id' => $account->id,
        ]);
    }

    /**
     * Define a purchase tax adjustment.
     */
    public function purchaseTax(?string $name = null, ?string $description = null): self
    {
        $name = $name ?? 'Purchase Tax';
        $account = Account::factory()->forPurchaseTax($name, $description)->create();

        return $this->state([
            'category' => AdjustmentCategory::Tax,
            'type' => AdjustmentType::Purchase,
            'account_id' => $account->id,
        ]);
    }

    /**
     * Define a sales discount adjustment.
     */
    public function salesDiscount(?string $name = null, ?string $description = null): self
    {
        $name = $name ?? 'Sales Discount';
        $account = Account::factory()->forSalesDiscount($name, $description)->create();

        return $this->state([
            'category' => AdjustmentCategory::Discount,
            'type' => AdjustmentType::Sales,
            'account_id' => $account->id,
        ]);
    }

    /**
     * Define a purchase discount adjustment.
     */
    public function purchaseDiscount(?string $name = null, ?string $description = null): self
    {
        $name = $name ?? 'Purchase Discount';
        $account = Account::factory()->forPurchaseDiscount($name, $description)->create();

        return $this->state([
            'category' => AdjustmentCategory::Discount,
            'type' => AdjustmentType::Purchase,
            'account_id' => $account->id,
        ]);
    }
}

# --- File --- ./Accounting/JournalEntryFactory.php

<?php

namespace Database\Factories\Accounting;

use App\Models\Accounting\JournalEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JournalEntry>
 */
class JournalEntryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            //
        ];
    }
}

# --- File --- ./Accounting/EstimateFactory.php

<?php

namespace Database\Factories\Accounting;

use App\Enums\Accounting\AdjustmentComputation;
use App\Enums\Accounting\DocumentDiscountMethod;
use App\Enums\Accounting\EstimateStatus;
use App\Models\Accounting\DocumentLineItem;
use App\Models\Accounting\Estimate;
use App\Models\Common\Client;
use App\Models\Company;
use App\Models\Setting\DocumentDefault;
use App\Utilities\RateCalculator;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Estimate>
 */
class EstimateFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected $model = Estimate::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $estimateDate = $this->faker->dateTimeBetween('-2 months', '-1 day');

        return [
            'company_id' => 1,
            'client_id' => function (array $attributes) {
                return Client::where('company_id', $attributes['company_id'])->inRandomOrder()->value('id')
                    ?? Client::factory()->state([
                        'company_id' => $attributes['company_id'],
                    ]);
            },
            'header' => 'Estimate',
            'subheader' => 'Estimate',
            'estimate_number' => $this->faker->unique()->numerify('EST-####'),
            'reference_number' => $this->faker->unique()->numerify('REF-####'),
            'date' => $estimateDate,
            'expiration_date' => $this->faker->dateTimeInInterval($estimateDate, '+3 months'),
            'status' => EstimateStatus::Draft,
            'discount_method' => $this->faker->randomElement(DocumentDiscountMethod::class),
            'discount_computation' => AdjustmentComputation::Percentage,
            'discount_rate' => function (array $attributes) {
                $discountMethod = DocumentDiscountMethod::parse($attributes['discount_method']);

                if ($discountMethod?->isPerDocument()) {
                    return $this->faker->numberBetween(50000, 200000); // 5% - 20%
                }

                return 0;
            },
            'currency_code' => function (array $attributes) {
                $client = Client::find($attributes['client_id']);

                return $client->currency_code ??
                    Company::find($attributes['company_id'])->default->currency_code ??
                    'USD';
            },
            'terms' => $this->faker->sentence,
            'footer' => $this->faker->sentence,
            'created_by' => 1,
            'updated_by' => 1,
        ];
    }

    public function withLineItems(int $count = 3): static
    {
        return $this->afterCreating(function (Estimate $estimate) use ($count) {
            // Clear existing line items first
            $estimate->lineItems()->delete();

            DocumentLineItem::factory()
                ->count($count)
                ->forEstimate($estimate)
                ->create();

            $this->recalculateTotals($estimate);
        });
    }

    public function approved(): static
    {
        return $this->afterCreating(function (Estimate $estimate) {
            $this->performApproval($estimate);
        });
    }

    public function accepted(): static
    {
        return $this->afterCreating(function (Estimate $estimate) {
            $this->performSent($estimate);

            $acceptedAt = Carbon::parse($estimate->last_sent_at)
                ->addDays($this->faker->numberBetween(1, 7));

            if ($acceptedAt->isAfter(now())) {
                $acceptedAt = Carbon::parse($this->faker->dateTimeBetween($estimate->last_sent_at, now()));
            }

            $estimate->markAsAccepted($acceptedAt);
        });
    }

    public function converted(): static
    {
        return $this->afterCreating(function (Estimate $estimate) {
            if (! $estimate->wasAccepted()) {
                $this->performSent($estimate);

                $acceptedAt = Carbon::parse($estimate->last_sent_at)
                    ->addDays($this->faker->numberBetween(1, 7));

                if ($acceptedAt->isAfter(now())) {
                    $acceptedAt = Carbon::parse($this->faker->dateTimeBetween($estimate->last_sent_at, now()));
                }

                $estimate->markAsAccepted($acceptedAt);
            }

            $convertedAt = Carbon::parse($estimate->accepted_at)
                ->addDays($this->faker->numberBetween(1, 7));

            if ($convertedAt->isAfter(now())) {
                $convertedAt = Carbon::parse($this->faker->dateTimeBetween($estimate->accepted_at, now()));
            }

            $estimate->convertToInvoice($convertedAt);
        });
    }

    public function declined(): static
    {
        return $this->afterCreating(function (Estimate $estimate) {
            $this->performSent($estimate);

            $declinedAt = Carbon::parse($estimate->last_sent_at)
                ->addDays($this->faker->numberBetween(1, 7));

            if ($declinedAt->isAfter(now())) {
                $declinedAt = Carbon::parse($this->faker->dateTimeBetween($estimate->last_sent_at, now()));
            }

            $estimate->markAsDeclined($declinedAt);
        });
    }

    public function sent(): static
    {
        return $this->afterCreating(function (Estimate $estimate) {
            $this->performSent($estimate);
        });
    }

    public function viewed(): static
    {
        return $this->afterCreating(function (Estimate $estimate) {
            $this->performSent($estimate);

            $viewedAt = Carbon::parse($estimate->last_sent_at)
                ->addHours($this->faker->numberBetween(1, 24));

            if ($viewedAt->isAfter(now())) {
                $viewedAt = Carbon::parse($this->faker->dateTimeBetween($estimate->last_sent_at, now()));
            }

            $estimate->markAsViewed($viewedAt);
        });
    }

    public function expired(): static
    {
        return $this
            ->state([
                'expiration_date' => now()->subDays($this->faker->numberBetween(1, 30)),
            ])
            ->afterCreating(function (Estimate $estimate) {
                $this->performApproval($estimate);
            });
    }

    protected function performApproval(Estimate $estimate): void
    {
        if (! $estimate->canBeApproved()) {
            throw new \InvalidArgumentException('Estimate cannot be approved. Current status: ' . $estimate->status->value);
        }

        $approvedAt = Carbon::parse($estimate->date)
            ->addHours($this->faker->numberBetween(1, 24));

        if ($approvedAt->isAfter(now())) {
            $approvedAt = Carbon::parse($this->faker->dateTimeBetween($estimate->date, now()));
        }

        $estimate->approveDraft($approvedAt);
    }

    protected function performSent(Estimate $estimate): void
    {
        if (! $estimate->wasApproved()) {
            $this->performApproval($estimate);
        }

        $sentAt = Carbon::parse($estimate->approved_at)
            ->addHours($this->faker->numberBetween(1, 24));

        if ($sentAt->isAfter(now())) {
            $sentAt = Carbon::parse($this->faker->dateTimeBetween($estimate->approved_at, now()));
        }

        $estimate->markAsSent($sentAt);
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Estimate $estimate) {
            DocumentLineItem::factory()
                ->count(3)
                ->forEstimate($estimate)
                ->create();

            $this->recalculateTotals($estimate);

            $number = DocumentDefault::getBaseNumber() + $estimate->id;

            $estimate->updateQuietly([
                'estimate_number' => "EST-{$number}",
                'reference_number' => "REF-{$number}",
            ]);

            if ($estimate->wasApproved() && $estimate->shouldBeExpired()) {
                $estimate->updateQuietly([
                    'status' => EstimateStatus::Expired,
                ]);
            }
        });
    }

    protected function recalculateTotals(Estimate $estimate): void
    {
        $estimate->refresh();

        if (! $estimate->hasLineItems()) {
            return;
        }

        $subtotalCents = $estimate->lineItems()->sum('subtotal');
        $taxTotalCents = $estimate->lineItems()->sum('tax_total');

        $discountTotalCents = 0;

        if ($estimate->discount_method?->isPerLineItem()) {
            $discountTotalCents = $estimate->lineItems()->sum('discount_total');
        } elseif ($estimate->discount_method?->isPerDocument() && $estimate->discount_rate) {
            if ($estimate->discount_computation?->isPercentage()) {
                $scaledRate = RateCalculator::parseLocalizedRate($estimate->discount_rate);
                $discountTotalCents = RateCalculator::calculatePercentage($subtotalCents, $scaledRate);
            } else {
                $discountTotalCents = $estimate->getRawOriginal('discount_rate');
            }
        }

        $grandTotalCents = $subtotalCents + $taxTotalCents - $discountTotalCents;

        $estimate->update([
            'subtotal' => $subtotalCents,
            'tax_total' => $taxTotalCents,
            'discount_total' => $discountTotalCents,
            'total' => $grandTotalCents,
        ]);
    }
}

# --- File --- ./Accounting/AccountFactory.php

<?php

namespace Database\Factories\Accounting;

use App\Models\Accounting\Account;
use App\Models\Accounting\AccountSubtype;
use App\Models\Banking\BankAccount;
use App\Models\Setting\Currency;
use App\Utilities\Accounting\AccountCode;
use App\Utilities\Currency\CurrencyAccessor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected $model = Account::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => 1,
            'subtype_id' => 1,
            'name' => $this->faker->unique()->word,
            'currency_code' => CurrencyAccessor::getDefaultCurrency() ?? 'USD',
            'description' => $this->faker->sentence,
            'archived' => false,
            'default' => false,
        ];
    }

    public function withBankAccount(string $name): static
    {
        return $this->afterCreating(function (Account $account) use ($name) {
            $accountSubtype = AccountSubtype::where('name', 'Cash and Cash Equivalents')->first();

            // Create and associate a BankAccount with the Account
            $bankAccount = BankAccount::factory()->create([
                'account_id' => $account->id, // Associate with Account
            ]);

            // Update the Account with the subtype and name
            $account->update([
                'subtype_id' => $accountSubtype->id,
                'name' => $name,
            ]);
        });
    }

    public function withForeignBankAccount(string $name, string $currencyCode, float $rate): static
    {
        return $this->afterCreating(function (Account $account) use ($currencyCode, $rate, $name) {
            $accountSubtype = AccountSubtype::where('name', 'Cash and Cash Equivalents')->first();

            // Create the Currency and BankAccount
            $currency = Currency::factory()->forCurrency($currencyCode, $rate)->create();
            $bankAccount = BankAccount::factory()->create([
                'account_id' => $account->id, // Associate with Account
            ]);

            // Update the Account with the subtype, name, and currency code
            $account->update([
                'subtype_id' => $accountSubtype->id,
                'name' => $name,
                'currency_code' => $currency->code,
            ]);
        });
    }

    public function forSalesTax(?string $name = null, ?string $description = null): static
    {
        $accountSubtype = AccountSubtype::where('name', 'Sales Taxes')->first();

        return $this->state([
            'name' => $name,
            'description' => $description,
            'category' => $accountSubtype->category,
            'type' => $accountSubtype->type,
            'subtype_id' => $accountSubtype->id,
            'code' => AccountCode::generate($accountSubtype),
        ]);
    }

    public function forPurchaseTax(?string $name = null, ?string $description = null): static
    {
        $accountSubtype = AccountSubtype::where('name', 'Input Tax Recoverable')->first();

        return $this->state([
            'name' => $name,
            'description' => $description,
            'category' => $accountSubtype->category,
            'type' => $accountSubtype->type,
            'subtype_id' => $accountSubtype->id,
            'code' => AccountCode::generate($accountSubtype),
        ]);
    }

    public function forSalesDiscount(?string $name = null, ?string $description = null): static
    {
        $accountSubtype = AccountSubtype::where('name', 'Sales Discounts')->first();

        return $this->state([
            'name' => $name,
            'description' => $description,
            'category' => $accountSubtype->category,
            'type' => $accountSubtype->type,
            'subtype_id' => $accountSubtype->id,
            'code' => AccountCode::generate($accountSubtype),
        ]);
    }

    public function forPurchaseDiscount(?string $name = null, ?string $description = null): static
    {
        $accountSubtype = AccountSubtype::where('name', 'Purchase Discounts')->first();

        return $this->state([
            'name' => $name,
            'description' => $description,
            'category' => $accountSubtype->category,
            'type' => $accountSubtype->type,
            'subtype_id' => $accountSubtype->id,
            'code' => AccountCode::generate($accountSubtype),
        ]);
    }
}

# --- File --- ./Accounting/InvoiceFactory.php

<?php

namespace Database\Factories\Accounting;

use App\Enums\Accounting\AdjustmentComputation;
use App\Enums\Accounting\DocumentDiscountMethod;
use App\Enums\Accounting\InvoiceStatus;
use App\Enums\Accounting\PaymentMethod;
use App\Models\Accounting\DocumentLineItem;
use App\Models\Accounting\Invoice;
use App\Models\Banking\BankAccount;
use App\Models\Common\Client;
use App\Models\Company;
use App\Models\Setting\DocumentDefault;
use App\Utilities\RateCalculator;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Random\RandomException;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected $model = Invoice::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $invoiceDate = $this->faker->dateTimeBetween('-2 months', '-1 day');

        return [
            'company_id' => 1,
            'client_id' => function (array $attributes) {
                return Client::where('company_id', $attributes['company_id'])->inRandomOrder()->value('id')
                    ?? Client::factory()->state([
                        'company_id' => $attributes['company_id'],
                    ]);
            },
            'header' => 'Invoice',
            'subheader' => 'Invoice',
            'invoice_number' => $this->faker->unique()->numerify('INV-####'),
            'order_number' => $this->faker->unique()->numerify('ORD-####'),
            'date' => $invoiceDate,
            'due_date' => $this->faker->dateTimeInInterval($invoiceDate, '+3 months'),
            'status' => InvoiceStatus::Draft,
            'discount_method' => $this->faker->randomElement(DocumentDiscountMethod::class),
            'discount_computation' => AdjustmentComputation::Percentage,
            'discount_rate' => function (array $attributes) {
                $discountMethod = DocumentDiscountMethod::parse($attributes['discount_method']);

                if ($discountMethod?->isPerDocument()) {
                    return $this->faker->numberBetween(50000, 200000); // 5% - 20%
                }

                return 0;
            },
            'currency_code' => function (array $attributes) {
                $client = Client::find($attributes['client_id']);

                return $client->currency_code ??
                    Company::find($attributes['company_id'])->default->currency_code ??
                    'USD';
            },
            'terms' => $this->faker->sentence,
            'footer' => $this->faker->sentence,
            'created_by' => 1,
            'updated_by' => 1,
        ];
    }

    public function withLineItems(int $count = 3): static
    {
        return $this->afterCreating(function (Invoice $invoice) use ($count) {
            $invoice->lineItems()->delete();

            DocumentLineItem::factory()
                ->count($count)
                ->forInvoice($invoice)
                ->create();

            $this->recalculateTotals($invoice);
        });
    }

    public function approved(): static
    {
        return $this->afterCreating(function (Invoice $invoice) {
            $this->performApproval($invoice);
        });
    }

    public function sent(): static
    {
        return $this->afterCreating(function (Invoice $invoice) {
            $this->performSent($invoice);
        });
    }

    public function partial(int $maxPayments = 4): static
    {
        return $this->afterCreating(function (Invoice $invoice) use ($maxPayments) {
            $this->performSent($invoice);
            $this->performPayments($invoice, $maxPayments, InvoiceStatus::Partial);
        });
    }

    public function paid(int $maxPayments = 4): static
    {
        return $this->afterCreating(function (Invoice $invoice) use ($maxPayments) {
            $this->performSent($invoice);
            $this->performPayments($invoice, $maxPayments, InvoiceStatus::Paid);
        });
    }

    public function overpaid(int $maxPayments = 4): static
    {
        return $this->afterCreating(function (Invoice $invoice) use ($maxPayments) {
            $this->performSent($invoice);
            $this->performPayments($invoice, $maxPayments, InvoiceStatus::Overpaid);
        });
    }

    public function overdue(): static
    {
        return $this
            ->state([
                'due_date' => now()->subDays($this->faker->numberBetween(1, 30)),
            ])
            ->afterCreating(function (Invoice $invoice) {
                $this->performApproval($invoice);
            });
    }

    protected function performApproval(Invoice $invoice): void
    {
        if (! $invoice->canBeApproved()) {
            throw new \InvalidArgumentException('Invoice cannot be approved. Current status: ' . $invoice->status->value);
        }

        $approvedAt = Carbon::parse($invoice->date)
            ->addHours($this->faker->numberBetween(1, 24));

        if ($approvedAt->isAfter(now())) {
            $approvedAt = Carbon::parse($this->faker->dateTimeBetween($invoice->date, now()));
        }

        $invoice->approveDraft($approvedAt);
    }

    protected function performSent(Invoice $invoice): void
    {
        if (! $invoice->wasApproved()) {
            $this->performApproval($invoice);
        }

        $sentAt = Carbon::parse($invoice->approved_at)
            ->addHours($this->faker->numberBetween(1, 24));

        if ($sentAt->isAfter(now())) {
            $sentAt = Carbon::parse($this->faker->dateTimeBetween($invoice->approved_at, now()));
        }

        $invoice->markAsSent($sentAt);
    }

    /**
     * @throws RandomException
     */
    protected function performPayments(Invoice $invoice, int $maxPayments, InvoiceStatus $invoiceStatus): void
    {
        $invoice->refresh();

        $amountDue = $invoice->amount_due;

        $totalAmountDue = match ($invoiceStatus) {
            InvoiceStatus::Overpaid => $amountDue + random_int(1000, 10000),
            InvoiceStatus::Partial => (int) floor($amountDue * 0.5),
            default => $amountDue,
        };

        if ($totalAmountDue <= 0 || empty($totalAmountDue)) {
            return;
        }

        $paymentCount = $this->faker->numberBetween(1, $maxPayments);
        $paymentAmount = (int) floor($totalAmountDue / $paymentCount);
        $remainingAmount = $totalAmountDue;

        $initialPaymentDate = Carbon::parse($invoice->approved_at);
        $maxPaymentDate = now();

        $paymentDates = [];

        for ($i = 0; $i < $paymentCount; $i++) {
            $amount = $i === $paymentCount - 1 ? $remainingAmount : $paymentAmount;

            if ($amount <= 0) {
                break;
            }

            if ($i === 0) {
                $postedAt = $initialPaymentDate->copy()->addDays($this->faker->numberBetween(1, 15));
            } else {
                $postedAt = $paymentDates[$i - 1]->copy()->addDays($this->faker->numberBetween(1, 10));
            }

            if ($postedAt->isAfter($maxPaymentDate)) {
                $postedAt = Carbon::parse($this->faker->dateTimeBetween($initialPaymentDate, $maxPaymentDate));
            }

            $paymentDates[] = $postedAt;

            $data = [
                'posted_at' => $postedAt,
                'amount' => $amount,
                'payment_method' => $this->faker->randomElement(PaymentMethod::class),
                'bank_account_id' => BankAccount::where('company_id', $invoice->company_id)->inRandomOrder()->value('id'),
                'notes' => $this->faker->sentence,
            ];

            $invoice->recordPayment($data);
            $remainingAmount -= $amount;
        }

        if ($invoiceStatus === InvoiceStatus::Paid && ! empty($paymentDates)) {
            $latestPaymentDate = max($paymentDates);
            $invoice->updateQuietly([
                'status' => $invoiceStatus,
                'paid_at' => $latestPaymentDate,
            ]);
        }
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Invoice $invoice) {
            DocumentLineItem::factory()
                ->count(3)
                ->forInvoice($invoice)
                ->create();

            $this->recalculateTotals($invoice);

            $number = DocumentDefault::getBaseNumber() + $invoice->id;

            $invoice->updateQuietly([
                'invoice_number' => "INV-{$number}",
                'order_number' => "ORD-{$number}",
            ]);

            if ($invoice->wasApproved() && $invoice->shouldBeOverdue()) {
                $invoice->updateQuietly([
                    'status' => InvoiceStatus::Overdue,
                ]);
            }
        });
    }

    protected function recalculateTotals(Invoice $invoice): void
    {
        $invoice->refresh();

        if (! $invoice->hasLineItems()) {
            return;
        }

        $subtotalCents = $invoice->lineItems()->sum('subtotal');
        $taxTotalCents = $invoice->lineItems()->sum('tax_total');

        $discountTotalCents = 0;

        if ($invoice->discount_method?->isPerLineItem()) {
            $discountTotalCents = $invoice->lineItems()->sum('discount_total');
        } elseif ($invoice->discount_method?->isPerDocument() && $invoice->discount_rate) {
            if ($invoice->discount_computation?->isPercentage()) {
                $scaledRate = RateCalculator::parseLocalizedRate($invoice->discount_rate);
                $discountTotalCents = RateCalculator::calculatePercentage($subtotalCents, $scaledRate);
            } else {
                $discountTotalCents = $invoice->getRawOriginal('discount_rate');
            }
        }

        $grandTotalCents = $subtotalCents + $taxTotalCents - $discountTotalCents;

        $invoice->update([
            'subtotal' => $subtotalCents,
            'tax_total' => $taxTotalCents,
            'discount_total' => $discountTotalCents,
            'total' => $grandTotalCents,
        ]);
    }
}

# --- File --- ./Accounting/DocumentLineItemFactory.php

<?php

namespace Database\Factories\Accounting;

use App\Models\Accounting\Bill;
use App\Models\Accounting\DocumentLineItem;
use App\Models\Accounting\Estimate;
use App\Models\Accounting\Invoice;
use App\Models\Accounting\RecurringInvoice;
use App\Models\Common\Offering;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentLineItem>
 */
class DocumentLineItemFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected $model = DocumentLineItem::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $quantity = $this->faker->numberBetween(1, 10);

        return [
            'company_id' => 1,
            'description' => $this->faker->sentence,
            'quantity' => $quantity,
            'created_by' => 1,
            'updated_by' => 1,
        ];
    }

    public function forInvoice(Invoice | RecurringInvoice $invoice): static
    {
        return $this
            ->for($invoice, 'documentable')
            ->for($invoice->company, 'company')
            ->afterCreating(function (DocumentLineItem $lineItem) {
                $offering = Offering::query()
                    ->where('company_id', $lineItem->company_id)
                    ->where('sellable', true)
                    ->inRandomOrder()
                    ->firstOrFail();

                $lineItem->updateQuietly([
                    'offering_id' => $offering->id,
                    'unit_price' => $offering->price,
                ]);

                $lineItem->salesTaxes()->syncWithoutDetaching($offering->salesTaxes->pluck('id')->toArray());

                // Only sync discounts if the discount method is per_line_item
                if ($lineItem->documentable->discount_method?->isPerLineItem() ?? true) {
                    $lineItem->salesDiscounts()->syncWithoutDetaching($offering->salesDiscounts->pluck('id')->toArray());
                }

                $lineItem->refresh();

                $taxTotal = $lineItem->calculateTaxTotalAmount();
                $discountTotal = $lineItem->calculateDiscountTotalAmount();

                $lineItem->updateQuietly([
                    'tax_total' => $taxTotal,
                    'discount_total' => $discountTotal,
                ]);
            });
    }

    public function forEstimate(Estimate $estimate): static
    {
        return $this
            ->for($estimate, 'documentable')
            ->for($estimate->company, 'company')
            ->afterCreating(function (DocumentLineItem $lineItem) {
                $offering = Offering::query()
                    ->where('company_id', $lineItem->company_id)
                    ->where('sellable', true)
                    ->inRandomOrder()
                    ->firstOrFail();

                $lineItem->updateQuietly([
                    'offering_id' => $offering->id,
                    'unit_price' => $offering->price,
                ]);

                $lineItem->salesTaxes()->syncWithoutDetaching($offering->salesTaxes->pluck('id')->toArray());

                // Only sync discounts if the discount method is per_line_item
                if ($lineItem->documentable->discount_method?->isPerLineItem() ?? true) {
                    $lineItem->salesDiscounts()->syncWithoutDetaching($offering->salesDiscounts->pluck('id')->toArray());
                }

                $lineItem->refresh();

                $taxTotal = $lineItem->calculateTaxTotalAmount();
                $discountTotal = $lineItem->calculateDiscountTotalAmount();

                $lineItem->updateQuietly([
                    'tax_total' => $taxTotal,
                    'discount_total' => $discountTotal,
                ]);
            });
    }

    public function forBill(Bill $bill): static
    {
        return $this
            ->for($bill, 'documentable')
            ->for($bill->company, 'company')
            ->afterCreating(function (DocumentLineItem $lineItem) {
                $offering = Offering::query()
                    ->where('company_id', $lineItem->company_id)
                    ->where('purchasable', true)
                    ->inRandomOrder()
                    ->firstOrFail();

                $lineItem->updateQuietly([
                    'offering_id' => $offering->id,
                    'unit_price' => $offering->price,
                ]);

                $lineItem->purchaseTaxes()->syncWithoutDetaching($offering->purchaseTaxes->pluck('id')->toArray());

                // Only sync discounts if the discount method is per_line_item
                if ($lineItem->documentable->discount_method?->isPerLineItem() ?? true) {
                    $lineItem->purchaseDiscounts()->syncWithoutDetaching($offering->purchaseDiscounts->pluck('id')->toArray());
                }

                $lineItem->refresh();

                $taxTotal = $lineItem->calculateTaxTotalAmount();
                $discountTotal = $lineItem->calculateDiscountTotalAmount();

                $lineItem->updateQuietly([
                    'tax_total' => $taxTotal,
                    'discount_total' => $discountTotal,
                ]);
            });
    }
}

# --- File --- ./Banking/InstitutionFactory.php

<?php

namespace Database\Factories\Banking;

use App\Models\Banking\Institution;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Institution>
 */
class InstitutionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            //
        ];
    }
}

# --- File --- ./Banking/BankAccountFactory.php

<?php

namespace Database\Factories\Banking;

use App\Enums\Banking\BankAccountType;
use App\Models\Banking\BankAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BankAccount>
 */
class BankAccountFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected $model = BankAccount::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => 1,
            'type' => BankAccountType::Depository,
            'number' => $this->faker->unique()->numerify(str_repeat('#', 12)),
            'enabled' => false,
        ];
    }
}

# --- File --- ./Setting/CompanyProfileFactory.php

<?php

namespace Database\Factories\Setting;

use App\Enums\Setting\EntityType;
use App\Faker\State;
use App\Models\Common\Address;
use App\Models\Company;
use App\Models\Setting\CompanyProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanyProfile>
 */
class CompanyProfileFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected $model = CompanyProfile::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'phone_number' => $this->faker->phoneNumber,
            'email' => $this->faker->email,
            'entity_type' => $this->faker->randomElement(EntityType::class),
        ];
    }

    public function forCompany(Company $company): self
    {
        return $this->state([
            'company_id' => $company->id,
            'created_by' => $company->owner->id,
            'updated_by' => $company->owner->id,
        ]);
    }

    public function withAddress(?string $countryCode = null): self
    {
        return $this->has(
            Address::factory()
                ->general()
                ->when($countryCode, function (Factory $factory) use ($countryCode) {
                    return $factory->forCountry($countryCode);
                })
                ->useParentCompany()
        );
    }
}

# --- File --- ./Setting/LocalizationFactory.php

<?php

namespace Database\Factories\Setting;

use App\Enums\Setting\DateFormat;
use App\Enums\Setting\NumberFormat;
use App\Enums\Setting\TimeFormat;
use App\Enums\Setting\WeekStart;
use App\Models\Setting\Localization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Localization>
 */
class LocalizationFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected $model = Localization::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'date_format' => DateFormat::DEFAULT,
            'time_format' => TimeFormat::DEFAULT,
        ];
    }

    public function withCountry(string $code, string $language = 'en'): Factory
    {
        $number_format = NumberFormat::fromLanguageAndCountry($language, $code) ?? NumberFormat::DEFAULT;
        $percent_first = Localization::isPercentFirst($language, $code) ?? false;

        $locale = Localization::getLocale($language, $code);
        $timezone = $this->faker->timezone($code);
        $week_start = Localization::getWeekStart($locale) ?? WeekStart::DEFAULT;

        return $this->state([
            'language' => $language,
            'timezone' => $timezone,
            'number_format' => $number_format,
            'percent_first' => $percent_first,
            'week_start' => $week_start,
            'fiscal_year_end_month' => 12,
            'fiscal_year_end_day' => 31,
        ]);
    }
}

# --- File --- ./Setting/CurrencyFactory.php

<?php

namespace Database\Factories\Setting;

use App\Models\Setting\Currency;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Currency>
 */
class CurrencyFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected $model = Currency::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $defaultCurrency = currency('USD');

        return [
            'name' => $defaultCurrency->getName(),
            'code' => $defaultCurrency->getCurrency(),
            'rate' => $defaultCurrency->getRate(),
            'precision' => $defaultCurrency->getPrecision(),
            'symbol' => $defaultCurrency->getSymbol(),
            'symbol_first' => $defaultCurrency->isSymbolFirst(),
            'decimal_mark' => $defaultCurrency->getDecimalMark(),
            'thousands_separator' => $defaultCurrency->getThousandsSeparator(),
            'enabled' => false,
        ];
    }

    /**
     * Define a state for a specific currency.
     */
    public function forCurrency(string $code, ?float $rate = null): static
    {
        $currency = currency($code);

        return $this->state([
            'name' => $currency->getName(),
            'code' => $currency->getCurrency(),
            'rate' => $rate ?? $currency->getRate(),
            'precision' => $currency->getPrecision(),
            'symbol' => $currency->getSymbol(),
            'symbol_first' => $currency->isSymbolFirst(),
            'decimal_mark' => $currency->getDecimalMark(),
            'thousands_separator' => $currency->getThousandsSeparator(),
            'enabled' => false,
        ]);
    }
}

# --- File --- ./Setting/CompanyDefaultFactory.php

<?php

namespace Database\Factories\Setting;

use App\Faker\CurrencyCode;
use App\Models\Company;
use App\Models\Setting\CompanyDefault;
use App\Models\Setting\Currency;
use App\Models\Setting\DocumentDefault;
use App\Models\Setting\Localization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanyDefault>
 */
class CompanyDefaultFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected $model = CompanyDefault::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {

        return [
            //
        ];
    }

    public function withDefault(User $user, Company $company, ?string $currencyCode, string $countryCode, string $language = 'en'): static
    {
        if ($currencyCode === null) {
            /** @var CurrencyCode $currencyFaker */
            $currencyFaker = $this->faker;
            $currencyCode = $currencyFaker->currencyCode($countryCode);
        }

        $currency = $this->createCurrency($company, $user, $currencyCode);
        $this->createDocumentDefaults($company, $user);
        $this->createLocalization($company, $user, $countryCode, $language);

        $companyDefaults = [
            'company_id' => $company->id,
            'currency_code' => $currency->code,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ];

        return $this->state($companyDefaults);
    }

    private function createCurrency(Company $company, User $user, string $currencyCode): Currency
    {
        return Currency::factory()->forCurrency($currencyCode)->createQuietly([
            'company_id' => $company->id,
            'enabled' => true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
    }

    private function createDocumentDefaults(Company $company, User $user): void
    {
        DocumentDefault::factory()->invoice()->createQuietly([
            'company_id' => $company->id,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        DocumentDefault::factory()->bill()->createQuietly([
            'company_id' => $company->id,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        DocumentDefault::factory()->estimate()->createQuietly([
            'company_id' => $company->id,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
    }

    private function createLocalization(Company $company, User $user, string $countryCode, string $language): void
    {
        Localization::factory()->withCountry($countryCode, $language)->createQuietly([
            'company_id' => $company->id,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
    }
}

# --- File --- ./Setting/DocumentDefaultFactory.php

<?php

namespace Database\Factories\Setting;

use App\Enums\Accounting\DocumentType;
use App\Enums\Setting\Font;
use App\Enums\Setting\Template;
use App\Models\Setting\DocumentDefault;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentDefault>
 */
class DocumentDefaultFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = DocumentDefault::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => 1,
            'payment_terms' => 'due_upon_receipt',
        ];
    }

    /**
     * The model's common default state.
     */
    private function baseState(DocumentType $type): array
    {
        $state = [
            'type' => $type,
            'number_prefix' => $type->getDefaultPrefix(),
            'item_name' => ['option' => 'items', 'custom' => null],
            'unit_name' => ['option' => 'quantity', 'custom' => null],
            'price_name' => ['option' => 'price', 'custom' => null],
            'amount_name' => ['option' => 'amount', 'custom' => null],
        ];

        if ($type !== DocumentType::Bill) {
            $state = [...$state,
                'header' => $type->getLabel(),
                'show_logo' => false,
                'accent_color' => '#4F46E5',
                'font' => Font::Inter,
                'template' => Template::Default,
            ];
        }

        return $state;
    }

    /**
     * Indicate that the model's type is invoice.
     */
    public function invoice(): self
    {
        return $this->state($this->baseState(DocumentType::Invoice));
    }

    /**
     * Indicate that the model's type is bill.
     */
    public function bill(): self
    {
        return $this->state($this->baseState(DocumentType::Bill));
    }

    /**
     * Indicate that the model's type is estimate.
     */
    public function estimate(): self
    {
        return $this->state($this->baseState(DocumentType::Estimate));
    }
}

# --- File --- ./Core/DepartmentFactory.php

<?php

namespace Database\Factories\Core;

use App\Models\Core\Department;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Department>
 */
class DepartmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            //
        ];
    }
}

# --- File --- ./CompanyFactory.php

<?php

namespace Database\Factories;

use App\Models\Accounting\Bill;
use App\Models\Accounting\Estimate;
use App\Models\Accounting\Invoice;
use App\Models\Accounting\RecurringInvoice;
use App\Models\Accounting\Transaction;
use App\Models\Common\Client;
use App\Models\Common\Offering;
use App\Models\Common\Vendor;
use App\Models\Company;
use App\Models\Setting\CompanyProfile;
use App\Models\User;
use App\Services\CompanyDefaultService;
use Illuminate\Database\Eloquent\Factories\Factory;

class CompanyFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Company::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->company(),
            'user_id' => User::factory(),
            'personal_company' => true,
        ];
    }

    public function withCompanyProfile(?string $countryCode = null): self
    {
        return $this->afterCreating(function (Company $company) use ($countryCode) {
            CompanyProfile::factory()
                ->forCompany($company)
                ->withAddress($countryCode)
                ->create();
        });
    }

    /**
     * Set up default settings for the company after creation.
     */
    public function withCompanyDefaults(string $currencyCode = 'USD', string $locale = 'en'): self
    {
        return $this->afterCreating(function (Company $company) use ($currencyCode, $locale) {
            $countryCode = $company->profile->address->country_code;
            $companyDefaultService = app(CompanyDefaultService::class);
            $companyDefaultService->createCompanyDefaults($company, $company->owner, $currencyCode, $countryCode, $locale);
        });
    }

    public function withTransactions(int $count = 2000): self
    {
        return $this->afterCreating(function (Company $company) use ($count) {
            $defaultBankAccount = $company->default->bankAccount;

            Transaction::factory()
                ->forCompanyAndBankAccount($company, $defaultBankAccount)
                ->count($count)
                ->create([
                    'created_by' => $company->user_id,
                    'updated_by' => $company->user_id,
                ]);
        });
    }

    public function withClients(int $count = 10): self
    {
        return $this->afterCreating(function (Company $company) use ($count) {
            Client::factory()
                ->count($count)
                ->withPrimaryContact()
                ->withAddresses()
                ->create([
                    'company_id' => $company->id,
                    'created_by' => $company->user_id,
                    'updated_by' => $company->user_id,
                ]);
        });
    }

    public function withVendors(int $count = 10): self
    {
        return $this->afterCreating(function (Company $company) use ($count) {
            Vendor::factory()
                ->count($count)
                ->withContact()
                ->withAddress()
                ->create([
                    'company_id' => $company->id,
                    'created_by' => $company->user_id,
                    'updated_by' => $company->user_id,
                ]);
        });
    }

    public function withOfferings(int $count = 10): self
    {
        return $this->afterCreating(function (Company $company) use ($count) {
            Offering::factory()
                ->count($count)
                ->withSalesAdjustments()
                ->withPurchaseAdjustments()
                ->create([
                    'company_id' => $company->id,
                    'created_by' => $company->user_id,
                    'updated_by' => $company->user_id,
                ]);
        });
    }

    public function withInvoices(int $count = 10): self
    {
        return $this->afterCreating(function (Company $company) use ($count) {
            $draftCount = (int) floor($count * 0.2);
            $approvedCount = (int) floor($count * 0.2);
            $paidCount = (int) floor($count * 0.3);
            $partialCount = (int) floor($count * 0.1);
            $overpaidCount = (int) floor($count * 0.1);
            $overdueCount = $count - ($draftCount + $approvedCount + $paidCount + $partialCount + $overpaidCount);

            Invoice::factory()
                ->count($draftCount)
                ->withLineItems()
                ->create([
                    'company_id' => $company->id,
                    'created_by' => $company->user_id,
                    'updated_by' => $company->user_id,
                ]);

            Invoice::factory()
                ->count($approvedCount)
                ->approved()
                ->create([
                    'company_id' => $company->id,
                    'created_by' => $company->user_id,
                    'updated_by' => $company->user_id,
                ]);

            Invoice::factory()
                ->count($paidCount)
                ->paid()
                ->create([
                    'company_id' => $company->id,
                    'created_by' => $company->user_id,
                    'updated_by' => $company->user_id,
                ]);

            Invoice::factory()
                ->count($partialCount)
                ->partial()
                ->create([
                    'company_id' => $company->id,
                    'created_by' => $company->user_id,
                    'updated_by' => $company->user_id,
                ]);

            Invoice::factory()
                ->count($overpaidCount)
                ->overpaid()
                ->create([
                    'company_id' => $company->id,
                    'created_by' => $company->user_id,
                    'updated_by' => $company->user_id,
                ]);

            Invoice::factory()
                ->count($overdueCount)
                ->overdue()
                ->create([
                    'company_id' => $company->id,
                    'created_by' => $company->user_id,
                    'updated_by' => $company->user_id,
                ]);
        });
    }

    public function withRecurringInvoices(int $count = 10): self
    {
        return $this->afterCreating(function (Company $company) use ($count) {
            $draftCount = (int) floor($count * 0.2);     // 20% drafts without schedule
            $scheduledCount = (int) floor($count * 0.2);  // 20% drafts with schedule
            $activeCount = (int) floor($count * 0.4);     // 40% active and generating
            $endedCount = (int) floor($count * 0.1);      // 10% manually ended
            $completedCount = $count - ($draftCount + $scheduledCount + $activeCount + $endedCount); // 10% completed by end conditions

            // Draft recurring invoices (no schedule)
            RecurringInvoice::factory()
                ->count($draftCount)
                ->create([
                    'company_id' => $company->id,
                    'created_by' => $company->user_id,
                    'updated_by' => $company->user_id,
                ]);

            // Draft recurring invoices with schedule
            RecurringInvoice::factory()
                ->count($scheduledCount)
                ->withSchedule()
                ->create([
                    'company_id' => $company->id,
                    'created_by' => $company->user_id,
                    'updated_by' => $company->user_id,
                ]);

            // Active recurring invoices with various schedules and historical invoices
            RecurringInvoice::factory()
                ->count($activeCount)
                ->active()
                ->create([
                    'company_id' => $company->id,
                    'created_by' => $company->user_id,
                    'updated_by' => $company->user_id,
                ]);

            // Manually ended recurring invoices
            RecurringInvoice::factory()
                ->count($endedCount)
                ->ended()
                ->create([
                    'company_id' => $company->id,
                    'created_by' => $company->user_id,
                    'updated_by' => $company->user_id,
                ]);

            // Completed recurring invoices (reached end conditions)
            RecurringInvoice::factory()
                ->count($completedCount)
                ->active()
                ->endAfter($this->faker->numberBetween(5, 12))
                ->create([
                    'company_id' => $company->id,
                    'created_by' => $company->user_id,
                    'updated_by' => $company->user_id,
                ]);
        });
    }

    public function withEstimates(int $count = 10): self
    {
        return $this->afterCreating(function (Company $company) use ($count) {
            $draftCount = (int) floor($count * 0.2);     // 20% drafts
            $approvedCount = (int) floor($count * 0.3);   // 30% approved
            $acceptedCount = (int) floor($count * 0.2);  // 20% accepted
            $declinedCount = (int) floor($count * 0.1);  // 10% declined
            $convertedCount = (int) floor($count * 0.1); // 10% converted to invoices
            $expiredCount = $count - ($draftCount + $approvedCount + $acceptedCount + $declinedCount + $convertedCount); // remaining 10%

            Estimate::factory()
                ->count($draftCount)
                ->create([
                    'company_id' => $company->id,
                    'created_by' => $company->user_id,
                    'updated_by' => $company->user_id,
                ]);

            Estimate::factory()
                ->count($approvedCount)
                ->approved()
                ->create([
                    'company_id' => $company->id,
                    'created_by' => $company->user_id,
                    'updated_by' => $company->user_id,
                ]);

            Estimate::factory()
                ->count($acceptedCount)
                ->accepted()
                ->create([
                    'company_id' => $company->id,
                    'created_by' => $company->user_id,
                    'updated_by' => $company->user_id,
                ]);

            Estimate::factory()
                ->count($declinedCount)
                ->declined()
                ->create([
                    'company_id' => $company->id,
                    'created_by' => $company->user_id,
                    'updated_by' => $company->user_id,
                ]);

            Estimate::factory()
                ->count($convertedCount)
                ->converted()
                ->create([
                    'company_id' => $company->id,
                    'created_by' => $company->user_id,
                    'updated_by' => $company->user_id,
                ]);

            Estimate::factory()
                ->count($expiredCount)
                ->expired()
                ->create([
                    'company_id' => $company->id,
                    'created_by' => $company->user_id,
                    'updated_by' => $company->user_id,
                ]);
        });
    }

    public function withBills(int $count = 10): self
    {
        return $this->afterCreating(function (Company $company) use ($count) {
            $unpaidCount = (int) floor($count * 0.4);
            $paidCount = (int) floor($count * 0.3);
            $partialCount = (int) floor($count * 0.2);
            $overdueCount = $count - ($unpaidCount + $paidCount + $partialCount);

            Bill::factory()
                ->count($unpaidCount)
                ->create([
                    'company_id' => $company->id,
                    'created_by' => $company->user_id,
                    'updated_by' => $company->user_id,
                ]);

            Bill::factory()
                ->count($paidCount)
                ->paid()
                ->create([
                    'company_id' => $company->id,
                    'created_by' => $company->user_id,
                    'updated_by' => $company->user_id,
                ]);

            Bill::factory()
                ->count($partialCount)
                ->partial()
                ->create([
                    'company_id' => $company->id,
                    'created_by' => $company->user_id,
                    'updated_by' => $company->user_id,
                ]);

            Bill::factory()
                ->count($overdueCount)
                ->overdue()
                ->create([
                    'company_id' => $company->id,
                    'created_by' => $company->user_id,
                    'updated_by' => $company->user_id,
                ]);
        });
    }
}

# --- File --- ./Concerns/HasParentRelationship.php

<?php

namespace Database\Factories\Concerns;

use Illuminate\Database\Eloquent\Model;

trait HasParentRelationship
{
    public function useParentCompany(): self
    {
        return $this->state(function (array $attributes, Model $parent) {
            return [
                'company_id' => $parent->company_id,
                'created_by' => $parent->created_by ?? 1,
                'updated_by' => $parent->updated_by ?? 1,
            ];
        });
    }
}

# --- File --- ./UserFactory.php

<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Wallo\FilamentCompanies\FilamentCompanies;

class UserFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = User::class;

    /**
     * The current password being used by the factory.
     */
    protected static ?string $password = null;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'profile_photo_path' => null,
            'current_company_id' => null,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(static fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the user should have a personal company.
     */
    public function withPersonalCompany(?callable $callback = null): static
    {
        if (! FilamentCompanies::hasCompanyFeatures()) {
            return $this->state([]);
        }

        return $this->has(
            Company::factory()
                ->withCompanyProfile()
                ->withCompanyDefaults()
                ->state(fn (array $attributes, User $user) => [
                    'name' => $user->name . '\'s Company',
                    'user_id' => $user->id,
                    'personal_company' => true,
                ])
                ->when(is_callable($callback), $callback),
            'ownedCompanies'
        );
    }
}

# --- File --- ./Service/CurrencyListFactory.php

<?php

namespace Database\Factories\Service;

use App\Models\Service\CurrencyList;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CurrencyList>
 */
class CurrencyListFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            //
        ];
    }
}

# --- File --- ./Common/AddressFactory.php

<?php

namespace Database\Factories\Common;

use App\Enums\Common\AddressType;
use App\Models\Common\Address;
use Database\Factories\Concerns\HasParentRelationship;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Address>
 */
class AddressFactory extends Factory
{
    use HasParentRelationship;

    /**
     * The name of the factory's corresponding model.
     */
    protected $model = Address::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => 1,
            'type' => $this->faker->randomElement(AddressType::cases()),
            'recipient' => $this->faker->name,
            'phone' => $this->faker->phoneNumber,
            'address_line_1' => $this->faker->streetAddress,
            'address_line_2' => $this->faker->streetAddress,
            'city' => $this->faker->city,
            'state_id' => $this->faker->state('US'),
            'postal_code' => $this->faker->postcode,
            'country_code' => 'US',
            'notes' => $this->faker->sentence,
            'created_by' => 1,
            'updated_by' => 1,
        ];
    }

    public function billing(): self
    {
        return $this->state([
            'type' => AddressType::Billing,
        ]);
    }

    public function shipping(): self
    {
        return $this->state([
            'type' => AddressType::Shipping,
        ]);
    }

    public function general(): self
    {
        return $this->state([
            'type' => AddressType::General,
        ]);
    }

    public function forCountry(string $countryCode): self
    {
        return $this->state([
            'state_id' => $this->faker->state($countryCode),
            'country_code' => $countryCode,
        ]);
    }
}

# --- File --- ./Common/OfferingFactory.php

<?php

namespace Database\Factories\Common;

use App\Enums\Accounting\AccountCategory;
use App\Enums\Accounting\AccountType;
use App\Enums\Accounting\AdjustmentType;
use App\Enums\Common\OfferingType;
use App\Models\Accounting\Account;
use App\Models\Accounting\Adjustment;
use App\Models\Common\Offering;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Offering>
 */
class OfferingFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected $model = Offering::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => 1,
            'name' => $this->faker->words(3, true),
            'description' => $this->faker->sentence,
            'type' => $this->faker->randomElement(OfferingType::cases()),
            'price' => $this->faker->numberBetween(500, 50000), // $5.00 to $500.00
            'sellable' => false,
            'purchasable' => false,
            'income_account_id' => null,
            'expense_account_id' => null,
            'created_by' => 1,
            'updated_by' => 1,
        ];
    }

    public function withSalesAdjustments(): self
    {
        return $this->afterCreating(function (Offering $offering) {
            $incomeAccount = Account::query()
                ->where('company_id', $offering->company_id)
                ->where('category', AccountCategory::Revenue)
                ->where('type', AccountType::OperatingRevenue)
                ->inRandomOrder()
                ->firstOrFail();

            $offering->updateQuietly([
                'sellable' => true,
                'income_account_id' => $incomeAccount->id,
            ]);

            $adjustments = $offering->company?->adjustments()
                ->where('type', AdjustmentType::Sales)
                ->pluck('id');

            $adjustmentsToAttach = $adjustments->isNotEmpty()
                ? $adjustments->random(min(2, $adjustments->count()))
                : Adjustment::factory()->salesTax()->count(2)->create()->pluck('id');

            $offering->salesAdjustments()->attach($adjustmentsToAttach);
        });
    }

    public function withPurchaseAdjustments(): self
    {
        return $this->afterCreating(function (Offering $offering) {
            $expenseAccount = Account::query()
                ->where('company_id', $offering->company_id)
                ->where('category', AccountCategory::Expense)
                ->where('type', AccountType::OperatingExpense)
                ->inRandomOrder()
                ->firstOrFail();

            $offering->updateQuietly([
                'purchasable' => true,
                'expense_account_id' => $expenseAccount->id,
            ]);

            $adjustments = $offering->company?->adjustments()
                ->where('type', AdjustmentType::Purchase)
                ->pluck('id');

            $adjustmentsToAttach = $adjustments->isNotEmpty()
                ? $adjustments->random(min(2, $adjustments->count()))
                : Adjustment::factory()->purchaseTax()->count(2)->create()->pluck('id');

            $offering->purchaseAdjustments()->attach($adjustmentsToAttach);
        });
    }
}

# --- File --- ./Common/ContactFactory.php

<?php

namespace Database\Factories\Common;

use App\Models\Common\Contact;
use Database\Factories\Concerns\HasParentRelationship;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contact>
 */
class ContactFactory extends Factory
{
    use HasParentRelationship;

    /**
     * The name of the factory's corresponding model.
     */
    protected $model = Contact::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => 1,
            'first_name' => $this->faker->firstName,
            'last_name' => $this->faker->lastName,
            'email' => $this->faker->unique()->safeEmail,
            'phones' => $this->generatePhones(),
            'is_primary' => $this->faker->boolean(50),
            'created_by' => 1,
            'updated_by' => 1,
        ];
    }

    protected function generatePhones(): array
    {
        $phones = [];

        if ($this->faker->boolean(80)) {
            $phones[] = [
                'data' => ['number' => $this->faker->phoneNumber],
                'type' => 'primary',
            ];
        }

        if ($this->faker->boolean(50)) {
            $phones[] = [
                'data' => ['number' => $this->faker->phoneNumber],
                'type' => 'mobile',
            ];
        }

        if ($this->faker->boolean(30)) {
            $phones[] = [
                'data' => ['number' => $this->faker->phoneNumber],
                'type' => 'toll_free',
            ];
        }

        if ($this->faker->boolean(10)) {
            $phones[] = [
                'data' => ['number' => $this->faker->phoneNumber],
                'type' => 'fax',
            ];
        }

        return $phones;
    }

    public function primary(): self
    {
        return $this->state([
            'is_primary' => true,
        ]);
    }

    public function secondary(): self
    {
        return $this->state([
            'is_primary' => false,
        ]);
    }
}

# --- File --- ./Common/ClientFactory.php

<?php

namespace Database\Factories\Common;

use App\Models\Common\Address;
use App\Models\Common\Client;
use App\Models\Common\Contact;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected $model = Client::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => 1,
            'name' => $this->faker->company,
            'currency_code' => fn (array $attributes) => Company::find($attributes['company_id'])->default->currency_code ?? 'USD',
            'account_number' => $this->faker->unique()->numerify(str_repeat('#', 12)),
            'website' => $this->faker->url,
            'notes' => $this->faker->sentence,
            'created_by' => 1,
            'updated_by' => 1,
        ];
    }

    public function withContacts(int $count = 1): self
    {
        return $this->has(
            Contact::factory()
                ->count($count)
                ->useParentCompany()
        );
    }

    public function withPrimaryContact(): self
    {
        return $this->has(
            Contact::factory()
                ->primary()
                ->useParentCompany()
        );
    }

    public function withAddresses(): self
    {
        return $this
            ->has(Address::factory()->billing()->useParentCompany())
            ->has(Address::factory()->shipping()->useParentCompany());
    }
}

# --- File --- ./Common/VendorFactory.php

<?php

namespace Database\Factories\Common;

use App\Enums\Common\ContractorType;
use App\Enums\Common\VendorType;
use App\Models\Common\Address;
use App\Models\Common\Contact;
use App\Models\Common\Vendor;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vendor>
 */
class VendorFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected $model = Vendor::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => 1,
            'name' => $this->faker->company,
            'type' => $this->faker->randomElement(VendorType::cases()),
            'contractor_type' => function (array $attributes) {
                return $attributes['type'] === VendorType::Contractor ? $this->faker->randomElement(ContractorType::cases()) : null;
            },
            'ssn' => function (array $attributes) {
                return $attributes['contractor_type'] === ContractorType::Individual ? $this->faker->numerify(str_repeat('#', 9)) : null;
            },
            'ein' => function (array $attributes) {
                return $attributes['contractor_type'] === ContractorType::Business ? $this->faker->numerify(str_repeat('#', 9)) : null;
            },
            'currency_code' => fn (array $attributes) => Company::find($attributes['company_id'])->default->currency_code ?? 'USD',
            'account_number' => $this->faker->unique()->numerify(str_repeat('#', 12)),
            'website' => $this->faker->url,
            'notes' => $this->faker->sentence,
            'created_by' => 1,
            'updated_by' => 1,
        ];
    }

    public function regular(): self
    {
        return $this->state([
            'type' => VendorType::Regular,
        ]);
    }

    public function contractor(): self
    {
        return $this->state([
            'type' => VendorType::Contractor,
        ]);
    }

    public function individualContractor(): self
    {
        return $this->state([
            'type' => VendorType::Contractor,
            'contractor_type' => ContractorType::Individual,
        ]);
    }

    public function businessContractor(): self
    {
        return $this->state([
            'type' => VendorType::Contractor,
            'contractor_type' => ContractorType::Business,
        ]);
    }

    public function withContact(): self
    {
        return $this->has(Contact::factory()->primary()->useParentCompany());
    }

    public function withAddress(): self
    {
        return $this->has(Address::factory()->general()->useParentCompany());
    }
}
