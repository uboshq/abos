{{--
    ভাড়ার চুক্তি ও জামানত।

    ── কেন "শেষ হয়ে আসছে" তালিকাটা সবার উপরে ────────────────────────────
    ⚠️ এই গোটা পর্দার সবচেয়ে দামি সংখ্যা ওটাই। বারো লাখ জামানতের
    ৯,৬০,০০০ ফেরত নিতে ভুলে যাওয়া এভাবেই ঘটে — কাগজটা ফাইলে থাকে,
    তারিখটা কারো মনে থাকে না, আর যেদিন মনে পড়ে সেদিন বাড়িওয়ালা বলেন
    "ওটা তো ভাড়ার সাথে কেটে গেছে"।

    ⓘ তাই নিচের তালিকায় গিয়ে খুঁজতে হয় না; জিনিসটা নিজে থেকে সামনে আসে।
--}}
{{--
    ⭐ তালিকাটা এখন `x-ui.table` — মালিক, ১৯ সেপ্টেম্বর ২০২৬: *"সব পাতাতেই সমস্যা"*।

    ⓘ আগে টেবিলটা হাতে লেখা ছিল, তাই টুলবারের ঘনত্ব, কলাম আর রপ্তানি
    বন্ধ রাখতে হত। ⭐ এখন কলামগুলো এক জায়গায় — টেবিল আর টুলবারের কলাম-মেনু
    একই তালিকা পড়ে, আর প্রতিটা ঘর আগের মতোই একই জিনিস দেখায়।
--}}
@php
    $rentColumns = [
        ['key' => 'counterparty', 'label' => __('finance::field.rental_counterparty'),
         'render' => fn ($c) => new \Illuminate\Support\HtmlString(
             '<a href=\'' . route('finance.rental.show', $c) . '\' '
             . 'class=\'text-(--color-brand-500) underline-offset-2 hover:underline\'>'
             . e($c->counterparty) . '</a>')],
        ['key' => 'subject', 'label' => __('finance::field.rental_subject'),
         'render' => fn ($c) => new \Illuminate\Support\HtmlString(
             '<span class=\'text-(--color-ink-muted)\'>' . e($c->subject) . '</span>')],
        ['key' => 'monthly_rent', 'label' => __('finance::field.rental_rent'), 'numeric' => true,
         'render' => fn ($c) => \App\Core\Support\Money::format($c->monthly_rent)],
        ['key' => 'cash', 'label' => __('finance::field.rental_cash'), 'numeric' => true,
         'render' => fn ($c) => \App\Core\Support\Money::format($c->monthlyCash())],
        ['key' => 'monthly_adjustment', 'label' => __('finance::field.rental_from_deposit'), 'numeric' => true,
         'render' => fn ($c) => \App\Core\Support\Money::format($c->monthly_adjustment)],
        /* ⭐ যে সংখ্যাটার জন্য এই পর্দা — ফেরত পাওয়ার টাকা */
        ['key' => 'deposit_left', 'label' => __('finance::field.rental_deposit_left'), 'numeric' => true,
         'render' => fn ($c) => new \Illuminate\Support\HtmlString(
             '<span class=\'font-semibold\'>' . e(\App\Core\Support\Money::format($c->depositLeft())) . '</span>')],
        ['key' => 'ends_on', 'label' => __('finance::field.rental_ends_on'), 'width' => '8rem',
         'render' => fn ($c) => $c->ends_on->format('d/m/Y')],
        /* ⓘ নিজের পাতায় — মাসের ভাড়া, শর্ত বদল, জামানত, চুক্তি শেষ সব ওখানে */
        ['key' => 'actions', 'label' => __('core.table.actions'), 'width' => '7rem',
         'render' => fn ($c) => new \Illuminate\Support\HtmlString(
             '<a href=\'' . route('finance.rental.show', $c) . '\' '
             . 'class=\'inline-flex min-h-(--spacing-touch) items-center rounded-(--radius-field) px-2 text-sm '
             . 'text-(--color-link) transition-colors hover:bg-(--color-surface-hover) print-hide\'>'
             . e(__('core.action.view')) . '</a>')],
    ];

    $rentTabs = [
        'running' => __('finance::state.active'),
        'closed' => __('finance::state.closed'),
    ];
