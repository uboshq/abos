{{--
    ডেলিভারির তালিকার সারিতে "পরের ধাপ" — এক চাপে, তথ্য লাগলে ছোট ঘরে (কোঅর্ডিনেটর, ২৯ সেপ্টেম্বর ২০২৬)।

    ⓘ বোতামের নাম পরের ধাপের নাম; ক্রম মালের পথ ধরে (তোলা → প্যাক → রওনা → পৌঁছেছে), আর তালিকাটা
    সেবার নিয়ম থেকেই ([[DeliveryStageService::manualChoices()]]) — পর্দা এমন বোতাম দেখায় না যা চাপলে
    সেবা না বলবে। রওনায় গাড়ি ও চালক (বহর থেকে বা হাতে) — গেট পাস ঐ ছবিটাই নেয়; পৌঁছেছেতে প্রাপক
    আগে থেকে গ্রাহকের নামে; পৌঁছায়নিতে কারণ; আংশিক চালানের পাতায়, কারণ সারি ধরে পরিমাণ লাগে।
    ⓘ `<details>` — জাভাস্ক্রিপ্ট ছাড়া খোলে, CSP-Alpine-এর সীমা এখানে খাটে না।
    ট্রিপে থাকা চালানে বোতাম নেই — "ট্রিপ …"; ওর খবর ট্রিপ দেয়।

    চাই: $challan, $choices (list<string>), $trip (?Shipment), $vehicles (Collection)।
--}}
@php
    use App\Modules\Sales\Services\DeliveryStage;

    $order = [DeliveryStage::PICKING, DeliveryStage::PACKED, DeliveryStage::DISPATCHED, DeliveryStage::DELIVERED];
    $primary = collect($order)->first(fn ($s) => in_array($s, $choices, true));
    $others = array_values(array_diff($choices, [$primary]));
    $action = route('sales.delivery.move', $challan);
    $input = 'h-(--spacing-field-compact) w-full rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-2xs';
@endphp

@if ($trip !== null)
    <span class="text-2xs text-(--color-ink-muted)" data-row-trip>{{ __('sales::delivery.action.trip_short', ['trip' => $trip->document_no]) }}</span>
@elseif ($primary !== null || $others !== [])
    <div class="flex flex-wrap items-start gap-1" data-row-next>
        @if ($primary !== null)
            @if (in_array($primary, [DeliveryStage::DISPATCHED, DeliveryStage::DELIVERED], true))
                <details class="relative">
                    <summary class="cursor-pointer rounded-(--radius-field) bg-(--color-brand-500) px-2 py-1 text-2xs font-semibold text-white">
                        {{ DeliveryStage::label($primary) }}
                    </summary>
                    <form method="POST" action="{{ $action }}"
                          class="absolute z-20 mt-1 w-64 space-y-2 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-3 shadow">
                        @csrf
                        <input type="hidden" name="stage" value="{{ $primary }}">
                        @if ($primary === DeliveryStage::DISPATCHED)
                            @if ($vehicles->isNotEmpty())
                                <label class="block text-2xs">{{ __('sales::delivery.field.vehicle') }}
                                    <select name="vehicle_id" class="{{ $input }}">
                                        <option value="">{{ __('sales::delivery.field.vehicle_not_in_fleet') }}</option>
                                        @foreach ($vehicles as $vehicle)
                                            <option value="{{ $vehicle->id }}" @selected((int) $challan->vehicle_id === (int) $vehicle->id)>
                                                {{ $vehicle->registration_no }} — {{ $vehicle->name() }}
                                            </option>
                                        @endforeach
                                    </select>
                                </label>
                            @endif
                            <label class="block text-2xs">{{ __('sales::delivery.field.vehicle_no') }}
                                <input type="text" name="vehicle_no" maxlength="64" value="{{ $challan->vehicle_no }}" class="{{ $input }}">
                            </label>
                            <label class="block text-2xs">{{ __('sales::delivery.field.driver_name') }}
                                <input type="text" name="driver_name" maxlength="191" value="{{ $challan->driver_name }}" class="{{ $input }}">
                            </label>
                            <label class="block text-2xs">{{ __('sales::delivery.field.driver_phone') }}
                                <input type="text" inputmode="tel" name="driver_phone" maxlength="32" value="{{ $challan->driver_phone }}" class="{{ $input }}">
                            </label>
                            <p class="text-2xs text-(--color-ink-muted)">{{ __('sales::delivery.action.gate_pass_hint') }}</p>
                        @else
                            <label class="block text-2xs">{{ __('sales::delivery.field.receiver_name') }}
                                <input type="text" name="receiver_name" maxlength="191" required
                                       value="{{ $challan->customer?->name() }}" class="{{ $input }}">
                            </label>
                            <label class="block text-2xs">{{ __('sales::delivery.field.receiver_phone') }}
                                <input type="text" inputmode="tel" name="receiver_phone" maxlength="32"
                                       value="{{ $challan->customer?->phone }}" class="{{ $input }}">
                            </label>
                        @endif
                        <x-ui.button type="submit" tone="primary" class="w-full">{{ __('sales::delivery.action.submit') }}</x-ui.button>
                    </form>
                </details>
            @else
                <form method="POST" action="{{ $action }}">
                    @csrf
                    <input type="hidden" name="stage" value="{{ $primary }}">
                    <button type="submit" class="rounded-(--radius-field) bg-(--color-brand-500) px-2 py-1 text-2xs font-semibold text-white">
                        {{ DeliveryStage::label($primary) }}
                    </button>
                </form>
            @endif
        @endif

        @if ($others !== [])
            <details class="relative">
                <summary class="cursor-pointer rounded-(--radius-field) border border-(--color-border) px-2 py-1 text-2xs"
                         aria-label="{{ __('sales::delivery.action.other') }}">⋯</summary>
                <div class="absolute z-20 mt-1 w-64 space-y-2 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-3 shadow">
                    @foreach ($others as $to)
                        @if ($to === DeliveryStage::PARTIALLY_DELIVERED)
                            <a class="block text-2xs text-(--color-brand-500) underline-offset-2 hover:underline"
                               href="{{ route('sales.delivery.show', $challan) }}">{{ __('sales::delivery.action.partial_page') }}</a>
                        @else
                            <form method="POST" action="{{ $action }}" class="space-y-1">
                                @csrf
                                <input type="hidden" name="stage" value="{{ $to }}">
                                @if ($to === DeliveryStage::FAILED)
                                    <input type="text" name="note" maxlength="500" required
                                           placeholder="{{ __('sales::delivery.field.reason_note') }}" class="{{ $input }}">
                                @elseif (in_array($to, DeliveryStage::NEEDS_RECEIVER, true))
                                    <input type="text" name="receiver_name" maxlength="191" required
                                           value="{{ $challan->customer?->name() }}" class="{{ $input }}">
                                @endif
                                <button type="submit" class="w-full rounded-(--radius-field) border border-(--color-border) px-2 py-1 text-2xs">
                                    {{ DeliveryStage::label($to) }}
                                </button>
                            </form>
                        @endif
                    @endforeach
                </div>
            </details>
        @endif
    </div>
@endif
