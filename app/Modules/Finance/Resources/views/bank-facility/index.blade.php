{{--
    ব্যাংকের সুবিধা — মঞ্জুরি, জামানত, নবায়ন।

    ── ⛔ যা এই পর্দায় নেই: টাকা নাড়ার বোতাম ──────────────────────────
    হাতধারের পর্দায় "টাকা দিলাম / নিলাম" আছে; এখানে নেই, আর সেটা
    ইচ্ছাকৃত। ⓘ **খাতা ঘটনা লেখে · ভাউচার টাকা নাড়ে** — মালিকের
    সিদ্ধান্ত, ১৪ সেপ্টেম্বর ২০২৬।

    ⚠️ দুইটা দরজা থাকলে একদিন একটায় চার্জের ঘর বসত, অন্যটায় না।
--}}
@php
    /*
     * ⭐ তালিকার গড়ন — ১৯ সেপ্টেম্বর ২০২৬, মালিক: *"সব পাতাতেই সমস্যা"*।
     *
     * ⓘ টুলবার (শিরোনাম · বর্ণনা · + নতুন সুবিধা) → চালু · বন্ধ ট্যাব →
     * নবায়নের সতর্কতা → তালিকা। ফর্মটা নিজের পাতায় ([[bank-facility/create]]),
     * তালিকার উপরে নয় — আগে লম্বা ফর্মের নিচে তালিকাটা কেউ খুঁজে পেত না।
     * ⓘ কলামগুলো এক জায়গায়, যাতে টুলবারের Columns মেনু আর টেবিল একই জিনিস বলে।
     */
    $tabs = [
        'active' => __('finance::state.active'),
        'closed' => __('finance::state.closed'),
    ];

    $bfColumns = [
        ['key' => 'bank', 'label' => __('finance::field.bank'),
         'render' => fn ($f) => new \Illuminate\Support\HtmlString(
             '<a href=\'' . route('finance.bank_facility.show', $f->id) . '\' '
             . 'class=\'text-brand-500 underline-offset-2 hover:underline\'>'
             . e($f->bank) . '</a>')],
        ['key' => 'kind', 'label' => __('finance::field.facility_kind'), 'width' => '10rem',
         'render' => fn ($f) => __('finance::field.facility_' . $f->kind)],
        /* ⓘ চুক্তিটা `numeric`, `align`/`money` নয় — কম্পোনেন্টের
             নিজের নিয়ম ([[App\View\Components\Ui\Table]])। */
        ['key' => 'limit_amount', 'label' => __('finance::field.limit_amount'), 'numeric' => true,
         'render' => fn ($f) => \App\Core\Support\Money::format($f->limit_amount)],

        /* ⚠️ `null` মানে "প্রশ্নটাই অপ্রাসঙ্গিক", শূন্য নয় —
             মেয়াদি ঋণের সারিতে শূন্য দেখালে কেউ ভাবতেন সীমা
             ফুরিয়ে গেছে। */
        ['key' => 'drawing', 'label' => __('finance::field.drawing_power'), 'numeric' => true,
         'render' => fn ($f) => $f->drawingPower() === null
             ? '—'
             : \App\Core\Support\Money::format($f->drawingPower())],
        /*
         * ⭐ ব্যবহৃত অঙ্ক ও বাকি সীমা — অর্থের মানচিত্র §১৪গ, ২০ সেপ্টেম্বর ২০২৬।
         *
         * ⓘ সংখ্যা দুইটা খতিয়ান থেকে গোনা ([[BankFacilityService::standing()]]),
         * সারিতে রাখা নেই। ⚠️ গ্যারান্টিতে টাকা তোলাই হয় না, তাই "—"।
         */
        ['key' => 'used', 'label' => __('finance::field.facility_used'), 'numeric' => true, 'width' => '9rem',
         'render' => fn ($f) => isset($standing[$f->id])
             ? \App\Core\Support\Money::format($standing[$f->id]['used'])
             : '—'],
        ['key' => 'left', 'label' => __('finance::field.facility_left'), 'numeric' => true, 'width' => '9rem',
         'render' => fn ($f) => isset($standing[$f->id])
             ? \App\Core\Support\Money::format($standing[$f->id]['left'])
             : '—'],

        ['key' => 'renews_on', 'label' => __('finance::field.renews_on'), 'width' => '9rem',
         'render' => fn ($f) => $f->renews_on?->translatedFormat('j M Y') ?? '—'],
        ['key' => 'status', 'label' => __('finance::field.state'), 'width' => '8rem',
         'render' => fn ($f) => __('core.status.' . $f->status)],
        // ⓘ সুবিধার নিজের পাতা — জামানত, শর্ত, খাতায় কোথায়, আর বন্ধ করা
        ['key' => 'do', 'label' => __('core.table.actions'), 'width' => '7rem',
         'render' => fn ($f) => view('finance::bank-facility.partials.open-it', ['facility' => $f])],
    ];
