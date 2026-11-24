{{--
    লক্ষ্যমাত্রা — বসানো ও মেলানো।

    ── কেন প্রতিটা সারিতেই ঘরটা খোলা থাকে ──────────────────────────────
    মালিক মাসের শুরুতে সবার টার্গেট একসাথে বসান, একজন একজন করে নয়।
    "সম্পাদনা" বোতাম দিয়ে একটা করে খুললে বারোজনের জন্য বারোবার পর্দা
    বদলাতে হত।

    ── কেন অর্জনের সংখ্যাটা ক্লিকযোগ্য ─────────────────────────────────
    নিয়ম ১: প্রতিটা সংখ্যা তার উৎসে নিয়ে যায়। "করিম ৪,২০,০০০" দেখে
    মালিকের পরের প্রশ্নটা সবসময় "কোন বিলগুলো?"
--}}
@php
    use App\Core\Support\Money;

    $canManage = auth()->user()?->can('sales.target.manage') ?? false;
@endphp

@php
    /* কলাম ধরে — `x-ui.table` স্লট পড়ে না, সারি আসে :rows থেকে। */
    $columns = [
        [
            'key' => 'who',
            'label' => __('sales::target.who'),
            'render' => fn ($r) => $r['user']->name,
        ],
        [
            'key' => 'target',
            'label' => __('sales::target.target'),
            'numeric' => true,
            'total' => 'money',
            'width' => '11rem',
            'render' => fn ($r) => view('sales::target.partials.target',
                ['row' => $r, 'canManage' => $canManage]),
        ],
        [
            'key' => 'achieved',
            'label' => __('sales::target.achieved'),
            'numeric' => true,
            'total' => 'money',
            'width' => '11rem',
            'render' => fn ($r) => view('sales::target.partials.achieved',
                ['row' => $r, 'month' => $month]),
        ],
        [
            'key' => 'percent',
            'label' => __('sales::target.percent'),
            'numeric' => true,
            'width' => '8rem',
            'render' => fn ($r) => view('sales::target.partials.percent', ['row' => $r]),
        ],
    ];
@endphp

@php
    // ⭐ এক সারির মাথা কেবল তিন ABOS রূপে; মাস ছাঁকনির প্যানেলে, সংরক্ষণ বারে (মালিক, ২৪ নভেম্বর ২০২৬)
    // ⛔ খোঁজার ঘর নেই: টাইপের সাথে পাতা আবার আসে, আর বসানো-কিন্তু-না-রাখা লক্ষ্য হারাত
    $abosHead = \App\Core\Support\AbosHead::on();
    $facetPanel = \App\Core\ListFilter\Facets::panel($abosHead ? [\App\Core\ListFilter\Facets::month('month', __('sales::target.month'), $month->format('Y-m'))] : []);
@endphp
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('sales::target.title') }}</x-slot:title>

    @unless ($abosHead)
    <x-slot:header>
        <x-ui.page-header :title="__('sales::target.title')" :subtitle="__('sales::target.subtitle')">
            <x-slot:actions>
                <form method="GET" class="flex items-end gap-2">
                    <label class="text-sm">
                        <span class="mb-1 block text-(--color-ink-muted)">{{ __('sales::target.month') }}</span>
                        <x-ui.month name="month" :value="$month->format('Y-m')"
                                    class="h-(--spacing-field-compact) rounded-(--radius-field) border border-(--color-border)
                                           bg-(--color-surface-card) px-2" />
                    </label>
                    <x-ui.button type="submit" tone="secondary">{{ __('core.action.apply') }}</x-ui.button>
                </form>
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>
    @endunless

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    @if ($errors->any())
        <div role="alert"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm
                    text-(--color-badge-danger-ink)">
            {{ $errors->first() }}
        </div>
    @endif

    <x-ui.filter-layout :filters="$facetPanel" form="list-form">
    @if ($abosHead)
        <form id="list-form" method="GET" class="contents">
            <x-ui.toolbar :title="__('sales::target.title')" :info="__('sales::target.subtitle').' '.__('sales::target.empty_means_none')" :search="false" :export="false" :print="false" :share="false" :refresh="false" :facets="$facetPanel">
                @if ($canManage && count($rows) > 0)
                    <x-slot:actions>
                        <x-ui.button type="submit" form="target-form" tone="primary">{{ __('core.action.save') }}</x-ui.button>
                    </x-slot:actions>
                @endif
            </x-ui.toolbar>
        </form>
    @endif
    <form id="target-form" method="POST" action="{{ route('sales.target.store') }}">
        @csrf
        <input type="hidden" name="month" value="{{ $month->toDateString() }}">

        <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card)">
        {{-- ⭐ সর্বমোট — ওয়েব-মান ধাপ ২, ২০ অক্টোবর ২০২৬: পাতা ভাগ নেই, তাই সারির যোগই গোটা দলের; লক্ষ্য না বসানো সারি যোগে নেই --}}
        @php
            $grand = ['target' => '0', 'achieved' => '0'];
            foreach ($rows as $r) {
                $grand['target'] = bcadd($grand['target'], (string) ($r['target'] ?? '0'), 4);
                $grand['achieved'] = bcadd($grand['achieved'], (string) $r['achieved'], 4);
            }
        @endphp
        <x-ui.table :rows="$rows"
                    :columns="$columns"
                    :grand="$rows === [] ? [] : $grand"
                    :empty="__('sales::target.nobody_sells')" />
        </div>

        @if ($canManage && $rows !== [])
            @unless ($abosHead)
                <p class="mt-2 text-xs text-(--color-ink-muted)">{{ __('sales::target.empty_means_none') }}</p>
            @endunless

            <div class="mt-3">
                <x-ui.button type="submit" tone="primary">{{ __('core.action.save') }}</x-ui.button>
            </div>
        @endif
    </form>
    </x-ui.filter-layout>
</x-layouts.app>
