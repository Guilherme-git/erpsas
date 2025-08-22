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
