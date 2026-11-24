{{--
    পরিবহন ও শ্রমিকের খতিয়ান — মানচিত্র §৬। ট্যাব → পক্ষ ধরে বাকি → একটা
    পক্ষ বাছলে তার সারিগুলো, চলমান বাকিসহ, প্রতিটা তার কাগজে নামে।
--}}
@php
    use App\Core\Support\Money;

    $keep = ['tab' => $tab, 'from' => $from, 'to' => $to];
    $sum = fn (string $k) => $parties->reduce(fn (string $c, $p) => bcadd($c, $p[$k], 4), '0');

    // ⭐ ছাঁকনি ডান পাশের প্যানেলে — আগের দুই তারিখের ঘর, একই চাবি; পাতা যে পরিসর ধরে চলেছে সেটাই দেখায় (মালিক, ২৩ নভেম্বর ২০২৬; [[Facets]])
    $facetPanel = \App\Core\ListFilter\Facets::panel([
        \App\Core\ListFilter\Facets::dates(__('core.list_filter.date')),
    ], shown: ['from' => $from, 'to' => $to]);
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('finance::carrier_labour.title') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('finance::carrier_labour.title')"
                          :subtitle="__('finance::carrier_labour.subtitle')" />
    </x-slot:header>

    <nav class="mb-3 flex flex-wrap items-end gap-1 border-b border-(--color-border) text-sm"
         aria-label="{{ __('finance::carrier_labour.title') }}">
        @foreach (array_keys(\App\Modules\Finance\Services\CarrierAndLabourLedger::HEADS) as $key)
            @php $on = $tab === $key; @endphp
            <a href="{{ route('finance.carrier_labour.index', ['tab' => $key, 'from' => $from, 'to' => $to]) }}"
               @if ($on) aria-current="page" @endif
               class="-mb-px flex min-h-(--spacing-touch) items-center border-b-2 px-3
                      {{ $on
                          ? 'border-(--color-brand-500) font-semibold text-(--color-ink)'
                          : 'border-transparent text-(--color-ink-muted) hover:text-(--color-ink)' }}">
                {{ __('finance::carrier_labour.'.$key) }}
            </a>
        @endforeach
    </nav>

    {{-- ⓘ তারিখ বেছে — ডান পাশের ছাঁকনির প্যানেলে, একই চাবি (tab, party সাথে যায়); চলে "দেখান" বা Enter-এ --}}
    <x-ui.filter-layout :filters="$facetPanel" form="list-form" :report="true">
        <form id="list-form" method="GET" action="{{ route('finance.carrier_labour.index') }}" class="contents">
            <input type="hidden" name="tab" value="{{ $tab }}">
            @if ($party !== null)
                <input type="hidden" name="party" value="{{ $party }}">
            @endif
            <x-ui.filter-bar :facets="$facetPanel" />
        </form>
    </x-ui.filter-layout>

    @if ($head === null)
        <p class="rounded-(--radius-field) bg-(--color-badge-pending-bg) px-3 py-2 text-sm text-(--color-badge-pending-ink)">
            {{ __('finance::carrier_labour.no_head') }}
        </p>
    @elseif ($statement === null)
        <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
            <header class="flex flex-wrap items-baseline gap-x-4 border-b border-(--color-border) px-4 py-2 text-sm">
                <h2 class="font-semibold">{{ $head->label() }}</h2>
                <span class="ms-auto tabular-nums">{{ __('finance::carrier_labour.closing') }}:
                    <strong>{{ Money::format($sum('closing')) }}</strong></span>
            </header>

            <x-ui.table :rows="$parties" :empty="__('finance::carrier_labour.none')" :columns="[
                ['key' => 'label', 'label' => __('finance::carrier_labour.party'),
                 'render' => fn ($p) => view('finance::carrier-labour.partials.party-link', ['p' => $p, 'keep' => $keep])],
                ['key' => 'opening', 'label' => __('finance::carrier_labour.opening'), 'numeric' => true,
                 'render' => fn ($p) => Money::format($p['opening'])],
                ['key' => 'charged', 'label' => __('finance::carrier_labour.charged'), 'numeric' => true,
                 'render' => fn ($p) => Money::format($p['charged'])],
                ['key' => 'paid', 'label' => __('finance::carrier_labour.paid'), 'numeric' => true,
                 'render' => fn ($p) => Money::format($p['paid'])],
                ['key' => 'closing', 'label' => __('finance::carrier_labour.closing'), 'numeric' => true,
                 'render' => fn ($p) => Money::format($p['closing'])],
            ]" />
        </div>
    @else
        <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
            <header class="flex flex-wrap items-baseline gap-x-4 border-b border-(--color-border) px-4 py-2 text-sm">
                <a href="{{ route('finance.carrier_labour.index', $keep) }}"
                   class="text-(--color-brand-600) underline-offset-2 hover:underline">{{ __('finance::carrier_labour.back') }}</a>
                {{-- ⭐ যাঁর খতিয়ান দেখছি, তাঁর নামটা তাঁর পাতায় নিয়ে যায়
                     (২০ সেপ্টেম্বর ২০২৬)। ⓘ ঠিকানাটা সেবা থেকেই আসে, তাই
                     এখানে কোনো মডিউলের নাম লেখা নেই। --}}
                @php ($chosen = $parties->firstWhere('key', $party))
                <h2 class="font-semibold">
                    @if (! empty($chosen['route']))
                        <a href="{{ route($chosen['route'][0], $chosen['route'][1]) }}"
                           class="text-(--color-brand-600) underline-offset-2 hover:underline">{{ $chosen['label'] }}</a>
                    @else
                        {{ $chosen['label'] ?? __('finance::carrier_labour.no_party') }}
                    @endif
                </h2>
                {{-- ⓘ "শুরুতে বাকি" সময়ের শুরুর, আর "আনা হলো" এই পাতার আগের সারিগুলো ধরে --}}
                <span class="ms-auto tabular-nums">{{ __('finance::carrier_labour.opening') }}:
                    <strong>{{ Money::format($statement['opening']) }}</strong></span>
                @if (bccomp((string) $statement['brought'], (string) $statement['opening'], 4) !== 0)
                    <span class="tabular-nums">{{ __('finance::carrier_labour.brought') }}:
                        <strong>{{ Money::format($statement['brought']) }}</strong></span>
                @endif
            </header>

            <x-ui.table :rows="$statement['rows']" :empty="__('finance::carrier_labour.none')" :columns="[
                ['key' => 'date', 'label' => __('finance::carrier_labour.date'), 'width' => '7rem',
                 'render' => fn ($e) => $e->trx_date?->translatedFormat('j M Y')],
                ['key' => 'document', 'label' => __('finance::carrier_labour.document'), 'width' => '9rem',
                 'render' => fn ($e) => view('finance::bank-charge.partials.drill', ['entry' => $e])],
                ['key' => 'narration', 'label' => __('finance::carrier_labour.narration'),
                 'render' => fn ($e) => $e->narration ?: '—'],
                ['key' => 'charged', 'label' => __('finance::carrier_labour.charged'), 'numeric' => true,
                 'render' => fn ($e) => bccomp((string) $e->credit, '0', 4) > 0 ? Money::format($e->credit) : ''],
                ['key' => 'paid', 'label' => __('finance::carrier_labour.paid'), 'numeric' => true,
                 'render' => fn ($e) => bccomp((string) $e->debit, '0', 4) > 0 ? Money::format($e->debit) : ''],
                ['key' => 'balance', 'label' => __('finance::carrier_labour.balance'), 'numeric' => true,
                 'render' => fn ($e) => Money::format($e->running_balance)],
            ]" />

            <x-ui.pager :rows="$statement['rows']" />
            <x-ui.list-totals :rows="$statement['rows']" />
        </div>
    @endif
</x-layouts.app>
