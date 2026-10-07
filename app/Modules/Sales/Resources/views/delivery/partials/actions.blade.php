{{--
    হাতে ধাপ বসানো — প্রতিটা সম্ভাব্য পরের ধাপের নিজের ছোট ফর্ম।

    ── ⓘ কেন আলাদা ফর্ম, একটা বড় ফর্ম নয় ────────────────────────────────
    "পৌঁছায়নি" চায় কারণ, "পৌঁছেছে" চায় কে নিলেন, "আংশিক" চায় পরিমাণও।
    এক ফর্মে সব ঘর রাখলে কোনটা কখন লাগে তা বোঝাতে জাভাস্ক্রিপ্ট লাগত
    (CSP-Alpine-এ শর্তের অনেক সীমা)। ⭐ `<details>` বিনা স্ক্রিপ্টে খোলে,
    আর প্রতিটা ফর্মে কেবল তার নিজের ঘর।

    ⓘ বোতামের তালিকা সেবার নিয়ম থেকেই ([[DeliveryStageService::manualChoices()]]) —
    পর্দা এমন বোতাম দেখায় না যেটা চাপলে সেবা না বলবে।

    চাই: $challan, $deliveryStages।
--}}
@php
    use App\Modules\Sales\Services\DeliveryStage;

    // ⚠️ local-এ lazy loading বন্ধ — চালানের পাতা সারিগুলো আগেই টানে, ডেলিভারির পাতা নাও টানতে পারে
    $challan->loadMissing('lines.product');

    $choices = $deliveryStages->manualChoices($challan);

    /* ⭐ "ডেলিভারি নিশ্চিত" নিজের বড় ফর্মে — মালিকের পরিকল্পনা, ধাপ ৪ (২৮ সেপ্টেম্বর ২০২৬)।
       ⓘ প্রাপক আগে থেকেই গ্রাহকের নামে, তাই সাধারণ দিনে এক চাপ; অন্য কেউ নিলে নামটা বদলান।
       বাকি ধাপগুলো নিচে "অন্য ধাপ"-এ, আগের মতোই। */
    $canDeliver = in_array(DeliveryStage::DELIVERED, $choices, true);
    $choices = array_values(array_diff($choices, [DeliveryStage::DELIVERED]));
    $challan->loadMissing('customer');
    $trip = $deliveryStages->activeTrip($challan);
    $action = route('sales.delivery.move', $challan);
    $inputClass = 'h-(--spacing-field-compact) w-32 rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-2 text-sm';
@endphp

