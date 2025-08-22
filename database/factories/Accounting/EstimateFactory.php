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
