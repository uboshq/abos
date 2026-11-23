{{--
    পাওনার সম্ভাব্য ক্ষতি (IFRS 9) আর অনাদায়ী বাদ — হিসাবের ফাঁক #১৮ ([[CreditLossController]], [[ReceivableCreditLoss]])।

    ⓘ উপরে কোন দিনের; মাঝে বয়সের প্রতিটা ভাগ: বকেয়া × হার = সম্ভাব্য ক্ষতি, নিচে লক্ষ্য, ভাতায় যা আছে, তফাত আর তফাতের খসড়ার
    বোতাম; শেষে একজন গ্রাহকের অনাদায়ী বাদের খসড়া। দুটো বোতামই খসড়া ভাউচার লেখে — পাকা হয় ভাউচারের পাতায়।
--}}
@php
    $money = fn ($v) => \App\Core\Support\Money::format($v);
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('credit_control::loss.title') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('credit_control::loss.title')" :subtitle="__('credit_control::loss.subtitle')" />
    </x-slot:header>

    <form method="GET" class="mb-4 flex flex-wrap items-end gap-3 print-hide">
        <x-ui.field name="as_of" type="date" :label="__('credit_control::loss.as_of')" :value="$position['as_of']" />
        <x-ui.button type="submit" tone="secondary">{{ __('core.action.apply') }}</x-ui.button>
    </form>

    <section data-boxed class="mb-4 overflow-x-auto rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <table class="w-full text-sm">
            <thead class="bg-(--color-section-head)">
                <tr>
                    <th class="px-4 py-3 text-start font-semibold">{{ __('credit_control::loss.bucket') }}</th>
                    <th class="px-4 py-3 text-end font-semibold">{{ __('credit_control::loss.due') }}</th>
                    <th class="px-4 py-3 text-end font-semibold">{{ __('credit_control::loss.rate') }}</th>
                    <th class="px-4 py-3 text-end font-semibold">{{ __('credit_control::loss.history') }}</th>
                    <th class="px-4 py-3 text-end font-semibold">{{ __('credit_control::loss.loss') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($position['buckets'] as $bucket)
                    <tr data-bucket="{{ $bucket['key'] }}" class="border-t border-(--color-border)">
                        <th scope="row" class="px-4 py-2 text-start font-medium">{{ __('credit_control::loss.'.$bucket['key']) }}</th>
                        <td class="px-4 py-2 text-end tabular-nums">{{ $money($bucket['due']) }}</td>
                        <td class="px-4 py-2 text-end tabular-nums">{{ rtrim(rtrim($bucket['rate'], '0'), '.') ?: '0' }}%</td>
                        {{-- ⭐ ইতিহাসের হার — কেবল প্রস্তাব, হার বসায় না ([[ReceivableCreditLoss::history()]]; মালিক, ২১ অক্টোবর ২০২৬) --}}
                        <td data-history class="px-4 py-2 text-end tabular-nums text-(--color-ink-muted)">{{ $bucket['history'] === null ? '—' : (rtrim(rtrim($bucket['history'], '0'), '.') ?: '0').'%' }}</td>
                        <td class="px-4 py-2 text-end tabular-nums">{{ $money($bucket['loss']) }}</td>
                    </tr>
                @endforeach
                <tr class="border-t-2 border-(--color-border) font-semibold">
                    <th scope="row" class="px-4 py-2 text-start" colspan="4">{{ __('credit_control::loss.target') }}</th>
                    <td data-loss-target class="px-4 py-2 text-end tabular-nums">{{ $money($position['target']) }}</td>
                </tr>
                <tr class="border-t border-(--color-border)">
                    <th scope="row" class="px-4 py-2 text-start" colspan="4">{{ __('credit_control::loss.held') }}</th>
                    <td data-loss-held class="px-4 py-2 text-end tabular-nums">{{ $money($position['held']) }}</td>
                </tr>
                <tr class="border-t border-(--color-border) font-semibold">
                    <th scope="row" class="px-4 py-2 text-start" colspan="4">{{ __('credit_control::loss.adjustment') }}</th>
                    <td data-loss-adjustment class="px-4 py-2 text-end tabular-nums">{{ $money($position['adjustment']) }}</td>
                </tr>
            </tbody>
        </table>
    </section>

    @can('accounts.voucher.create')
        @if (bccomp($position['adjustment'], '0', 2) !== 0)
            <form method="POST" action="{{ route('credit_control.loss.provision') }}" class="mb-6">
                @csrf
                <input type="hidden" name="as_of" value="{{ $position['as_of'] }}">
                <x-ui.button type="submit" tone="primary">{{ __('credit_control::loss.draft_provision') }}</x-ui.button>
            </form>
        @endif

        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="mb-3 font-semibold">{{ __('credit_control::loss.write_off_title') }}</h2>
            <form method="POST" action="{{ route('credit_control.loss.write_off') }}" class="grid gap-3 lg:grid-cols-4">
                @csrf
                <input type="hidden" name="as_of" value="{{ $position['as_of'] }}">
                <x-ui.select name="customer_id" :label="__('credit_control::loss.customer')"
                             :options="$customers->mapWithKeys(fn ($c) => [$c->id => $c->code.' · '.$c->name()])" />
                <x-ui.field name="amount" type="number" step="0.01" :label="__('credit_control::loss.amount')" />
                <x-ui.field name="reason" :label="__('credit_control::loss.reason')" />
                <div class="flex items-end">
                    <x-ui.button type="submit" tone="secondary">{{ __('credit_control::loss.draft_write_off') }}</x-ui.button>
                </div>
            </form>
        </section>
    @endcan
</x-layouts.app>
