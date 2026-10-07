{{--
    উপহার দেওয়া — যে উপহারগুলো বিলে লেখা হয়েছে কিন্তু গুদাম থেকে এখনো
    পুরো বেরোয়নি (স্পেক §৮)।

    ⓘ প্রতিটা সারিতে নিজের ফর্ম: কোন গুদাম, কোন লট, কত। ⚠️ পণ্যটা ফর্মে
    নেই — অফারের ধাপ যা বলে সেটাই যায়, হাতে বদলানো যায় না।

    ⚠️ এই পাতায় কোনো `@php` ব্লক নেই — তালিকার পাতায় `@php`-র ভিতরে একটা
    Blade মন্তব্য গোটা পাতাটা ৫০০ করেছিল।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('promotion::menu.gifts') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('promotion::menu.gifts')" />
    </x-slot:header>

    <x-ui.errors />

    <div class="space-y-3">
        @forelse ($rows as $row)
            <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2 text-sm">
                    <span>
                        <a class="underline" href="{{ route('promotion.show', $row->promotion) }}">{{ $row->promotion->code }}</a>
                        · {{ $row->promotion->name() }}
                        · {{ $row->source_type }} #{{ $row->source_id }}
                    </span>
                    <span class="tabular-nums">
                        {{ $row->benefit?->giftProduct?->name() ?? '—' }}:
                        {{ __('promotion::field.issued') }} {{ \App\Core\Support\Money::quantity($row->issued_qty ?? '0') }} / {{ __('promotion::field.owed') }} {{ $row->benefit_amount }}
                    </span>
                </div>

                @if ($row->benefit?->giftProduct)
                    <form method="POST" action="{{ route('promotion.gift.store', $row) }}" class="grid gap-3 sm:grid-cols-4">
                        @csrf
                        <x-ui.select name="warehouse_id" :label="__('promotion::field.warehouse')"
                                     :options="$warehouses->mapWithKeys(fn ($w) => [$w->id => $w->code.' · '.$w->name()])"
                                     required />

                        {{-- ⓘ লট কেবল যে পণ্য লট রাখে তার — না রাখলে ঘরটাই নেই, খালি তালিকা দেখিয়ে বিভ্রান্ত করা নয় --}}
                        @if ($row->benefit->giftProduct->track_batch)
                            <x-ui.select name="batch_id" :label="__('promotion::field.lot')"
                                         :options="($lots[$row->benefit->gift_product_id] ?? collect())->mapWithKeys(fn ($b) => [$b->id => $b->label()])"
                                         required />
                        @endif

                        {{-- ⓘ সিরিয়াল-রাখা পণ্যে প্রতিটা পিসের নম্বর, এক লাইনে একটা — GiftIssuer মিলিয়ে দেখে --}}
                        @if ($row->benefit->giftProduct->track_serial)
                            <label class="block sm:col-span-4">
                                <span class="mb-1 block text-2xs uppercase tracking-wide text-(--color-ink-muted)">{{ __('promotion::field.serials') }}</span>
                                <textarea name="serials_text" rows="2"
                                          class="w-full rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-2 py-1.5 text-sm"></textarea>
                            </label>
                        @endif

                        <x-ui.field name="qty" type="number" step="0.0001"
                                    :value="bcsub((string) $row->benefit_amount, (string) ($row->issued_qty ?? '0'), 4)"
                                    :label="__('promotion::field.qty')" required />
                        <div class="flex items-end">
                            <x-ui.button type="submit" tone="primary">{{ __('promotion::action.issue_gift') }}</x-ui.button>
                        </div>
                    </form>
                @else
                    {{-- ⚠️ ধাপে উপহার-পণ্য নেই — দেওয়ার বোতাম দেখানো নয়, কারণটা বলা --}}
                    <p class="text-sm text-(--color-ink-muted)">{{ __('promotion::validation.gift_needs_product') }}</p>
                @endif
            </section>
        @empty
            <p class="text-sm text-(--color-ink-muted)">{{ __('promotion::message.no_gifts_owed') }}</p>
        @endforelse

        <x-ui.pager :rows="$rows" />
        <x-ui.list-totals :rows="$rows" />
    </div>
</x-layouts.app>
