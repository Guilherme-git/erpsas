@props([
    'reportLoaded' => false,
    'summaryData' => [],
    'targetLabel' => null,
])

@php
    use App\Utilities\Currency\CurrencyAccessor;
@endphp

<div>
    <x-filament::section>
        @if($reportLoaded)
            <div @class([
                'grid grid-cols-1 gap-1 place-content-center items-end text-center max-w-fit mx-auto',
                'md:grid-cols-[repeat(1,minmax(0,1fr)_minmax(0,4rem))_minmax(0,1fr)]' => count($summaryData) === 2,
                'md:grid-cols-[repeat(2,minmax(0,1fr)_minmax(0,4rem))_minmax(0,1fr)]' => count($summaryData) === 3,
                'md:grid-cols-[repeat(3,minmax(0,1fr)_minmax(0,4rem))_minmax(0,1fr)]' => count($summaryData) === 4,
                'md:grid-cols-[repeat(4,minmax(0,1fr)_minmax(0,4rem))_minmax(0,1fr)]' => count($summaryData) === 5,
            ])>
                @foreach($summaryData as $summary)
                    <div class="text-sm">
                        <div class="text-gray-600 dark:text-gray-200 font-medium mb-2">{{ $summary['label'] }}</div>

                        @php
                            $isTargetLabel = $summary['label'] === $targetLabel;
                            $isPositive = money($summary['value'], CurrencyAccessor::getDefaultCurrency())->isPositive();
                        @endphp

                        <strong
                            @class([
                                'text-lg',
                                'text-success-700 dark:text-success-400' => $isTargetLabel && $isPositive,
                                'text-danger-700 dark:text-danger-400' => $isTargetLabel && ! $isPositive,
                            ])
                        >
                            {{ $summary['value'] }}
                        </strong>
                    </div>

                    @if(! $loop->last)
                        <div class="flex items-center justify-center">
                            <strong class="text-lg">
                                {{ $loop->remaining === 1 ? '=' : '-' }}
                            </strong>
                        </div>
                    @endif
                @endforeach
            </div>
        @endif
    </x-filament::section>
</div>
