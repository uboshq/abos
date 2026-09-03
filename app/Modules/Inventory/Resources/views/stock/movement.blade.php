{{--
    মরা · ধীর · দ্রুত চলা মাল — ড্যাশবোর্ডের সংখ্যার পেছনের তালিকা।

    তিনটা ট্যাব (দ্রুত/ধীর/মরা) আর তিনটা জানালা (৭/৩০/৯০ দিন)। সংখ্যাগুলো
    StockFacts থেকে, তালিকাও একই সংজ্ঞা থেকে — তাই ট্যাবে যা লেখা, তালিকায়
    ঠিক তাই। সবচেয়ে বেশি টাকা আটকে থাকা মাল আগে।
--}}
@php
    /*
     * "কত টাকা আটকে" কলামটা কেবল অনুমতিওয়ালার।
     *
     * ── এখানে ফাঁসটা সরাসরি নয়, তাই সহজে চোখ এড়ায় ───────────────────
     * এই পর্দায় ক্রয়মূল্য কোথাও ছাপা হয় না। কিন্তু আটকে থাকা মূল্য =
     * পরিমাণ × ক্রয়মূল্য, আর **পরিমাণটা পাশের কলামেই আছে** — অর্থাৎ
     * একটা ভাগ করলেই ক্রয়মূল্য বেরিয়ে আসে, হুবহু।
     *
     * ⚠️ চলাচলের এই পর্দা গুদামের লোকও খোলেন — খুলতেই হয়, কোন মাল
     * নড়ছে না সেটা তাঁরই জানার কথা। কিন্তু ওই মালে কত টাকা বসে আছে,
     * সেটা তাঁর প্রশ্ন নয়।
     *
     * ── কেন কলামটাই সরানো ───────────────────────────────────────────
     * শূন্য বা ড্যাশ দেখালে সারিটা থেকে যেত আর শিরোনামটাও — আর তখন
     * "এখানে একটা সংখ্যা আছে যা আমাকে দেখানো হচ্ছে না" কথাটাই খবর
     * হয়ে যেত। পণ্যের তালিকাতেও একই সিদ্ধান্ত (৩ সেপ্টেম্বর ২০২৬)।
     *
     * ⓘ ঘোষণাটা মডিউলের `sensitive_fields`-এ, এখানে নতুন কিছু নয় —
     * `Product::purchase_price → inventory.cost.view`।
     */
    $showsCost = \App\Core\Security\FieldSecurity::visible(
        \App\Modules\Inventory\Models\Product::class,
        'purchase_price',
    );
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('inventory::analysis.title') }}</x-slot:title>

    <div class="shell py-6">
        <h1 class="text-xl font-bold text-(--color-ink)">{{ __('inventory::analysis.title') }}</h1>

        {{-- জানালা: ৭ / ৩০ / ৯০ দিন --}}
        <div class="mt-4 flex flex-wrap items-center gap-2 text-sm">
            <span class="text-(--color-ink-muted)">{{ __('inventory::analysis.window') }}:</span>
            @foreach ($windows as $w)
                <a href="{{ route('inventory.stock.movement', ['type' => $type, 'days' => $w]) }}"
                   class="rounded-(--radius-field) border px-3 py-1 font-medium
                          {{ $w === $days
                             ? 'border-(--color-primary) bg-(--color-primary-bg) text-(--color-primary)'
                             : 'border-(--color-border) text-(--color-ink-soft)' }}">
                    {{ trans_choice('inventory::analysis.days', $w, ['count' => $w]) }}
                </a>
            @endforeach
        </div>

        {{-- ধরন: দ্রুত / ধীর / মরা — প্রতিটায় সংখ্যা --}}
        <div class="mt-3 flex flex-wrap gap-2">
            @foreach ($types as $t)
                <a href="{{ route('inventory.stock.movement', ['type' => $t, 'days' => $days]) }}"
                   class="flex items-center gap-2 rounded-(--radius-card) border px-4 py-2
                          {{ $t === $type
                             ? 'border-(--color-primary) bg-(--color-primary-bg)'
                             : 'border-(--color-border) bg-(--color-surface-card)' }}">
                    <span class="text-sm font-semibold text-(--color-ink)">
                        {{ __('inventory::analysis.'.$t) }}
                    </span>
                    <span class="num rounded-full bg-(--color-surface-sunken) px-2 text-sm font-bold text-(--color-ink)">
                        {{ $counts[$t] }}
                    </span>
                </a>
            @endforeach
        </div>

        {{-- তালিকা --}}
        <div class="mt-5 overflow-x-auto rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
            @if ($products->isEmpty())
                <p class="p-8 text-center text-(--color-ink-muted)">
                    {{ __('inventory::analysis.empty') }}
                </p>
            @else
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-(--color-border) text-left text-(--color-ink-muted)">
                            <th class="px-4 py-2.5">{{ __('inventory::analysis.product') }}</th>
                            <th class="px-4 py-2.5 text-right">{{ __('inventory::analysis.available') }}</th>
                            @if ($showsCost)
                                <th class="px-4 py-2.5 text-right">{{ __('inventory::analysis.value_stuck') }}</th>
                            @endif
                            <th class="px-4 py-2.5 text-right">
                                {{ $type === 'fast' ? __('inventory::analysis.sold') : __('inventory::analysis.moves') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($products as $p)
                            @php
                                $qty = (string) ($p->available_qty ?? '0');

                                // অনুমতি না থাকলে গোনাই হয় না — ঘরটা আঁকা হবে না
                                $value = $showsCost
                                    ? bcmul($qty, (string) ($p->purchase_price ?? '0'), 2)
                                    : null;
                            @endphp
                            <tr class="border-b border-(--color-border)/60">
                                <td class="px-4 py-2.5">
                                    <a href="{{ route('inventory.product.show', $p) }}"
                                       class="font-medium text-(--color-ink) hover:text-(--color-primary)">
                                        {{ $p->name() }}
                                    </a>
                                    <span class="block text-2xs text-(--color-ink-muted)">{{ $p->code }}</span>
                                </td>
                                <td class="num px-4 py-2.5 text-right text-(--color-ink)">
                                    {{ rtrim(rtrim($qty, '0'), '.') }} {{ $p->unit?->code }}
                                </td>
                                @if ($showsCost)
                                    <td class="num px-4 py-2.5 text-right text-(--color-ink)">
                                        ৳{{ number_format((float) $value, 2) }}
                                    </td>
                                @endif
                                <td class="num px-4 py-2.5 text-right text-(--color-ink-muted)">
                                    {{ $type === 'fast' ? ($p->sold_moves ?? 0) : ($p->touches ?? 0) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>
</x-layouts.app>
