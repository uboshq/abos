{{--
    নিট সম্পদ বিবরণী (Net Worth Statement) — শুধু মালিকের এক নজর (মালিক, ২৫ অক্টোবর ২০২৬; নমুনা
    "নিট-সম্পদ-বিবরণী-নমুনা-২৫-অক্টোবর.pdf")।

    ── কেন এমন সাজ ─────────────────────────────────────────────────────
    বড় সবুজ অঙ্কটাই প্রশ্নের উত্তর: সব বিক্রি-আদায় করে সব দেনা শোধ করলে কী থাকে। নিচে চারটা ঘর তার উৎস, আর দুই পাশে হিসাব আর
    মিল — বাঁয়ে সম্পদ − দায়, ডানে মূলধন − উত্তোলন + লাভ; দুটো একই অঙ্ক না হলে খাতায় ভুল, সেটা লাল করে উপরে।
    ⓘ সাজ কেবল স্থিতিপত্রের পাতার চেনা class দিয়ে — নতুন CSS বানাতে হয় না।
--}}
@php
    $money = fn ($v) => \App\Core\Support\Money::format($v);
    $tiles = [
        'cash' => __('accounts::field.nw_cash'),
        'receivable' => __('accounts::field.nw_receivable'),
        'stock' => __('accounts::field.nw_stock'),
        'other_assets' => __('accounts::field.nw_other_assets'),
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('accounts::field.nw_title') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('accounts::field.nw_title')"
                          :subtitle="__('accounts::field.nw_subtitle', ['date' => \App\Core\Support\DateFormat::format($asOf)])">
            <x-slot:actions>
                <x-ui.button tone="secondary" icon="print" data-action="print">
                    {{ __('core.action.print') }}
                </x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>

    {{-- ── কোম্পানি বাছা ─────────────────────────────────────────── --}}
    <nav class="mb-4 flex flex-wrap items-end gap-3 print-hide" aria-label="{{ __('accounts::field.nw_companies') }}" data-net-worth-companies>
        <x-ui.button :tone="$picked === null ? 'primary' : 'secondary'" :href="route('accounts.net_worth')">
            {{ __('accounts::field.nw_all_companies') }}
        </x-ui.button>
        @foreach ($companies as $c)
            <x-ui.button :tone="$picked === $c['code'] ? 'primary' : 'secondary'"
                         :href="route('accounts.net_worth', ['company' => $c['code']])">
                {{ $c['code'] }}
            </x-ui.button>
        @endforeach
    </nav>

    {{-- ── উত্তর: নিট সম্পদ, আর দুই দিক মেলে কি না ─────────────────── --}}
    <section data-boxed data-net-worth
             @class([
                 'mb-4 flex flex-wrap items-center gap-3 rounded-(--radius-card) border px-4 py-3',
                 'border-(--color-border) bg-(--color-badge-success-bg)' => $agrees,
                 'border-(--color-danger) bg-(--color-badge-danger-bg)' => ! $agrees,
             ])>
        <span @class(['font-semibold',
            'text-(--color-badge-success-ink)' => $agrees,
            'text-(--color-badge-danger-ink)' => ! $agrees])>
            {{ __('accounts::field.nw_net') }}
        </span>
        <span class="flex-1"></span>
        <strong class="text-3xl tabular-nums" data-net>{{ $money($figures['net']) }}</strong>
        <span class="text-sm tabular-nums">
            {{ __('accounts::field.nw_this_year') }} <strong>{{ $money($figures['this_year']) }}</strong>
            · {{ $agrees ? __('accounts::field.nw_agrees') : __('accounts::field.nw_does_not_agree') }}
        </span>
    </section>

    {{-- ── চারটা ঘর: সম্পদ কোথায় ─────────────────────────────────── --}}
    <div class="mb-4 grid gap-4 lg:grid-cols-2">
        @foreach ($tiles as $key => $label)
            <section data-boxed data-tile="{{ $key }}" class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                            bg-(--color-surface-card)">
                <h2 class="flex items-baseline justify-between border-b border-(--color-border)
                           bg-(--color-section-head) px-4 py-3 font-semibold">
                    <span>{{ $label }}</span>
                    <span class="tabular-nums">{{ $money($figures[$key]) }}</span>
                </h2>
                @if (! empty($accounts[$key]))
                    <ul class="px-4 py-3 text-sm">
                        @foreach ($accounts[$key] as $a)
                            <li class="flex items-baseline justify-between gap-3">
                                <a class="underline" href="{{ route('accounts.report.show', ['slug' => 'ledger', 'account_id' => $a['id']]) }}">{{ $a['code'] }} · {{ $a['name'] }}</a>
                                <span class="tabular-nums">{{ $money($a['amount']) }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @endforeach
    </div>

    {{-- ── হিসাব আর মিল ──────────────────────────────────────────────── --}}
    <div class="grid gap-4 lg:grid-cols-2">
        <section data-boxed data-side="position" class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card)">
            <ul class="px-4 py-3 text-sm">
                <li class="flex items-baseline justify-between font-semibold"><span>{{ __('accounts::field.nw_assets') }}</span><span class="tabular-nums">{{ $money($figures['assets']) }}</span></li>
                <li class="flex items-baseline justify-between"><span>{{ __('accounts::field.nw_less_payable') }}</span><span class="tabular-nums">−{{ $money($figures['payable']) }}</span></li>
                <li class="flex items-baseline justify-between"><span>{{ __('accounts::field.nw_less_other_liabilities') }}</span><span class="tabular-nums">−{{ $money($figures['other_liabilities']) }}</span></li>
                <li class="flex items-baseline justify-between font-semibold"><span>= {{ __('accounts::field.nw_net') }}</span><span class="tabular-nums">{{ $money($figures['net']) }}</span></li>
            </ul>
        </section>
        <section data-boxed data-side="equity" class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card)">
            <ul class="px-4 py-3 text-sm">
                <li class="flex items-baseline justify-between"><span>{{ __('accounts::field.nw_capital') }}</span><span class="tabular-nums">{{ $money($figures['capital']) }}</span></li>
                <li class="flex items-baseline justify-between"><span>{{ __('accounts::field.nw_drawings') }}</span><span class="tabular-nums">−{{ $money($figures['drawings']) }}</span></li>
                <li class="flex items-baseline justify-between"><span>{{ __('accounts::field.nw_earlier') }}</span><span class="tabular-nums">{{ $money($figures['earlier']) }}</span></li>
                <li class="flex items-baseline justify-between"><span>{{ __('accounts::field.nw_this_year') }}</span><span class="tabular-nums">{{ $money($figures['this_year']) }}</span></li>
                <li class="flex items-baseline justify-between font-semibold"><span>= {{ __('accounts::field.nw_net') }}</span><span class="tabular-nums">{{ $money($figures['equity']) }}</span></li>
            </ul>
        </section>
    </div>

    @foreach ($missing as $m)
        <p role="note" class="mt-4 rounded-(--radius-field) bg-(--color-badge-pending-bg) px-3 py-2
                              text-sm text-(--color-badge-pending-ink)" data-missing="{{ $m['code'] }}">
            {{ __('accounts::field.nw_missing', ['company' => $m['code']]) }}
        </p>
    @endforeach
</x-layouts.app>