<div class="border-t border-(--color-border) p-4">
    <h3 class="mb-3 font-semibold">{{ __('sales::delivery.action.title') }}</h3>

    @if ($trip !== null)
        <p class="mb-3 text-sm text-(--color-ink-muted)">
            {{ __('sales::delivery.action.on_trip', ['trip' => $trip->document_no]) }}
        </p>
    @endif

    @if ($canDeliver)
        <form method="POST" action="{{ $action }}"
              class="mb-3 space-y-3 rounded-(--radius-card) border border-(--color-border) p-3">
            @csrf
            <input type="hidden" name="stage" value="{{ DeliveryStage::DELIVERED }}">
            <p class="text-sm text-(--color-ink-muted)">{{ __('sales::delivery.action.confirm_hint') }}</p>
            <div class="flex flex-wrap items-end gap-2">
                <x-ui.field name="receiver_name" :label="__('sales::delivery.field.receiver_name')"
                            :value="old('receiver_name', $challan->customer?->name())"
                            :required="true" class="min-w-64 flex-1" />
                <x-ui.field name="receiver_phone" type="tel"
                            :value="old('receiver_phone', $challan->customer?->phone)"
                            :label="__('sales::delivery.field.receiver_phone')" class="w-52" />
                <x-ui.button type="submit" tone="primary" icon="check-circle">
                    {{ __('sales::delivery.action.confirm') }}
                </x-ui.button>
            </div>
        </form>
    @endif

    @if ($choices === [] && ! $canDeliver)
        <p class="text-sm text-(--color-ink-muted)">{{ __('sales::delivery.action.none') }}</p>
    @elseif ($choices !== [])
        @if ($canDeliver)
            <h4 class="mb-2 text-sm font-semibold text-(--color-ink-muted)">{{ __('sales::delivery.action.other') }}</h4>
        @endif
        <div class="space-y-2">
            @foreach ($choices as $to)
                <details class="rounded-(--radius-card) border border-(--color-border) p-3">
                    <summary class="flex cursor-pointer flex-wrap items-center justify-between gap-2 text-sm font-semibold">
                        <span>{{ __('sales::delivery.action.to', ['stage' => DeliveryStage::label($to)]) }}</span>
                        @include('sales::delivery.partials.badge', ['stage' => $to])
                    </summary>

                    <form method="POST" action="{{ $action }}" class="mt-3 space-y-3">
                        @csrf
                        <input type="hidden" name="stage" value="{{ $to }}">

                        @if ($to === DeliveryStage::FAILED)
                            <x-ui.select name="reason_code_id" :label="__('sales::delivery.field.reason')"
                                         :options="$deliveryStages->reasons()->mapWithKeys(fn ($r) => [$r->id => $r->name()])->all()"
                                         :placeholder="'—'"
                                         :hint="__('sales::delivery.field.reason_hint')" />
                        @endif

                        @if (in_array($to, DeliveryStage::NEEDS_RECEIVER, true))
                            <div class="flex flex-wrap items-end gap-2">
                                <x-ui.field name="receiver_name" :label="__('sales::delivery.field.receiver_name')"
                                            :required="true" class="min-w-64 flex-1" />
                                <x-ui.field name="receiver_phone" type="tel"
                                            :label="__('sales::delivery.field.receiver_phone')" class="w-52" />
                            </div>
                        @endif

                        @if ($to === DeliveryStage::PARTIALLY_DELIVERED)
                            <p class="text-xs text-(--color-ink-muted)">{{ __('sales::delivery.field.partial_hint') }}</p>

                            <ul>
                                @foreach ($challan->lines as $line)
                                    <li class="flex flex-wrap items-center gap-3 border-b border-(--color-border) py-2 text-sm last:border-b-0">
                                        <span class="min-w-0 flex-1 truncate">
                                            {{ $line->line_no }}. {{ $line->product?->name() }}
                                        </span>
                                        <span class="tabular w-28 shrink-0 text-end text-(--color-ink-muted)">
                                            {{ __('sales::delivery.field.sent_qty') }}:
                                            {{ \App\Core\Support\Money::format($line->delivered_qty) }}
                                        </span>
                                        <input type="number" name="lines[{{ $line->id }}]" min="0" step="0.0001"
                                               max="{{ $line->delivered_qty }}"
                                               value="{{ old('lines.'.$line->id, '0') }}"
                                               aria-label="{{ __('sales::delivery.field.delivered_qty') }} — {{ $line->product?->name() }}"
                                               class="{{ $inputClass }}">
                                        {{-- ⭐ ভাঙা পৌঁছানো — আটকে রাখা মজুদে ফেরত (ধাপ ৭) --}}
                                        <label class="flex items-center gap-1 text-xs text-(--color-ink-muted)">
                                            {{ __('sales::delivery.field.damaged_qty') }}
                                            <input type="number" name="damaged[{{ $line->id }}]" min="0" step="0.0001"
                                                   max="{{ $line->delivered_qty }}" data-damaged-qty
                                                   value="{{ old('damaged.'.$line->id, '0') }}"
                                                   aria-label="{{ __('sales::delivery.field.damaged_qty') }} — {{ $line->product?->name() }}"
                                                   class="{{ $inputClass }}">
                                        </label>
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        <x-ui.field name="note"
                                    :label="$to === DeliveryStage::FAILED
                                        ? __('sales::delivery.field.reason_note')
                                        : __('sales::delivery.field.note')" />

                        <x-ui.button type="submit" tone="primary">
                            {{ __('sales::delivery.action.submit') }}
                        </x-ui.button>
                    </form>
                </details>
            @endforeach
        </div>
    @endif
</div>
