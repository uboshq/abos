{{--
    স্টকের বয়স — কোন বয়স-ভাগে কত টাকা আটকে।

    আন্তর্জাতিক ধারা: প্রাপ্তির তারিখ থেকে বয়স, থাকে-থাকে (bucket), আর প্রতিটা
    থাকের সংখ্যাটা টাকা — পণ্য-গণনা নয়। ভিত্তি inv_cost_layers (FIFO স্তর),
    রিপোর্ট কেবল পড়ে। সবচেয়ে পুরনো স্তর আগে।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('inventory::analysis.age_title') }}</x-slot:title>

    <div class="shell py-6">
        <h1 class="text-xl font-bold text-(--color-ink)">{{ __('inventory::analysis.age_title') }}</h1>
        <p class="mt-1 text-sm text-(--color-ink-muted)">{{ __('inventory::analysis.age_note') }}</p>

        {{-- বয়স-ভাগ: প্রতিটায় আটকে থাকা টাকা --}}
        <div class="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-4">
            @foreach ($buckets as $b)
                <a href="{{ route('inventory.stock.age', ['bucket' => $b]) }}"
                   class="rounded-(--radius-card) border px-4 py-3
                          {{ $b === $bucket
                             ? 'border-(--color-primary) bg-(--color-primary-bg)'
                             : 'border-(--color-border) bg-(--color-surface-card)' }}">
                    <span class="block text-xs text-(--color-ink-muted)">
                        {{ $b }} {{ __('inventory::analysis.days', ['count' => '']) }}
                    </span>
                    <span class="num block text-lg font-bold text-(--color-ink)">
                        ৳{{ number_format((float) $totals[$b], 0) }}
                    </span>
                </a>
            @endforeach
        </div>

        {{-- বেছে নেওয়া ভাগের স্তর-তালিকা, সবচেয়ে পুরনো আগে --}}
        <div class="mt-5 overflow-x-auto rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
            @if ($layers->isEmpty())
                <p class="p-8 text-center text-(--color-ink-muted)">
                    {{ __('inventory::analysis.age_empty') }}
                </p>
            @else
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-(--color-border) text-left text-(--color-ink-muted)">
                            <th class="px-4 py-2.5">{{ __('inventory::analysis.product') }}</th>
                            <th class="px-4 py-2.5">{{ __('inventory::analysis.received') }}</th>
                            <th class="px-4 py-2.5 text-right">{{ __('inventory::analysis.age_days_col') }}</th>
                            <th class="px-4 py-2.5 text-right">{{ __('inventory::analysis.quantity') }}</th>
                            <th class="px-4 py-2.5 text-right">{{ __('inventory::analysis.value_stuck') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($layers as $l)
                            <tr class="border-b border-(--color-border)/60">
                                <td class="px-4 py-2.5">
                                    <a href="{{ route('inventory.product.show', $l->product_id) }}"
                                       class="font-medium text-(--color-ink) hover:text-(--color-primary)">
                                        {{ app()->getLocale() === 'bn' && $l->name_bn ? $l->name_bn : $l->name_en }}
                                    </a>
                                    <span class="block text-2xs text-(--color-ink-muted)">{{ $l->product_code }}</span>
                                </td>
                                <td class="num px-4 py-2.5 text-(--color-ink-soft)">{{ $l->trx_date }}</td>
                                <td class="num px-4 py-2.5 text-right text-(--color-ink)">{{ (int) $l->age_days }}</td>
                                <td class="num px-4 py-2.5 text-right text-(--color-ink)">
                                    {{ rtrim(rtrim((string) $l->qty_remaining, '0'), '.') }}
                                </td>
                                <td class="num px-4 py-2.5 text-right font-medium text-(--color-ink)">
                                    ৳{{ number_format((float) $l->value_stuck, 2) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>
</x-layouts.app>
