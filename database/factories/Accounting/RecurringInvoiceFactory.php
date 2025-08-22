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
