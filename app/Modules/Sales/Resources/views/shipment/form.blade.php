{{--
    শিপমেন্ট — তৈরি ও সম্পাদনা।

    ── কেন এখানে লাইন এডিটর নয় ─────────────────────────────────────────
    ট্রিপে নতুন কিছু লেখা হয় না; যা আছে তার থেকে বাছা হয়। গুদামের লোক
    সকালে কাগজগুলো হাতে নিয়ে দাঁড়ান আর একটা একটা করে টিক দেন — তাই
    ঘরটাও ঠিক তাই: চেকবক্সের একটা তালিকা, প্রতিটাতে নম্বর, ক্রেতা ও
    টাকা, যাতে হাতের কাগজটার সাথে মিলিয়ে নেওয়া যায়।

    সম্পাদনা কেবল খসড়ায় — গাড়ি বেরিয়ে যাওয়ার পর কাগজে যা লেখা ছিল
    সেটাই সত্য।
--}}
@php
    $isNew = ! $shipment->exists;

    $chosen = collect(old('challans', $loaded->pluck('id')->all()))
        ->map(fn ($id) => (int) $id)->all();

    /*
     * সম্পাদনার সময় গাড়িতে থাকা চালানগুলোও তালিকায় থাকতে হবে।
     *
     * `formData()` কেবল মুক্ত চালান দেয়, আর এই ট্রিপে তোলা চালানগুলো
     * ওই হিসাবে মুক্ত নয় — বাদ দিলে সম্পাদনার পর্দা খুলে দেখা যেত
     * টিকগুলো উধাও, আর সেভ করলেই গাড়ি খালি হয়ে যেত।
     */
    $pickable = $challans->concat($loaded)->unique('id')
        ->sortByDesc(fn ($c) => [$c->trx_date?->toDateString(), $c->id]);
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $isNew ? __('sales::shipment.new') : $shipment->document_no }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header
            :title="$isNew ? __('sales::shipment.new') : $shipment->document_no"
            :subtitle="__('sales::shipment.subtitle')" />
    </x-slot:header>

    <form method="POST"
          action="{{ $isNew ? route('sales.shipment.store') : route('sales.shipment.update', $shipment) }}"
          class="space-y-4">
        @csrf
        @unless ($isNew) @method('PUT') @endunless

        @if ($errors->any())
            <div role="alert"
                 class="rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm
                        text-(--color-badge-danger-ink)">
                <ul class="list-inside list-disc">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <section class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <x-ui.field name="trx_date" type="date" :label="__('sales::field.date')"
                            :value="old('trx_date', $shipment->trx_date?->toDateString() ?? now()->toDateString())"
                            required />

                <x-ui.select name="warehouse_id" :label="__('sales::shipment.warehouse')"
                             :options="$warehouses->mapWithKeys(fn ($w) => [$w->id => $w->name()])"
                             :selected="old('warehouse_id', $shipment->warehouse_id)"
                             placeholder="-" required />

                @if ($vehicles->isNotEmpty())
                    <x-ui.select name="vehicle_id" :label="__('sales::shipment.vehicle')"
                                 :options="$vehicles->mapWithKeys(fn ($v) => [$v->id => $v->registration_no . ' — ' . $v->name()])"
                                 :selected="old('vehicle_id', $shipment->vehicle_id)"
                                 :placeholder="__('sales::field.vehicle_not_in_fleet')"
                                 :hint="__('sales::shipment.vehicle_hint')" />
                @endif

                <x-ui.field name="vehicle_no" :label="__('sales::shipment.vehicle_no')"
                            :value="old('vehicle_no', $shipment->vehicle_no)" />


                <x-ui.field name="driver_name" :label="__('sales::shipment.driver_name')"
                            :value="old('driver_name', $shipment->driver_name)" />

                <x-ui.field name="helper_name" :label="__('sales::shipment.helper')"
                            :value="old('helper_name', $shipment->helper_name)" />

                @if ($routes->isNotEmpty())
                    <x-ui.select name="route_location_id" :label="__('sales::shipment.route')"
                                 :options="$routes->mapWithKeys(fn ($r) => [$r->id => $r->name()])"
                                 :selected="old('route_location_id', $shipment->route_location_id)"
                                 placeholder="-" />
                @endif

                <x-ui.field name="opening_km" type="number" step="0.01"
                            :label="__('sales::shipment.opening_km')"
                            :value="old('opening_km', $shipment->opening_km)" />
            </div>

            <div class="mt-3">
                <x-ui.field name="narration" :label="__('sales::field.narration')"
                            :value="old('narration', $shipment->narration)" />
            </div>
        </section>

        <section class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="font-semibold">{{ __('sales::shipment.challans') }}</h2>
            <p class="mt-1 text-xs text-(--color-ink-muted)">{{ __('sales::shipment.challans_hint') }}</p>

            @if ($pickable->isEmpty())
                <p class="mt-3 text-sm text-(--color-ink-muted)">{{ __('sales::shipment.no_free_challans') }}</p>
            @else
                <div class="mt-3 max-h-96 overflow-y-auto rounded-(--radius-field) border border-(--color-border)">
                    @foreach ($pickable as $challan)
                        <label class="flex min-h-(--spacing-touch) items-center gap-3 border-b border-(--color-border)
                                      px-3 py-2 text-sm last:border-b-0 hover:bg-(--color-surface-hover)">
                            <input type="checkbox" name="challans[]" value="{{ $challan->id }}"
                                   @checked(in_array($challan->id, $chosen, true)) class="size-4">

                            <span class="w-40 shrink-0 font-medium">{{ $challan->document_no }}</span>
                            <span class="min-w-0 flex-1 truncate">{{ $challan->customer?->name() }}</span>
                            <span class="tabular w-28 shrink-0 text-end">
                                {{ \App\Core\Support\Money::format($challan->total) }}
                            </span>
                            <span class="w-24 shrink-0 text-end text-xs text-(--color-ink-muted)">
                                {{ \App\Core\Support\DateFormat::format($challan->trx_date) }}
                            </span>
                        </label>
                    @endforeach
                </div>
            @endif
        </section>

        <div class="flex flex-wrap gap-2">
            <x-ui.button type="submit" tone="primary">{{ __('core.action.save') }}</x-ui.button>
            <x-ui.button tone="secondary" :href="route('sales.shipment.index')">
                {{ __('core.action.cancel') }}
            </x-ui.button>
        </div>
    </form>
</x-layouts.app>
