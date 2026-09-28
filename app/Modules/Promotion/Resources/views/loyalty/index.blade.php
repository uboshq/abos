{{--
    ক্রেতার পয়েন্ট — যাঁদের খাতায় পয়েন্ট আছে (স্পেক §৭-ঠ, §১৩)।

    ⭐ কেবল পড়া: ⓘ কোনো ফর্ম, কোনো বোতাম নেই যা পয়েন্ট বদলায় — পয়েন্ট
    আসে অফার থেকে, যায় বিলে, আর কেবল [[LoyaltyLedger]] লেখে।

    ⛔ ব্যালান্সটা পর্দায় গোনা নয় — `$balances` কন্ট্রোলার খাতা থেকে এনেছে,
    মেয়াদ ধরে। ⚠️ এখানে `points` যোগ করলে ফুরোনো পয়েন্টও দেখাত।

    ⚠️ এই পাতায় কোনো `@php` ব্লক নেই — [[gifts]] পাতার একই নিয়ম।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('promotion::loyalty_screen.title') }}</x-slot:title>

    <div class="space-y-4">
        <x-ui.toolbar :title="__('promotion::loyalty_screen.title')"
                      :search-placeholder="__('promotion::loyalty_screen.search')"
                      :filter="false" :export="false" :share="false" />

        <p class="text-sm text-(--color-ink-muted)">{{ __('promotion::loyalty_screen.balance_note') }}</p>

        @if ($rows->isEmpty())
            <p class="text-sm text-(--color-ink-muted)">{{ __('promotion::loyalty_screen.none') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="ui-grid">
                    <thead class="bg-(--color-surface-sunken) text-2xs text-(--color-ink-muted)">
                        <tr>
                            <th class="text-start">{{ __('core.table.serial') }}</th>
                            <th class="text-start">{{ __('promotion::loyalty_screen.code') }}</th>
                            <th class="text-start">{{ __('promotion::loyalty_screen.customer') }}</th>
                            <th class="text-end">{{ __('promotion::loyalty_screen.balance') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $customer)
                            <tr class="border-t border-(--color-border)">
                                <td class="num">{{ $rows->firstItem() + $loop->index }}</td>
                                <td>
                                    <a class="underline" href="{{ route('promotion.loyalty.show', $customer) }}">{{ $customer->code }}</a>
                                </td>
                                <td>{{ $customer->name() }}</td>
                                {{-- ⚠️ ঋণাত্মক হতে পারে: খরচ করা পয়েন্টের বিল বাতিল হলে — লুকানো নয়, লাল --}}
                                <td @class(['num tabular-nums', 'text-(--color-danger)' => str_starts_with($balances[$customer->id] ?? '0', '-')])>
                                    {{ $balances[$customer->id] ?? '0' }}
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
