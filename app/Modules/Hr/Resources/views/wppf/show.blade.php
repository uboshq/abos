{{--
    ⭐ শ্রমিকের মুনাফায় অংশগ্রহণ তহবিল — এক অর্থবছর (শ্রম আইন ২০০৬, অধ্যায় ১৫; a6, ২১ অক্টোবর ২০২৬; [[WppfService]])।

    উপরে বছর বাছাই; তারপর লাভ-ক্ষতির নিট মুনাফা, ৫%, আর তিন ভাগ (ধারা ২৩৪, ২০১৩-র সংশোধনী — ৮০:১০:১০)।
    নিচে ঐচ্ছিক জাবেদা — "সই দিন" — কেবল `hr.wppf.manage`-এ, বছরে একবার।
--}}
@php
    $money = fn ($value) => \App\Core\Support\Money::format($value);
    $profit = bccomp((string) $figures['pl_net'], '0', 4) >= 0;
    $charged = bccomp((string) $figures['charged'], '0', 4) !== 0;
    // ⭐ এক সারির মাথা কেবল তিন ABOS রূপে ([[AbosHead]]); বাকি রূপ আগের মতো
    $abosHead = \App\Core\Support\AbosHead::on();
    $facetPanel = ! $abosHead ? null : \App\Core\ListFilter\Facets::panel([
        \App\Core\ListFilter\Facets::pick('year', __('hr::wppf.year'), $years->pluck('name', 'id')->all()),
    ]);
    $source = __('hr::wppf.source', ['from' => $year->starts_on->format('d M Y'), 'to' => $year->ends_on->format('d M Y')]);
@endphp
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('hr::wppf.title') }}</x-slot:title>

    @unless ($abosHead)
    <x-slot:header>
        <x-ui.page-header :title="__('hr::wppf.title')" :subtitle="$year->name" />
    </x-slot:header>
    @endunless

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    @if ($errors->any())
        <div role="alert"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm text-(--color-badge-danger-ink)">
            <ul class="list-inside list-disc">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <x-ui.filter-layout :filters="$facetPanel ?? \App\Core\ListFilter\Facets::panel([])" form="list-form" :report="true">
    @if ($abosHead)
        <div data-boxed class="mb-4 overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
            <form id="list-form" method="GET" action="{{ route('hr.wppf.show') }}" class="contents" data-report>
                <x-ui.toolbar :title="__('hr::wppf.title')" :count="$year->name" :info="$source.' '.__('hr::wppf.law')" :search="false" :report="true"
                              :export="false" :print="false" :share="false" :facets="$facetPanel" />
            </form>
        </div>
    @else
    <form method="GET" action="{{ route('hr.wppf.show') }}"
          class="mb-4 flex flex-wrap items-end gap-3 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
        <label class="block">
            <span class="mb-1 block text-2xs font-semibold uppercase tracking-wide text-(--color-ink-muted)">{{ __('hr::wppf.year') }}</span>
            <select name="year"
                    class="rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-2 py-1.5 text-sm">
                @foreach ($years as $each)
                    <option value="{{ $each->id }}" @selected((int) $each->id === (int) $year->id)>{{ $each->name }}</option>
                @endforeach
            </select>
        </label>
        <x-ui.button type="submit">{{ __('hr::wppf.show') }}</x-ui.button>
    </form>
    @endif

    <div class="mb-4 grid gap-3 sm:grid-cols-2" data-wppf-figures>
        <div data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-3">
            <p class="text-2xs font-semibold uppercase tracking-wide text-(--color-ink-muted)">
                {{ $profit ? __('hr::wppf.net_profit') : __('hr::wppf.net_loss') }}
            </p>
            <p class="num mt-1 text-xl font-semibold" data-wppf-net>{{ $money($profit ? $figures['pl_net'] : bcmul((string) $figures['pl_net'], '-1', 4)) }}</p>
            <p class="mt-1 text-xs text-(--color-ink-muted)">{{ __('hr::wppf.source', ['from' => $year->starts_on->format('d M Y'), 'to' => $year->ends_on->format('d M Y')]) }}</p>
            {{-- ⓘ জাবেদা বসার পরে — তহবিলের নিজের খরচ ফিরিয়ে যোগ, ভিত্তি তহবিলের আগের মুনাফা ([[WppfService::figuresFor()]]) --}}
            @if ($charged)
                <p class="mt-2 text-xs" data-wppf-base>
                    {{ __('hr::wppf.charged_back', ['charged' => $money($figures['charged']), 'base' => $money($figures['net_profit'])]) }}
                </p>
            @endif
        </div>
        <div data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-3">
            <p class="text-2xs font-semibold uppercase tracking-wide text-(--color-ink-muted)">{{ __('hr::wppf.wppf') }}</p>
            <p class="num mt-1 text-xl font-semibold" data-wppf-total>{{ $money($figures['wppf']) }}</p>
            <p class="mt-1 text-xs text-(--color-ink-muted)">{{ __('hr::wppf.law') }}</p>
        </div>
    </div>

    <table class="ui-lines mb-4 w-full rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <thead>
            <tr>
                <th class="text-start">{{ __('hr::wppf.fund') }}</th>
                <th class="text-end">{{ __('hr::wppf.share') }}</th>
                <th class="text-end">{{ __('hr::wppf.amount') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ([
                'participation_fund' => \App\Modules\Hr\Services\WppfService::PARTICIPATION,
                'welfare_fund' => \App\Modules\Hr\Services\WppfService::WELFARE,
                'foundation_fund' => \App\Modules\Hr\Services\WppfService::FOUNDATION,
            ] as $fund => $share)
                <tr data-wppf-fund="{{ $fund }}">
                    <td data-label="{{ __('hr::wppf.fund') }}">{{ __('hr::wppf.'.$fund) }}</td>
                    <td data-label="{{ __('hr::wppf.share') }}" class="num text-end">{{ $share }}%</td>
                    <td data-label="{{ __('hr::wppf.amount') }}" class="num text-end">{{ $money($figures[$fund]) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <section class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4 text-sm" data-wppf-provision>
        @if ($provision?->isPosted())
            <p>{{ __('hr::wppf.posted_on', ['amount' => $money($provision->wppf), 'at' => \App\Core\Support\DateFormat::format($provision->posted_at)]) }}</p>
        @else
            <p class="text-(--color-ink-muted)">{{ __('hr::wppf.provision_note') }}</p>
            @can('hr.wppf.manage')
                @if (bccomp((string) $figures['wppf'], '0', 4) > 0)
                    <form method="POST" action="{{ route('hr.wppf.provision', ['year' => $year->id]) }}" class="mt-3">
                        @csrf
                        <x-ui.button type="submit" tone="primary">{{ __('hr::wppf.sign') }}</x-ui.button>
                    </form>
                @endif
            @endcan
        @endif
    </section>
    </x-ui.filter-layout>
</x-layouts.app>
