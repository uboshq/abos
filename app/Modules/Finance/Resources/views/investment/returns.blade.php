{{--
    বিনিয়োগের রিটার্ন — মানচিত্র §১২, ২০ সেপ্টেম্বর ২০২৬।

    কার টাকা কত আয় করল: তাঁর অংশ অনুযায়ী লাভের ভাগ, আর সেটা তাঁর নিট
    মূলধনের কত শতাংশ।

    ⚠️ এই পর্দা কিছুই পোস্ট করে না — কেবল পড়ে। টাকা সরানো আলাদা কাজ
    (লাভ ভাগাভাগি)। ⓘ তাই এখানে কোনো "বণ্টন করুন" বোতাম নেই: দেখা আর
    করা এক পর্দায় থাকলে কেউ ভুল করে চাপত।
--}}
@php
    $money = fn ($v) => \App\Core\Support\Money::format($v);
    $loss = bccomp($report['profit'], '0', 4) < 0;

    /*
     * ⭐ প্রতিটা সংখ্যা যেন খোলা যায় — মালিকের কথা, ২০ সেপ্টেম্বর ২০২৬:
     * *"sob jaygay hyper link dewar kotha"*।
     *
     * ⓘ একটা ভেরিয়েন্সের প্রথম প্রশ্নই হলো *"কোন এন্ট্রিগুলো?"* — দরজা
     * না থাকলে পর্দাটা কেবল বিশ্বাস বা অবিশ্বাস করা যায়, **যাচাই** করা
     * যায় না।
     *
     * ⚠️ অনুমতি না থাকলে লিংক নয়। ⛔ ৪০৩-এ নিয়ে যাওয়া দরজার চেয়ে দরজা
     * না থাকা ভালো — লাভ-লোকসান রিপোর্ট আলাদা অনুমতি চায়, আর মূলধন
     * দেখতে পারা মানে সেটাও দেখতে পারা নয়।
     */
    $user = auth()->user();
    $range = ['from' => $report['from'], 'to' => $report['to']];

    $door = fn (string $permission, string $route, array $params = []) => $user?->can($permission)
        ? route($route, $params)
        : null;

    // কার মূলধনের সারিগুলো — নাম ধরে খোঁজা, কারণ তালিকাটা নামেই ছাঁকে
    $hisRows = fn (array $r) => route('finance.capital.index', ['tab' => 'entries', 'q' => $r['name']]);

    $columns = [
        ['key' => 'name', 'label' => __('finance::field.who'),
            'render' => fn ($r) => new \Illuminate\Support\HtmlString(
                '<a class="text-(--color-brand-500) underline-offset-2 hover:underline" href="'
                .e($hisRows($r)).'">'.e($r['name']).'</a>'
            )],

        ['key' => 'type', 'label' => __('finance::field.as'),
            'render' => fn ($r) => __('finance::who.'.$r['type'])],

        ['key' => 'contributed', 'label' => __('finance::field.put_in'), 'numeric' => true,
            'render' => fn ($r) => view('ui.amount-link', [
                'value' => $r['contributed'],
                'href' => $hisRows($r),
            ])],

        // তোলা টাকা → উত্তোলনের সারি, কিন্তু অনুমতি থাকলেই
        ['key' => 'withdrawn', 'label' => __('finance::field.taken_out'), 'numeric' => true,
            'render' => fn ($r) => view('ui.amount-link', [
                'value' => $r['withdrawn'],
                'href' => $door('finance.withdrawal.view', 'finance.withdrawal.index', ['q' => $r['name']]),
            ])],

        /*
         * ⓘ বাকি ঘরগুলোর পেছনে যাওয়ার মতো কিছু নেই: "দাঁড়ায়" হলো
         * পাশের দুই ঘরের বিয়োগ, আর "ভাগের টাকা" এই পর্দাতেই কষা।
         * ⚠️ ফাঁকা তালিকায় নিয়ে যাওয়া লিংকের চেয়ে সাধারণ লেখা ভালো।
         */
        ['key' => 'net', 'label' => __('finance::field.stands_at'), 'numeric' => true,
            'render' => fn ($r) => $money($r['net'])],

        ['key' => 'share', 'label' => __('finance::field.share'), 'numeric' => true,
            'render' => fn ($r) => $r['share'] === null ? '—' : rtrim(rtrim($r['share'], '0'), '.').'%'],

        ['key' => 'earned', 'label' => __('finance::investment.earned'), 'numeric' => true,
            'render' => fn ($r) => $money($r['earned'])],

        /*
         * ⓘ নিট মূলধন শূন্য বা ঋণাত্মক হলে শতাংশ বলা যায় না — যিনি সব
         * তুলে নিয়েছেন তাঁর "রিটার্ন" অসীম হত, তাই ড্যাশ।
         */
        ['key' => 'return_pct', 'label' => __('finance::investment.return_pct'), 'numeric' => true,
            'render' => fn ($r) => $r['return_pct'] === null ? '—' : $r['return_pct'].'%'],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('finance::investment.title') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('finance::investment.title')"
                          :subtitle="__('finance::investment.note')" />
    </x-slot:header>

    {{-- সময় — দুইটা তারিখ, জমা দিলেই ছাঁকে --}}
    <form method="GET" class="mb-3 flex flex-wrap items-end gap-3">
        <x-ui.field name="from" type="date" :label="__('finance::investment.from')" :value="$report['from']" />
        <x-ui.field name="to" type="date" :label="__('finance::investment.to')" :value="$report['to']" />
        <x-ui.button type="submit" tone="secondary">{{ __('finance::investment.show') }}</x-ui.button>
    </form>

    <div class="mb-3 grid gap-3 sm:grid-cols-3">
        @foreach ([
            ['label' => __('finance::investment.income'), 'value' => $report['income'],
             'href' => $door('finance.income.view', 'finance.income.index', $range)],
            ['label' => __('finance::investment.expense'), 'value' => $report['expense'],
             'href' => $door('finance.expense.view', 'finance.expense.index', $range)],
            ['label' => $loss ? __('finance::investment.loss') : __('finance::investment.profit'),
             'value' => $report['profit'],
             'tone' => $loss ? 'badge-danger-ink' : 'badge-success-ink',
             'href' => $door('accounts.report.final', 'accounts.report.show', ['slug' => 'profit-loss'] + $range)],
        ] as $card)
            <div data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                <p class="text-sm text-(--color-ink-muted)">{{ $card['label'] }}</p>

                {{-- ⓘ দরজা থাকলে সংখ্যাটাই দরজা; না থাকলে সাধারণ লেখা --}}
                <x-ui.amount :value="$card['value']"
                             :href="$card['href']"
                             :tone="$card['tone'] ?? null"
                             class="mt-1 block text-xl font-semibold" />
            </div>
        @endforeach
    </div>

    @if ($loss)
        {{-- ⓘ লোকসানে "রিটার্ন" বলে কিছু নেই — সংখ্যা না দেখিয়ে কথাটা বলা --}}
        <p role="status" class="mb-3 rounded-(--radius-field) bg-(--color-badge-warning-bg) px-3 py-2
                                text-sm text-(--color-badge-warning-ink)">
            {{ __('finance::investment.in_loss') }}
        </p>
    @endif

    @if (bccomp($report['unallocated'], '0', 4) > 0)
        {{-- ⚠️ কারও অংশ লেখা না থাকলে লাভের একটা অংশ কারও নয় — চুপ করে
             কাউকে দেওয়া হয় না, বরং কতটা বাকি সেটা বলা হয় --}}
        <p role="status" class="mb-3 rounded-(--radius-field) bg-(--color-badge-pending-bg) px-3 py-2
                                text-sm text-(--color-badge-pending-ink)">
            {{ __('finance::investment.unallocated', [
                'amount' => $money($report['unallocated']),
                'shares' => rtrim(rtrim($report['shares_total'], '0'), '.'),
            ]) }}
        </p>
    @endif

    <section data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card)">
        <h2 class="border-b border-(--color-border) bg-(--color-section-head) px-4 py-3 font-semibold">
            {{ __('finance::investment.who') }}
        </h2>

        {{--
            ⚠️ কলামগুলো অ্যাট্রিবিউটের **ভিতরে** লেখা হয় না, ২০ সেপ্টেম্বর ২০২৬।

            ⛔ দুইবার একই কামড় খেয়েছি: প্রথমে `:columns`-এর ভিতরে একটা
            মন্তব্যে ASCII ডবল-কোট, পরে নামের ঘরের `<a href="…">`-এ।
            Blade ওই কোটেই অ্যাট্রিবিউট শেষ ধরে নেয়, ট্যাগটা আর চেনে না,
            আর গোটা `<x-ui.table …>` **হুবহু লেখা হিসেবে** পাতায় ছাপা হয় —
            কোনো ভুলের খবর ছাড়াই, ২০০ সহ।

            ⭐ তাই ছকটা উপরের `@php`-তে বানানো, যেখানে কোনো অ্যাট্রিবিউট
            পার্স হয় না — ইচ্ছেমতো কোট লেখা যায়।
        --}}
        <x-ui.table :compact="true"
                    :empty="__('finance::investment.nobody')"
                    :rows="$report['rows']"
                    :columns="$columns" />
    </section>
</x-layouts.app>
