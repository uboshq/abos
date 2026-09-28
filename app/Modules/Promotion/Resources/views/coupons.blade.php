{{--
    কুপনের পাতা — একটা অফারের কোড (আর তৈরির ফর্ম), নাহলে সব অফারের কোড।

    ⓘ কোডগুলো [[CouponDesk]] বানায়; এই পাতা কেবল জিজ্ঞেস করে কয়টা, কতবার,
    কার জন্য, কবে পর্যন্ত। ⚠️ নিয়মগুলো (কোডের আকার, মেয়াদ অফারের ভিতরে)
    ডেস্কেই — এখানে আবার লেখা নয়।

    ⛔ এই পাতায় কোনো `@php` ব্লক নেই — তালিকার পাতায় `@php`-র ভিতরে একটা
    Blade মন্তব্য গোটা পাতাটা ৫০০ করেছিল। আর কম্পোনেন্টের কোনো গুণে `"`
    নেই — থাকলে Blade পুরো ট্যাগটা লেখা হিসেবে ছেপে দেয়, নীরবে।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('promotion::coupon.title') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$offer ? __('promotion::coupon.title').' · '.$offer->code : __('promotion::coupon_screen.all_offers')">
            @if ($offer)
                <x-slot:actions>
                    <x-ui.button :href="route('promotion.show', $offer)" tone="secondary">{{ __('promotion::coupon_screen.back_to_offer') }}</x-ui.button>
                </x-slot:actions>
            @endif
        </x-ui.page-header>
    </x-slot:header>

    <x-ui.errors />

    @if (session('saved'))
        <p role="status" class="mb-3 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2
                                text-sm text-(--color-badge-success-ink)">{{ session('saved') }}</p>
    @endif

    <div class="space-y-4">
        {{-- ⭐ তৈরির ফর্ম — কেবল যখন সত্যিই সম্ভব; নাহলে কারণটা, বোতাম নয় --}}
        @if ($offer)
            <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                <h2 class="mb-3 text-sm font-semibold">{{ __('promotion::coupon_screen.issue_heading') }}</h2>

                @if ($canIssue)
                    <form method="POST" action="{{ route('promotion.coupon.store', $offer) }}" class="grid gap-3 sm:grid-cols-4">
                        @csrf
                        <x-ui.field name="code" :label="__('promotion::coupon.code')"
                                    :hint="__('promotion::coupon_screen.code_hint')" />
                        <x-ui.field name="count" type="number" :value="1" :label="__('promotion::coupon.count')"
                                    :hint="__('promotion::coupon_screen.count_hint', ['max' => $maxBatch])" />
                        <x-ui.field name="max_uses" type="number" :value="1" :label="__('promotion::coupon.max_uses')" required />
                        <x-ui.field name="max_uses_per_customer" type="number" :label="__('promotion::coupon.max_uses_per_customer')" />
                        <x-ui.field name="customer_id" type="number" :label="__('promotion::coupon.customer')"
                                    :hint="__('promotion::coupon_screen.customer_hint')" />
                        <x-ui.field name="valid_from" type="date" :label="__('promotion::coupon.valid_from')"
                                    :hint="__('promotion::coupon_screen.dates_hint')" />
                        <x-ui.field name="valid_to" type="date" :label="__('promotion::coupon.valid_to')" />
                        <div class="flex items-end">
                            <x-ui.button type="submit" tone="primary">{{ __('promotion::coupon.issue') }}</x-ui.button>
                        </div>
                    </form>
                @elseif ($offer->status->isFinal())
                    <p class="text-sm text-(--color-ink-muted)">{{ __('promotion::coupon_screen.offer_final', ['code' => $offer->code]) }}</p>
                @else
                    <p class="text-sm text-(--color-ink-muted)">{{ __('promotion::coupon_screen.not_coupon_type', ['code' => $offer->code]) }}</p>
                @endif
            </section>
        @else
            <p class="text-sm text-(--color-ink-muted)">{{ __('promotion::coupon_screen.pick_offer') }}</p>
        @endif

        {{-- ⓘ খোঁজা GET-এ, আর অফারটা সাথে যায় — নাহলে খুঁজতেই পাতাটা সব অফারে ছড়িয়ে পড়ত --}}
        <form method="GET" action="{{ route('promotion.coupon.index') }}" class="flex flex-wrap items-end gap-3">
            @if ($offer)
                <input type="hidden" name="offer" value="{{ $offer->id }}">
            @endif
            <x-ui.field name="q" :value="$search" :label="__('promotion::coupon_screen.search')" />
            <x-ui.button type="submit" tone="secondary">{{ __('promotion::coupon_screen.search_button') }}</x-ui.button>
        </form>

        @if ($rows->isEmpty())
            <p class="text-sm text-(--color-ink-muted)">{{ __('promotion::coupon_screen.none') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="ui-grid">
                    <thead class="bg-(--color-surface-sunken) text-2xs text-(--color-ink-muted)">
                        <tr>
                            <th class="text-start">{{ __('promotion::coupon.code') }}</th>
                            @unless ($offer)
                                <th class="text-start">{{ __('promotion::coupon_screen.offer') }}</th>
                            @endunless
                            <th class="text-end">{{ __('promotion::coupon.used_count') }}</th>
                            <th class="text-end">{{ __('promotion::coupon.max_uses') }}</th>
                            <th class="text-end">{{ __('promotion::coupon.uses_left') }}</th>
                            <th class="text-end">{{ __('promotion::coupon.max_uses_per_customer') }}</th>
                            <th class="text-start">{{ __('promotion::coupon.customer') }}</th>
                            <th class="text-start">{{ __('promotion::coupon.valid_from') }} — {{ __('promotion::coupon.valid_to') }}</th>
                            <th class="text-start">{{ __('promotion::coupon.is_active') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr class="border-t border-(--color-border)">
                                <td class="font-mono">{{ $row->code }}</td>
                                @unless ($offer)
                                    <td>
                                        @if ($row->promotion)
                                            <a class="underline" href="{{ route('promotion.coupon.index', ['offer' => $row->promotion_id]) }}">{{ $row->promotion->code }}</a>
                                        @else
                                            —
                                        @endif
                                    </td>
                                @endunless
                                <td class="num">{{ $row->used_count }}</td>
                                <td class="num">{{ $row->max_uses }}</td>
                                {{-- ⚠️ বাকি শূন্য হলে লাল — কাউন্টার এই কোড আর খাটাতে পারবে না --}}
                                <td class="num">
                                    <x-ui.badge :tone="$row->usesLeft() > 0 ? 'info' : 'danger'">{{ $row->usesLeft() }}</x-ui.badge>
                                </td>
                                <td class="num">{{ $row->max_uses_per_customer ?? __('promotion::coupon_screen.no_limit') }}</td>
                                <td>{{ $row->customer?->name() ?? __('promotion::coupon_screen.any_customer') }}</td>
                                <td>
                                    @if ($row->valid_from || $row->valid_to)
                                        {{ $row->valid_from?->format('d/m/y') ?? '…' }} — {{ $row->valid_to?->format('d/m/y') ?? '…' }}
                                    @else
                                        {{ __('promotion::coupon_screen.whole_offer') }}
                                    @endif
                                </td>
                                <td>
                                    @if ($row->is_active)
                                        <x-ui.badge tone="success">{{ __('promotion::coupon.is_active') }}</x-ui.badge>
                                    @else
                                        <x-ui.badge tone="draft">{{ __('promotion::coupon_screen.off') }}</x-ui.badge>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <x-ui.pager :rows="$rows" />
    </div>
</x-layouts.app>