@endphp
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('finance::menu.bank_facility') }}</x-slot:title>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-badge-success-bg px-3 py-2 text-sm text-badge-success-ink">
            {{ session('saved') }}
        </div>
    @endif

    @if ($errors->any())
        <div role="alert"
             class="mb-4 rounded-(--radius-field) bg-badge-danger-bg px-3 py-2 text-sm text-badge-danger-ink">
            <ul class="list-inside list-disc">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                bg-(--color-surface-card)">
        <form method="GET" class="contents">
            {{-- ⓘ ঘনত্ব বা কলাম বদলালে ট্যাবটা হারায় না --}}
            @if ($tab === 'closed')
                <input type="hidden" name="tab" value="closed">
            @endif

            {{-- ⭐ নমুনার হেডার — নাম · ব্যাখ্যা · কেন খাতাটা নতুন।
    
                 ⓘ "ধারের চেয়ে অন্য জাত" কথাটা নমুনায় আছে, আর ওটার কাজ
                 আছে: মালিকের নিজের নির্দেশ ছিল *"bank loan alada rako"* —
                 হাতধার আর ব্যাংক ঋণ এক জিনিস নয়। ⚠️ এক করে ফেললে সুদের
                 হিসাব, জামানত আর নবায়ন সব এক ছাঁচে পড়ত। --}}
            <x-ui.toolbar :title="__('finance::menu.bank_facility')"
                          :subtitle="__('finance::field.facility_book_tag')"
                          :columns="$bfColumns"
                          :search-placeholder="__('finance::message.facility_search')"
                          :quiet="['tab']">
                <x-slot:actions>
                    @can('finance.bank_facility.create')
                        <x-ui.button tone="primary" icon="plus" :href="route('finance.bank_facility.create')">
                            {{ __('finance::message.facility_new') }}
                        </x-ui.button>
                    @endcan
                </x-slot:actions>
            </x-ui.toolbar>
        </form>

        {{-- ট্যাবের সারি — চালু · বন্ধ, পাশে গোনা --}}
        <nav class="flex flex-wrap gap-1 border-b border-(--color-border) px-2 text-sm"
             aria-label="{{ __('finance::menu.bank_facility') }}">
            @foreach ($tabs as $key => $label)
                <a href="{{ route('finance.bank_facility.index', $key === 'active' ? [] : ['tab' => $key]) }}"
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

        {{--
            ⭐ নবায়নের তালিকা — উপরে, আলাদা করে।
    
            ⛔ CC ও LTR বার্ষিক নবায়ন হয়, আর তারিখটা কেউ না দেখলে সুবিধাটা
            **নীরবে ফুরায়**। ⚠️ টের পাওয়া যায় একটা চেক ফেরত এলে — সাধারণত
            সরবরাহকারীর সামনে। ⓘ নিচের তালিকায় মিশিয়ে দিলে ঐ সারিটা আর
            দশটার মতোই দেখাত।
        --}}
        {{-- ⓘ এখন একই কার্ডের ভেতরে, ট্যাবের নিচে আর তালিকার উপরে — আলাদা
             বাক্স হলে টুলবারটা আবার পাতার মাথা থেকে নেমে যেত। --}}
        @if ($renewals->isNotEmpty())
            <section class="border-b border-(--color-border) bg-badge-warning-bg px-4 py-3">
                <h2 class="font-semibold text-badge-warning-ink">
                    {{ __('finance::message.facility_renewals_due') }}
                </h2>
                <ul class="mt-2 space-y-1 text-sm text-badge-warning-ink">
                    @foreach ($renewals as $due)
                        <li>
                            <a href="{{ route('finance.bank_facility.show', $due) }}"
                               class="underline-offset-2 hover:underline">
                                {{ $due->bank }} —
                                {{ __('finance::field.facility_' . $due->kind) }} ·
                                <x-ui.amount :value="$due->limit_amount" />
                            </a>
                            <span class="text-(--color-ink-muted)">
                                {{ $due->renews_on?->translatedFormat('j F Y') }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        <x-ui.table
            :compact="request()->boolean('compact')"
            :empty="filled(request('q')) ? __('core.empty.no_results') : __('finance::message.no_facilities')"
            :rows="$facilities"
            :columns="$bfColumns" />

        <x-ui.pager :rows="$facilities" />
    </div>
</x-layouts.app>
