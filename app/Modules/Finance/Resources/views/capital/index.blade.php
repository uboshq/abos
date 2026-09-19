{{--
    মূলধন ও বিনিয়োগ — দুইটা ট্যাব: লেনদেন, আর মালিক ও বিনিয়োগকারী।

    ── ⭐ কেন ট্যাব, ১৯ সেপ্টেম্বর ২০২৬ ─────────────────────────────────
    মালিকের প্রস্তাব: *"মূলধন ও বিনিয়োগের ভিতরে একটা ট্যাবে 'মালিক ও
    বিনিয়োগকারী'… বাকি বোতামগুলোতেও একই ভাবে।"* ⓘ আগে দুইটা অংশ ওপর-নিচে
    ছিল, আর "কার কত" জানতে সারির লম্বা ইতিহাস পেরিয়ে যেতে হত — অথবা উল্টো।

    ⚠️ নামগুলো আলাদা খাতায় যায় না: মানুষ থাকেন একটাই তালিকায় (মাস্টার
    ডেটার ব্যক্তি), আর এই ট্যাব কেবল এই খাতার লেনদেন থেকে প্রতি জনের
    হিসাব দেখায় ([[CapitalService::positions()]])। ⛔ নকল তালিকা হলে একদিন
    দুই জায়গায় দুই রকম নাম-ঠিকানা থাকত।
--}}
@php
    $tabs = [
        'entries' => __('finance::field.tab_entries'),
        'owners' => __('finance::field.owners_investors'),
    ];

    /*
     * ⭐ মালিকানার যোগ ১০০ কি না — ১৯ সেপ্টেম্বর ২০২৬।
     *
     * ⓘ অংশীদারি ব্যবসায় ঝগড়াটা ঠিক এই সংখ্যা নিয়েই হয়। ⚠️ কারও অংশ লেখা
     * না থাকলে (`null`) তিনি যোগে পড়েন না — তখন সতর্কবার্তাটা বলে কতটা
     * কারও নামে নেই। ⛔ কারও অংশই লেখা না থাকলে কিছু বলা হয় না: একক
     * মালিকের ব্যবসায় শতাংশ লেখার দরকারই পড়ে না।
     */
    $shared = collect($positions)->pluck('share')->filter(fn ($s) => $s !== null);
    $shareTotal = $shared->reduce(fn (string $sum, $s) => bcadd($sum, (string) $s, 4), '0');

    /*
     * ⚠️ দুইটা ছাড়, abos-8b-এর ধরা (১৯ সেপ্টেম্বর):
     *   · কারও অংশ মূলধন থেকে হিসাব করা (`share_source` = capital) হলে বার্তা
     *     নয় — তখন যোগ গঠন অনুযায়ীই ১০০ ([[CapitalService::positions()]])।
     *   · রাউন্ডিং: তিনজন সমান হলে ৩৩.৩৩৩৩ × ৩ = ৯৯.৯৯৯৯। ⛔ ছাড় না রাখলে
     *     মিথ্যা সতর্কবার্তা আসত, আর মিথ্যা বার্তা মানুষকে আসলটাও উপেক্ষা
     *     করতে শেখায়। ⓘ তাই ০.০১-এর বেশি ফারাক হলে তবেই।
     */
    $computed = collect($positions)->contains(fn ($p) => ($p['share_source'] ?? null) === 'capital');
    $gap = bcsub('100', $shareTotal, 4);
    $shareOff = ! $computed
        && $shared->isNotEmpty()
        && bccomp(ltrim($gap, '-'), '0.01', 4) > 0;
    $trim = fn (string $n) => rtrim(rtrim($n, '0'), '.');
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('finance::menu.capital') }}</x-slot:title>

    <x-slot:header>
        {{-- ⭐ "+ নতুন" উপরে, বাকি পর্দাগুলোর মতোই।

             ⛔ ফর্মটা আগে তালিকার মাঝখানে গোঁজা ছিল, আর মালিক ধরেছেন
             যে বাকি পর্দায় উপরে বোতাম থাকে। ⓘ এক রকম না হলে মানুষ
             প্রতিটা পর্দায় নতুন করে খোঁজেন কোথায় কী। --}}
        <x-ui.page-header :title="__('finance::menu.capital')"
                          :subtitle="__('finance::message.capital_note')">
            <x-slot:actions>
                @can('finance.capital.create')
                    <x-ui.button tone="primary" icon="plus" :href="route('finance.capital.create')">
                        {{ __('finance::action.new_contribution') }}
                    </x-ui.button>
                @endcan
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>

    @if (session('saved'))
        <p role="alert" class="mb-3 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2
                               text-sm text-(--color-badge-success-ink)">{{ session('saved') }}</p>
    @endif

    @if ($errors->any())
        <div role="alert" class="mb-3 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2
                                 text-sm text-(--color-badge-danger-ink)">
            <ul class="list-inside list-disc">
                @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    {{-- ট্যাবের সারি --}}
    <nav class="mb-3 flex flex-wrap gap-1 border-b border-(--color-border) text-sm"
         aria-label="{{ __('finance::menu.capital') }}">
        @foreach ($tabs as $key => $label)
            <a href="{{ route('finance.capital.index', $key === 'entries' ? [] : ['tab' => $key]) }}"
               @if ($tab === $key) aria-current="page" @endif
               class="-mb-px flex min-h-(--spacing-touch) items-center gap-2 border-b-2 px-3
                      {{ $tab === $key
                          ? 'border-(--color-brand-500) font-semibold text-(--color-ink)'
                          : 'border-transparent text-(--color-ink-muted) hover:text-(--color-ink)' }}">
                {{ $label }}
                @if ($key === 'owners')
                    <span class="rounded-full bg-(--color-surface-sunken) px-2 text-2xs text-(--color-ink-muted)">
                        {{ count($positions) }}
                    </span>
                @endif
            </a>
        @endforeach
    </nav>

    @if ($tab === 'owners')
        {{-- ── মালিক ও বিনিয়োগকারী — কে কোথায় দাঁড়িয়ে ──────────────────────── --}}
        <section data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card)">
            <h2 class="border-b border-(--color-border) bg-(--color-section-head) px-4 py-3 font-semibold">
                {{ __('finance::field.where_each_stands') }}
            </h2>

            @if ($shareOff)
                <p role="status" class="border-b border-(--color-border) bg-(--color-badge-pending-bg) px-4 py-2
                                        text-sm text-(--color-badge-pending-ink)">
                    {{ __('finance::message.share_total_off', [
                        'total' => $trim($shareTotal),
                        'gap' => $trim($gap),
                    ]) }}
                </p>
            @endif

            {{-- ⭐ লাভের অংশ — ১৮ সেপ্টেম্বর ২০২৬, মালিকের প্রশ্নে।

                 *"কে কত % মালিকানা, আর কে কত % লাভ পাবে — সেগুলো কোথায়?"*

                 ⓘ শতাংশটা আগে থেকেই ছিল; টাকায় কত সেটা ছিল না। ⚠️ সংখ্যাটা
                 **চলতি বছরের আন্দাজ**: বছর বন্ধ হওয়ার আগে একটা বড় খরচ বা
                 একটা অনাদায়ী বিল সবটা ঘুরিয়ে দিতে পারে। ⛔ তাই শিরোনামে
                 "চলতি" কথাটা আছে, আর লোকসান হলে সংখ্যাটা ঋণাত্মক দেখায়।

                 ⓘ নামটা লিংক — লেনদেন ট্যাবে কেবল তাঁর সারিগুলো খোলে। --}}
            <x-ui.table
                :empty="__('finance::message.no_capital_yet')"
                :rows="$positions"
                :columns="[
                    ['key' => 'name', 'label' => __('finance::field.who'),
                     'render' => fn ($p) => view('finance::capital.partials.owner-link', ['position' => $p])],
                    ['key' => 'type', 'label' => __('finance::field.as'),
                     'render' => fn ($p) => __('finance::who.'.$p['type'])],
                    ['key' => 'contributed', 'label' => __('finance::field.put_in'), 'numeric' => true,
                     'render' => fn ($p) => \App\Core\Support\Money::format($p['contributed'])],
                    ['key' => 'withdrawn', 'label' => __('finance::field.taken_out'), 'numeric' => true,
                     'render' => fn ($p) => \App\Core\Support\Money::format($p['withdrawn'])],
                    ['key' => 'net', 'label' => __('finance::field.stands_at'), 'numeric' => true,
                     'render' => fn ($p) => \App\Core\Support\Money::format($p['net'])],
                    ['key' => 'share', 'label' => __('finance::field.share'), 'numeric' => true,
                     /* ⓘ অংশ হাতে লেখা না থাকলে মূলধনের অনুপাতে হিসাব হয় (`share_source`
                        = capital) — তখন পাশে ছোট করে বলা থাকে, যাতে কেউ ভাবেন না ওটা চুক্তি */
                     'render' => fn ($p) => $p['share'] === null
                         ? '—'
                         : $trim((string) $p['share']).'%'.((($p['share_source'] ?? null) === 'capital')
                             ? ' ('.__('finance::field.share_by_capital').')'
                             : '')],
                    ['key' => 'profit_share', 'label' => __('finance::field.profit_share_now'), 'numeric' => true,
                     'render' => fn ($p) => $p['profit_share'] === null
                         ? '—'
                         : \App\Core\Support\Money::format($p['profit_share'])],
                ]" />
        </section>
    @else
        {{-- ── লেনদেন ─────────────────────────────────────────────────────── --}}
        <section data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card)">
            <h2 class="flex flex-wrap items-center gap-2 border-b border-(--color-border) bg-(--color-section-head) px-4 py-3 font-semibold">
                {{ __('finance::field.contributions') }}

                {{-- ⓘ একজনের সারি দেখানো হচ্ছে — কার, আর সবার দিকে ফেরার পথ --}}
                @if ($person)
                    <span class="rounded-full bg-(--color-surface-sunken) px-3 py-0.5 text-xs font-normal">
                        {{ __('finance::message.showing_one_person', ['name' => $person->name()]) }}
                    </span>
                    <a href="{{ route('finance.capital.index') }}"
                       class="text-xs font-normal text-(--color-brand-500) underline-offset-2 hover:underline">
                        {{ __('finance::action.show_everyone') }}
                    </a>
                @endif
            </h2>

            <x-ui.table
                :empty="__('finance::message.no_capital_yet')"
                :rows="$entries"
                :columns="[
                    ['key' => 'trx_date', 'label' => __('finance::field.date'), 'width' => '8rem',
                     'render' => fn ($e) => \App\Core\Support\DateFormat::format($e->trx_date)],
                    ['key' => 'document_no', 'label' => __('core.print.document_no'), 'width' => '9rem'],
                    ['key' => 'person', 'label' => __('finance::field.who'),
                     'render' => fn ($e) => $e->person?->name() ?? '—'],
                    ['key' => 'entry_type', 'label' => __('finance::field.kind'), 'width' => '8rem',
                     'render' => fn ($e) => __('finance::kind.'.$e->entry_type)],

                    ['key' => 'contributor_type', 'label' => __('finance::field.as'), 'width' => '8rem',
                     'render' => fn ($e) => __('finance::who.'.$e->contributor_type)],

                    ['key' => 'share_percent', 'label' => __('finance::field.share'), 'numeric' => true,
                     'width' => '7rem',
                     'render' => fn ($e) => $e->share_percent === null
                         ? '—'
                         : $trim((string) $e->share_percent).'%'],
                    ['key' => 'amount', 'label' => __('finance::field.amount'), 'numeric' => true, 'width' => '10rem',
                     'render' => fn ($e) => \App\Core\Support\Money::format($e->amount)],
                    /* ⚠️ চওড়া, কারণ ভিতরে খাতের ঘর, নম্বরের ঘর আর বোতাম —
                       তিনটা। সরু রাখলে লেখাগুলো লম্বালম্বি ভেঙে যায়। */
                    ['key' => 'status', 'label' => __('finance::field.state'), 'width' => '22rem',
                     'render' => fn ($e) => view('finance::capital.partials.state', ['entry' => $e])],

                    /*
                     * ⛔ সম্পাদনা ও মোছা — কেবল খসড়ায়।
                     *
                     * পোস্ট হওয়া সারিতে বোতাম দুইটা আসে না, আর সেটা সৌজন্য
                     * মাত্র: আসল পাহারা [[CapitalController::assertStillADraft()]]-এ,
                     * কারণ ঠিকানা টাইপ করে বা পুরনো ট্যাব থেকেও অনুরোধ আসতে
                     * পারে। ⓘ **মেনুতে লুকানো আর দরজায় তালা দেওয়া এক জিনিস নয়** —
                     * আজ এই পার্থক্যটা মালিকানার পর্দাতেও ধরা পড়েছে।
                     */
                    ['key' => 'actions', 'label' => '', 'width' => '3rem',
                     'render' => fn ($e) => $e->status !== \App\Modules\Finance\Models\CapitalEntry::DRAFT
                         ? ''
                         : view('finance::capital.partials.row-actions', ['entry' => $e])],
                ]" />

            <x-ui.pager :rows="$entries" />
        </section>
    @endif
</x-layouts.app>
