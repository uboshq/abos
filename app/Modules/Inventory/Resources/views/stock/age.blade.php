{{--
    স্টকের বয়স — কোন বয়স-ভাগে কত টাকা আটকে।

    আন্তর্জাতিক ধারা: প্রাপ্তির তারিখ থেকে বয়স, থাকে-থাকে (bucket), আর প্রতিটা
    থাকের সংখ্যাটা টাকা — পণ্য-গণনা নয়। ভিত্তি inv_cost_layers (FIFO স্তর),
    রিপোর্ট কেবল পড়ে। সবচেয়ে পুরনো স্তর আগে।
--}}
{{--
    ⭐ টাকা কেবল খরচ দেখার চাবিওয়ালার — Inventory অডিট ম১৪, ৫ অক্টোবর ২০২৬। চাবি না থাকলে
    [[StockFacts::agingValue()]] `null` দেয়; তখন ভাগের টাকা আর "আটকে থাকা টাকা" কলাম দুটোই সরে, বয়স আর পরিমাণ থাকে।
    ⛔ আগে চাবি ছাড়াও স্তরের টাকা দেখা যেত — টাকা ÷ পরিমাণ = কেনা দর।
--}}
@php $showsCost = \App\Core\Security\FieldSecurity::visible(\App\Modules\Inventory\Models\StockMovement::class, 'unit_cost'); @endphp
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('inventory::analysis.age_title') }}</x-slot:title>

    <div class="py-6">
        <h1 class="text-xl font-bold text-(--color-ink)">{{ __('inventory::analysis.age_title') }}</h1>
        <p class="mt-1 text-sm text-(--color-ink-muted)">{{ __('inventory::analysis.age_note') }}</p>

        {{-- বয়স-ভাগ: প্রতিটায় আটকে থাকা টাকা --}}
        <div class="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-4">
            @foreach ($buckets as $b)
                <a href="{{ route('inventory.stock.age', ['bucket' => $b]) }}"
                   class="rounded-(--radius-card) border px-4 py-3
                          {{ $b === $bucket
                             ? 'border-(--color-brand-600) bg-(--color-brand-50)'
                             : 'border-(--color-border) bg-(--color-surface-card)' }}">
                    <span class="block text-xs text-(--color-ink-muted)">
                        {{ $b }} {{ __('inventory::analysis.days', ['count' => '']) }}
                    </span>
                    @if ($showsCost && $totals[$b] !== null)
                        <span class="num block text-lg font-bold text-(--color-ink)">
                            ৳{{ \App\Core\Support\Money::format($totals[$b], 0) }}
                        </span>
                    @endif
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
                <table class="ui-list w-full text-sm">
                    <thead>
                        <tr class="border-b border-(--color-border) text-left text-(--color-ink-muted)">
                            <th >{{ __('inventory::analysis.product') }}</th>
                            <th >{{ __('inventory::analysis.received') }}</th>
                            <th class="text-right">{{ __('inventory::analysis.age_days_col') }}</th>
                            <th class="text-right">{{ __('inventory::analysis.quantity') }}</th>
                            @if ($showsCost)
                                <th class="text-right">{{ __('inventory::analysis.value_stuck') }}</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($layers as $l)
                            <tr class="border-b border-(--color-border)/60">
                                <td >
                                    <a href="{{ route('inventory.product.show', $l->product_id) }}"
                                       class="font-medium text-(--color-ink) hover:text-(--color-brand-600)">
                                        {{ app()->getLocale() === 'bn' && $l->name_bn ? $l->name_bn : $l->name_en }}
                                    </a>
                                    <span class="block text-2xs text-(--color-ink-muted)">{{ $l->product_code }}</span>
                                </td>
                                <td class="num text-(--color-ink-muted)">{{ $l->trx_date }}</td>
                                <td class="num text-right text-(--color-ink)">{{ (int) $l->age_days }}</td>
                                <td class="num text-right text-(--color-ink)">
                                    {{ rtrim(rtrim((string) $l->qty_remaining, '0'), '.') }}
                                </td>
                                @if ($showsCost)
                                    <td class="num text-right font-medium text-(--color-ink)">
                                        ৳{{ \App\Core\Support\Money::format($l->value_stuck) }}
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>
</x-layouts.app>
