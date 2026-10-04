{{--
    উদ্ধৃতির তুলনা — দুই থেকে ছয়টা উদ্ধৃতি পাশাপাশি, সারি ধরে ([[QuotationComparison]])।

    ⭐ মালিকের আন্তর্জাতিক পরিকল্পনা, ৪ অক্টোবর ২০২৬ — মেনুর "উদ্ধৃতির তুলনা" এখন এই পাতা।
    ⓘ প্রথম কলাম ভিত্তি; ভিত্তি থেকে আলাদা ঘর হলুদ (`data-changed`), ভিত্তিতে ছিল না এমন সারি সবুজ (`data-added`),
    এই উদ্ধৃতিতে নেই এমন সারি লাল (`data-missing`)।

    ⓘ মালিকের পর্দা ডান দিক কাটে (১ অক্টোবর ২০২৬): প্রতিটা ঘরে একটা করে লাইন — পরিমাণ, দর, ছাড়, ভ্যাট, মোট
    একটার নিচে আরেকটা, পাশাপাশি পাঁচ কলাম নয়। ⚠️ ছয় কলামের বেশি নয় ([[QuotationComparison::MAX]]); ফোনের চওড়ায় বাক্সটা
    নিজে পাশে সরে, পাতা নয়।
--}}
@php
    $changedClass = 'rounded-(--radius-field) bg-(--color-badge-warning-bg) px-1 text-(--color-badge-warning-ink)';
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('sales::quotation.compare.title') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('sales::quotation.compare.title')" :subtitle="__('sales::quotation.compare.note')" />
    </x-slot:header>

    @if ($comparison === null)
        {{-- ⓘ বাছার পাতা — খুঁজে টিক দিন, তারপর তুলনা --}}
        <section data-boxed data-compare-picker
                 class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
            <h2 class="border-b border-(--color-border) bg-(--color-section-head) px-4 py-3 font-semibold">
                {{ __('sales::quotation.compare.pick') }}
            </h2>

            <form method="GET" action="{{ route('sales.quotation.compare') }}" class="flex flex-wrap items-end gap-2 border-b border-(--color-border) p-4">
                <x-ui.field name="q" :label="__('sales::quotation.search')" :value="$q" />
                <x-ui.button type="submit" tone="secondary">{{ __('core.action.search') }}</x-ui.button>
            </form>

            <form method="GET" action="{{ route('sales.quotation.compare') }}">
                @if ($picker->isEmpty())
                    <p class="p-4 text-sm text-(--color-ink-muted)">{{ __('sales::quotation.compare.pick_empty') }}</p>
                @else
                    <ul class="divide-y divide-(--color-border)">
                        @foreach ($picker as $option)
                            <li class="px-4 py-2 text-sm">
                                <label class="flex min-h-(--spacing-touch) items-start gap-3">
                                    <input type="checkbox" name="ids[]" value="{{ $option->id }}" class="mt-1 size-4"
                                           @checked(in_array($option->id, $chosen, true))>
                                    <span>
                                        <span class="block font-semibold">{{ $option->document_no }}</span>
                                        <span class="block">{{ $option->customer?->name() }}</span>
                                        <span class="block text-2xs text-(--color-ink-muted)">
                                            {{ \App\Core\Support\DateFormat::format($option->trx_date) }} · {{ \App\Core\Support\Money::format($option->total) }}
                                        </span>
                                        <span class="block">@include('sales::quotation.partials.status', ['quotation' => $option])</span>
                                    </span>
                                </label>
                            </li>
                        @endforeach
                    </ul>

                    <div class="border-t border-(--color-border) p-4">
                        <x-ui.button type="submit" tone="primary">{{ __('sales::quotation.compare.go') }}</x-ui.button>
                    </div>
                @endif
            </form>
        </section>
    @else
        <section data-boxed data-comparison
                 class="overflow-x-auto rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
            <table class="w-full text-sm">
                <thead class="bg-(--color-section-head)">
                    <tr>
                        <th scope="col" class="w-64 px-4 py-3 text-start font-semibold"></th>
                        @foreach ($quotations as $i => $quotation)
                            <th scope="col" class="min-w-56 px-4 py-3 text-start align-top font-semibold" data-column="{{ $quotation->id }}">
                                <span class="block">@include('sales::components.doc-link', ['document' => $quotation, 'route' => 'sales.quotation.show'])</span>
                                <span class="block font-normal">{{ $quotation->customer?->name() }}</span>
                                @if ($i === 0)
                                    <span class="block text-2xs font-normal text-(--color-ink-muted)">{{ __('sales::quotation.compare.base') }}</span>
                                @endif
                            </th>
                        @endforeach
                    </tr>
                </thead>

                <tbody class="divide-y divide-(--color-border)">
                    <tr><th colspan="{{ $quotations->count() + 1 }}" class="bg-(--color-surface-sunken) px-4 py-2 text-start text-xs font-semibold">{{ __('sales::quotation.compare.heading') }}</th></tr>

                    @foreach ($comparison['head'] as $row)
                        <tr>
                            <th scope="row" class="px-4 py-2 text-start font-normal text-(--color-ink-muted)">{{ $row['label'] }}</th>
                            @foreach ($row['values'] as $i => $value)
                                <td class="px-4 py-2 align-top">
                                    <span @if ($row['changed'][$i]) data-changed class="{{ $changedClass }}" title="{{ __('sales::quotation.compare.changed') }}" @endif>{{ $value }}</span>
                                </td>
                            @endforeach
                        </tr>
                    @endforeach

                    <tr><th colspan="{{ $quotations->count() + 1 }}" class="bg-(--color-surface-sunken) px-4 py-2 text-start text-xs font-semibold">{{ __('sales::quotation.compare.lines') }}</th></tr>

                    @foreach ($comparison['lines'] as $line)
                        <tr data-line>
                            <th scope="row" class="px-4 py-2 text-start align-top font-normal">{{ $line['label'] }}</th>
                            @foreach ($line['cells'] as $i => $cell)
                                @if ($cell === null)
                                    <td data-missing class="bg-(--color-badge-danger-bg) px-4 py-2 align-top text-(--color-badge-danger-ink)">
                                        — {{ __('sales::quotation.compare.missing') }}
                                    </td>
                                @else
                                    <td @if ($cell['added']) data-added title="{{ __('sales::quotation.compare.added') }}" @endif
                                        @class(['px-4 py-2 align-top', 'bg-(--color-badge-success-bg) text-(--color-badge-success-ink)' => $cell['added']])>
                                        @foreach (\App\Modules\Sales\Support\QuotationComparison::LINE_FIELDS as $field)
                                            <span class="block">
                                                <span class="text-(--color-ink-muted)">{{ __('sales::quotation.compare.field.'.$field) }}:</span>
                                                <span @if ($cell['changed'][$field]) data-changed="{{ $field }}" class="{{ $changedClass }}" title="{{ __('sales::quotation.compare.changed') }}" @endif>
                                                    {{ $cell['fields'][$field] }}@if ($field === 'qty' && $cell['unit'] !== '') {{ $cell['unit'] }}@endif
                                                </span>
                                            </span>
                                        @endforeach
                                    </td>
                                @endif
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @endif
</x-layouts.app>
