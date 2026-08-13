{{--
    Z-রিপোর্ট — একটা বন্ধ শিফটের হিসাব।

    ── পার্থক্যটা লুকানো হয় না ──────────────────────────────────────────
    কম পড়লে লাল, বেশি হলে অ্যাম্বার, মিললে সবুজ। ঘাটতিটা ছোট করে
    দেখালে বা "সমন্বয়" নামে চেপে দিলে ব্যবস্থাটার একমাত্র কাজটাই বাদ
    যেত: প্রশ্নটা তোলা।

    বেশি থাকাও সমস্যা, আর সেটা কম লোকে ভাবে — বেশি মানে সাধারণত একটা
    আদায় কোথাও লেখা হয়নি, অর্থাৎ কোনো গ্রাহকের খাতায় টাকাটা বসেনি।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('sales::menu.shift') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('sales::menu.shift')"
                          :subtitle="$shift->till?->name().' · '.$shift->user?->name" />
    </x-slot:header>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    <div class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
        <p class="mb-4 text-sm text-(--color-ink-muted)">
            {{ \App\Core\Support\DateFormat::format($shift->opened_at) }}
            · {{ $shift->opened_at?->format('H:i') }}–{{ $shift->closed_at?->format('H:i') }}
            · {{ __('sales::message.shift_bills') }}: <span class="num">{{ $figures['bills'] }}</span>
        </p>

        <table class="w-full text-sm">
            <tbody>
                @foreach ([
                    'shift_opening' => $figures['opening'],
                    'shift_cash_in' => $figures['cash_in'],
                    'shift_cash_out' => $figures['cash_out'],
                ] as $label => $value)
                    <tr class="border-b border-(--color-border)">
                        <td class="py-2">{{ __('sales::message.'.$label) }}</td>
                        <td class="num py-2 text-end">{{ \App\Core\Support\Money::format($value) }}</td>
                    </tr>
                @endforeach

                <tr class="border-b border-(--color-border) font-medium">
                    <td class="py-2">{{ __('sales::message.shift_expected') }}</td>
                    <td class="num py-2 text-end">{{ \App\Core\Support\Money::format($figures['expected']) }}</td>
                </tr>

                <tr class="border-b border-(--color-border) font-medium">
                    <td class="py-2">{{ __('sales::message.shift_counted') }}</td>
                    <td class="num py-2 text-end">{{ \App\Core\Support\Money::format($figures['counted']) }}</td>
                </tr>
            </tbody>
        </table>

        @php
            // ঋণাত্মক = কম, ধনাত্মক = বেশি, শূন্য = মিলেছে
            $sign = bccomp((string) $figures['difference'], '0', 4);

            $tone = match (true) {
                $sign < 0 => ['bg' => 'danger', 'note' => 'shift_short'],
                $sign > 0 => ['bg' => 'pending', 'note' => 'shift_over'],
                default => ['bg' => 'success', 'note' => 'shift_matched'],
            };
        @endphp

        <div class="mt-4 rounded-(--radius-field) bg-(--color-badge-{{ $tone['bg'] }}-bg) px-3 py-3
                    text-(--color-badge-{{ $tone['bg'] }}-ink)">
            <div class="flex items-baseline justify-between">
                <span class="font-medium">{{ __('sales::message.shift_difference') }}</span>
                <span class="num text-xl font-semibold">
                    {{ \App\Core\Support\Money::format($figures['difference']) }}
                </span>
            </div>

            <p class="mt-1 text-2xs">{{ __('sales::message.'.$tone['note']) }}</p>
        </div>

        @if ($shift->narration)
            <p class="mt-3 text-sm text-(--color-ink-muted)">{{ $shift->narration }}</p>
        @endif
    </div>
</x-layouts.app>