@endphp
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('finance::menu.rental') }}</x-slot:title>

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

    <section data-boxed
             class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card)">
        {{-- ⭐ শিরোনাম, খোঁজা আর "বন্ধগুলোও দেখাও" — বাকি তালিকার মতো এক বাক্সে।
             মালিক, ১৯ সেপ্টেম্বর ২০২৬: *"সব মডিউলেই একই অবস্থা, সব ঠিক করো"*।

             ⓘ "বন্ধগুলোও দেখাও" চেকবক্সটা এখন ট্যাব (চালু · বন্ধ), মূলধনের পাতার
             মতো। ⭐ টেবিলটা এখন `x-ui.table`, তাই ঘনত্ব, কলাম আর রপ্তানি সত্যিই
             কাজ করে — আর "+ নতুন চুক্তি" শিরোনামের ডানে, ফর্মটা নিজের পাতায়। --}}
        <form method="GET" class="contents">
            {{-- ⓘ ঘনত্ব, কলাম বা খোঁজা বদলালে খোলা ট্যাবটা হারায় না --}}
            @if ($tab === 'closed')
                <input type="hidden" name="tab" value="closed">
            @endif

            <x-ui.toolbar :title="__('finance::menu.rental')"
                :subtitle="__('finance::message.rental_note')"
                :columns="$rentColumns"
                :search-placeholder="__('finance::message.rental_search')"
                {{-- ⓘ ট্যাব ছাঁকনির চিপ নয় — ট্যাব নিজেই দেখায়; `closed` পুরনো লিংকের --}}
                :quiet="['tab', 'closed']">
                <x-slot:actions>
                    @can('finance.rental.create')
                        <x-ui.button tone="primary" icon="plus" :href="route('finance.rental.create')">
                            {{ __('finance::action.rental_new') }}
                        </x-ui.button>
                    @endcan
                </x-slot:actions>
            </x-ui.toolbar>
        </form>

        {{-- ট্যাবের সারি — প্রতিটার পাশে কয়টা চুক্তি --}}
        <nav class="flex flex-wrap gap-1 border-b border-(--color-border) px-2 text-sm"
             aria-label="{{ __('finance::menu.rental') }}">
            @foreach ($rentTabs as $key => $label)
                <a href="{{ route('finance.rental.index', $key === 'running' ? [] : ['tab' => $key]) }}"
                   @if ($tab === $key) aria-current="page" @endif
                   class="-mb-px flex min-h-(--spacing-touch) items-center gap-2 border-b-2 px-3
                          {{ $tab === $key
                              ? 'border-(--color-brand-500) font-semibold text-(--color-ink)'
                              : 'border-transparent text-(--color-ink-muted) hover:text-(--color-ink)' }}">
                    {{ $label }}
                    <span class="rounded-full bg-(--color-surface-sunken) px-2 text-2xs text-(--color-ink-muted)">
                        {{ $counts[$key] }}
                    </span>
                </a>
            @endforeach
        </nav>

        {{-- ⓘ শেষ হয়ে আসা চুক্তি — একই বাক্সে, ট্যাবের নিচে আর তালিকার উপরে।
             আগে বাক্সের বাইরে উপরে বসত আর টুলবারকে নিচে ঠেলত। কেবল চালুর
             ট্যাবে: বন্ধ চুক্তির তালিকার উপরে "শেষ হয়ে আসছে" বিভ্রান্ত করত। --}}
        @if ($tab === 'running' && $endingSoon->isNotEmpty())
            <section class="border-b border-(--color-border) bg-(--color-badge-warning-bg) px-4 py-3">
                <h2 class="mb-2 font-semibold text-(--color-badge-warning-ink)">
                    {{ __('finance::message.rental_ending_soon') }}
                </h2>

                <ul class="grid gap-1 text-sm text-(--color-badge-warning-ink)">
                    @foreach ($endingSoon as $soon)
                        <li>
                            <a href="{{ route('finance.rental.show', $soon) }}"
                               class="underline decoration-dotted underline-offset-2">
                                {{ $soon->counterparty }}@if ($soon->subject) — {{ $soon->subject }}@endif
                            </a>
                            ·
                            {{ __('finance::message.rental_ends_on', ['date' => $soon->ends_on->format('d/m/Y')]) }}
                            ·
                            {{-- ⭐ ফেরতযোগ্য টাকাটা এখানেই লেখা — নাহলে কেউ
                                 ক্লিক করে দেখতে যেতেন না --}}
                            <span class="num font-semibold">{{ \App\Core\Support\Money::format($soon->depositLeft()) }}</span>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        <x-ui.table :rows="$contracts"
                    :columns="$rentColumns"
                    :compact="request()->boolean('compact')"
                    :empty="request('q') ? __('core.empty.no_results') : __('finance::message.no_rentals')" />

        <x-ui.pager :rows="$contracts" />
    </section>
</x-layouts.app>
