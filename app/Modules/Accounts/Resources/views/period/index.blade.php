{{--
    মাস বন্ধ করা ও খোলা।

    পাতাটা মাসের তালিকা দেখায়, নতুন আগে — কারণ যে মাসটা এইমাত্র শেষ
    হয়েছে, সেটাই বন্ধ করার কথা। পুরনোগুলো নিচে, আর সেগুলো সাধারণত
    আগেই বন্ধ।

    বন্ধ করা এক ক্লিক, খোলা নয়: খুলতে কারণ লিখতে হয়, আর সেটাই ছয় মাস
    পরে নিরীক্ষকের প্রশ্নের একমাত্র উত্তর।
--}}
@php
    /*
        কলাম ধরে — `x-ui.table` স্লট পড়ে না, সারি আসে :rows থেকে।

        সারিগুলো মডেল নয়, একটা অ্যারে (`$month['label']` ধাঁচে), তাই
        প্রতিটা কলামেই `render` লাগে — কম্পোনেন্টের ডিফল্ট পাঠ
        অবজেক্টের বৈশিষ্ট্য খোঁজে।
    */
    $columns = [
        [
            'key' => 'label',
            'label' => __('accounts::field.month'),
            'render' => fn ($m) => $m['label'],
        ],
        [
            'key' => 'state',
            'label' => __('accounts::field.state'),
            'width' => '8rem',
            'render' => fn ($m) => view('accounts::period.partials.state', ['month' => $m]),
        ],
        [
            'key' => 'reason',
            'label' => __('accounts::field.reason'),
            // ⓘ বাকি রেখে বন্ধ হলে কী বাকি ছিল, কারণের নিচে ([[PeriodLockController::close()]]; ২১ নভেম্বর ২০২৬)
            'render' => fn ($m) => new \Illuminate\Support\HtmlString(e($m['lock']?->reason ?: '—')
                .(! empty($m['lock']?->open_items)
                    ? '<span class="block text-xs text-(--color-warning-hover)" data-open-items>'
                        .e(__('accounts::message.period_left', ['items' => \App\Modules\Accounts\Services\MonthEndChecklist::itemList($m['lock']->open_items)])).'</span>'
                    : '')),
        ],
        [
            'key' => 'actions',
            'label' => __('core.table.actions'),
            'render' => fn ($m) => view('accounts::period.partials.actions', ['month' => $m]),
        ],
    ];

    // ⛔ মাথা আর প্যানেল কেবল ABOS Smart, Navy, Blue-এ — বাকি রূপ হুবহু আগের মতো ([[AbosHead]])
    $abosHead = \App\Core\Support\AbosHead::on();
    $facetPanel = ! $abosHead || $year === null ? null : \App\Core\ListFilter\Facets::panel([
        \App\Core\ListFilter\Facets::one('fy', __('accounts::period_list.year'), $years->mapWithKeys(fn ($y) => [(string) $y->id => (string) $y->name])->all(), (string) $year->id),
        \App\Core\ListFilter\Facets::pick('state', __('accounts::field.state'), ['open' => __('accounts::field.open'), 'closed' => __('accounts::field.closed')]),
    ]);
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('accounts::menu.periods') }}</x-slot:title>

    @unless ($abosHead)
    <x-slot:header>
        <x-ui.page-header :title="__('accounts::menu.periods')"
                          :subtitle="__('accounts::message.period_note')" />
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
            <ul class="list-inside list-disc">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($facetPanel)
    <x-ui.filter-layout :filters="$facetPanel" form="list-form">
    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form id="list-form" method="GET" class="contents">
            <x-ui.toolbar :title="__('accounts::menu.periods')" :info="__('accounts::message.period_note')"
                          :search-placeholder="__('accounts::field.month')" :columns="$columns"
                          :sort="['recent' => __('accounts::period_list.recent'), 'oldest' => __('accounts::period_list.oldest')]"
                          :quiet="['fy']" :facets="$facetPanel" />
        </form>
        <x-ui.table :rows="$months" :columns="$columns" :empty="__('core.empty.no_results')" />
    </div>
    </x-ui.filter-layout>
    @elseif ($months === [])
        <x-ui.empty-state :message="__('accounts::message.no_current_year')" />
    @else
    <x-ui.table :rows="$months"
                :columns="$columns"
                :empty="__('core.empty.no_results')" />
    @endif
</x-layouts.app>
