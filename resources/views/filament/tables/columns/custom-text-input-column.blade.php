@php
    use Filament\Support\Enums\Alignment;

    $isDisabled = $isDisabled();
    $state = $getState();
    $mask = $getMask();
    $isDeferred = $isDeferred();
    $isNavigable = $isNavigable();

    $alignment = $getAlignment() ?? Alignment::Start;

    if (! $alignment instanceof Alignment) {
        $alignment = filled($alignment) ? (Alignment::tryFrom($alignment) ?? $alignment) : null;
    }

    if (filled($mask)) {
        $type = 'text';
    } else {
        $type = $getType();
    }
@endphp

<div
    x-data="{
        error: undefined,

        isEditing: false,

        isLoading: false,

        name: @js($getName()),

        recordKey: @js($recordKey),

        state: @js($state),

        navigateToRow(direction) {
            const currentRow = $el.closest('tr');
            const currentCell = $el.closest('td');
            const currentColumnIndex = Array.from(currentRow.children).indexOf(currentCell);

            const targetRow = direction === 'next'
                ? currentRow.nextElementSibling
                : currentRow.previousElementSibling;

            if (targetRow && targetRow.children[currentColumnIndex]) {
                const targetInput = targetRow.children[currentColumnIndex].querySelector('input[x-model=\'state\']');
                if (targetInput) {
                    targetInput.focus();
                    targetInput.select();
                }
            }
        },

        navigateToColumn(direction) {
            const currentCell = $el.closest('td');
            const currentRow = $el.closest('tr');
            const currentColumnIndex = Array.from(currentRow.children).indexOf(currentCell);

            const targetCell = direction === 'next'
                ? currentRow.children[currentColumnIndex + 1]
                : currentRow.children[currentColumnIndex - 1];

            if (targetCell) {
                const targetInput = targetCell.querySelector('input[x-model=\'state\']');
                if (targetInput) {
                    targetInput.focus();
                    targetInput.select();
                }
            }
        }
    }"
    x-init="
        () => {
            Livewire.hook('commit', ({ component, commit, succeed, fail, respond }) => {
                succeed(({ snapshot, effect }) => {
                    $nextTick(() => {
                        if (component.id !== @js($this->getId())) {
                            return
                        }

                        if (isEditing) {
                            return
                        }

                        if (! $refs.newState) {
                            return
                        }

                        let newState = $refs.newState.value.replaceAll('\\'+String.fromCharCode(34), String.fromCharCode(34))

                        if (state === newState) {
                            return
                        }

                        state = newState
                    })
                })
            })
        }
    "
    {{
        $attributes
            ->merge($getExtraAttributes(), escape: false)
            ->class([
                'fi-ta-text-input w-full min-w-36',
                'px-3 py-4' => ! $isInline(),
            ])
    }}
>
    <input
        type="hidden"
        value="{{ str($state)->replace('"', '\\"') }}"
        x-ref="newState"
    />

    <x-filament::input.wrapper
        :alpine-disabled="'isLoading || ' . \Illuminate\Support\Js::from($isDisabled)"
        alpine-valid="error === undefined"
        x-tooltip="
            error === undefined
                ? false
                : {
                    content: error,
                    theme: $store.theme,
                }
        "
        x-on:click.stop.prevent=""
    >
        {{-- format-ignore-start --}}
        <x-filament::input
            :disabled="$isDisabled"
            :input-mode="$getInputMode()"
            :placeholder="$getPlaceholder()"
            :step="$getStep()"
            :type="$type"
            :x-bind:disabled="$isDisabled ? null : 'isLoading'"
            x-model="state"
            x-on:blur="isEditing = false"
            x-on:focus="isEditing = true"
            :attributes="
                \Filament\Support\prepare_inherited_attributes(
                    $getExtraInputAttributeBag()
                        ->merge([
                            'x-on:change' . ($type === 'number' ? '.debounce.1s' : null) => $isDeferred ? '
                                $wire.handleBatchColumnChanged({
                                    name: name,
                                    recordKey: recordKey,
                                    value: $event.target.value
                                })
                            ' : '
                                isLoading = true

                                const response = await $wire.updateTableColumnState(
                                    name,
                                    recordKey,
                                    $event.target.value,
                                )

                                error = response?.error ?? undefined

                                if (! error) {
                                    state = response
                                }

                                isLoading = false
                            ',
                            'x-on:keydown.enter' => $isDeferred ? '
                                $wire.handleBatchColumnChanged({
                                    name: name,
                                    recordKey: recordKey,
                                    value: state
                                });

                                $nextTick(() => {
                                    $wire.saveBatchChanges();
                                });
                            ' : ($isNavigable ? 'navigateToRow(\'next\')' : null),
                            'x-on:keydown.arrow-down.prevent' => $isNavigable ? 'navigateToRow(\'next\')' : null,
                            'x-on:keydown.arrow-up.prevent' => $isNavigable ? 'navigateToRow(\'prev\')' : null,
                            'x-on:keydown.arrow-left.prevent' => $isNavigable ? 'navigateToColumn(\'prev\')' : null,
                            'x-on:keydown.arrow-right.prevent' => $isNavigable ? 'navigateToColumn(\'next\')' : null,
                            'x-mask' . ($mask instanceof \Filament\Support\RawJs ? ':dynamic' : '') => filled($mask) ? $mask : null,
                        ])
                        ->class([
                            match ($alignment) {
                                Alignment::Start => 'text-start',
                                Alignment::Center => 'text-center',
                                Alignment::End => 'text-end',
                                Alignment::Left => 'text-left',
                                Alignment::Right => 'text-right',
                                Alignment::Justify, Alignment::Between => 'text-justify',
                                default => $alignment,
                            },
                        ])
                )
            "
        />
        {{-- format-ignore-end --}}
    </x-filament::input.wrapper>
</div>
