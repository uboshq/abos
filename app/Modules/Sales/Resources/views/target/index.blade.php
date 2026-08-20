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
            'width' => '11rem',
            'render' => fn ($r) => view('sales::target.partials.target',
                ['row' => $r, 'canManage' => $canManage]),
        ],
        [
            'key' => 'achieved',
            'label' => __('sales::target.achieved'),
            'numeric' => true,
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

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('sales::target.title') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('sales::target.title')" :subtitle="__('sales::target.subtitle')">
            <x-slot:actions>
                <form method="GET" class="flex items-end gap-2">
                    <label class="text-sm">
                        <span class="mb-1 block text-(--color-ink-muted)">{{ __('sales::target.month') }}</span>
                        <input type="month" name="month" value="{{ $month->format('Y-m') }}"
                               class="h-(--spacing-field-compact) rounded-(--radius-field) border border-(--color-border)
                                      bg-(--color-surface-card) px-2">
                    </label>
                    <x-ui.button type="submit" tone="secondary">{{ __('core.action.apply') }}</x-ui.button>
                </form>
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>

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

    <form method="POST" action="{{ route('sales.target.store') }}">
        @csrf
        <input type="hidden" name="month" value="{{ $month->toDateString() }}">

        <div class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card)">
        <x-ui.table :rows="$rows"
                    :columns="$columns"
                    :empty="__('sales::target.nobody_sells')" />
        </div>

        @if ($canManage && $rows !== [])
            <p class="mt-2 text-xs text-(--color-ink-muted)">{{ __('sales::target.empty_means_none') }}</p>

            <div class="mt-3">
                <x-ui.button type="submit" tone="primary">{{ __('core.action.save') }}</x-ui.button>
            </div>
        @endif
    </form>
</x-layouts.app>
