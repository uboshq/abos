{{--
    গ্রুপের হিসাব — এক মালিকের সব কোম্পানি এক পাতায়।

    ── ⭐ মালিকের প্রশ্ন, ২৫ সেপ্টেম্বর ২০২৬ ────────────────────────────
    *"এক মালিকের একাধিক কোম্পানি থাকলে কী হবে? সে তো একসাথে হিসাব
    দেখতে চাইবে।"* ⓘ আজ দেখতে হয় এক কোম্পানি করে, সুইচার দিয়ে বদলে বদলে।

    ── কেন এক সারিতে এক কোম্পানি, আর নিচে যোগফল ─────────────────────
    মালিক দুইটা প্রশ্ন একসাথে করেন: "সব মিলিয়ে কত?" আর "কোনটা টানছে,
    কোনটা টানছে না?"। যোগফল একা থাকলে দ্বিতীয় প্রশ্নের উত্তর থাকত না,
    আর সারিগুলো একা থাকলে প্রথমটার।

    ── ⚠️ কোম্পানির সারি ক্লিকযোগ্য নয়, ইচ্ছাকৃতভাবে ──────────────────
    সারিতে ক্লিক করলে ওই কোম্পানির ভিতরে যেতে হত, আর সেটা মানে কোম্পানি
    বদলানো — একটা রিপোর্ট পড়তে গিয়ে কাজের প্রসঙ্গ বদলে যাওয়া। ⓘ কোম্পানি
    বদলানোর জায়গা সুইচার, আর সেটাই একমাত্র জায়গা থাকা ভালো।
--}}
@php
    /*
        ⚠️ কলামগুলো এখানে, স্লটে নয়।

        `x-ui.table` স্লট পড়ে না — সে `:rows` আর `:columns` থেকে নিজে
        সারি আঁকে, আর প্রতিটা কলামে `key` ও `label` দুইটাই চায়।
        ⛔ প্রথম লেখায় আমি ভেতরে হাতে `<tr>` বসিয়েছিলাম, আর সেটা খালি
        অবস্থায় ঠিক চলত — ভাঙত প্রথম কোম্পানি আসামাত্র। ⓘ সম্পদের
        খাতায় ([[accounts::asset.index]]) ঠিক এই ভুলটার মন্তব্য লেখা আছে,
        আর সেটা পড়েই ধরা পড়ল।
    */
    $amount = fn (string $value) => view('accounts::report.partials.amount', ['value' => $value]);

    $columns = [
        [
            'key' => 'name',
            'label' => __('core.company.company'),
        ],
        [
            'key' => 'income',
            'label' => __('accounts::field.source_income'),
            'numeric' => true,
            'width' => '9rem',
            'render' => fn (array $r) => $amount($r['income']),
        ],
        [
            'key' => 'expense',
            'label' => __('accounts::field.expense_head'),
            'numeric' => true,
            'width' => '9rem',
            'render' => fn (array $r) => $amount($r['expense']),
        ],
        [
            'key' => 'profit',
            'label' => __('accounts::field.profit_this_year'),
            'numeric' => true,
            'width' => '9rem',
            'render' => fn (array $r) => $amount($r['profit']),
        ],
        [
            'key' => 'asset',
            'label' => __('accounts::field.assets'),
            'numeric' => true,
            'width' => '9rem',
            'render' => fn (array $r) => $amount($r['asset']),
        ],
        [
            'key' => 'liability',
            'label' => __('accounts::field.source_liability'),
            'numeric' => true,
            'width' => '9rem',
            'render' => fn (array $r) => $amount($r['liability']),
        ],
        [
            'key' => 'equity',
            'label' => __('accounts::field.source_equity'),
            'numeric' => true,
            'width' => '9rem',
            'render' => fn (array $r) => $amount($r['equity']),
        ],
    ];

    /*
        ⓘ যোগফলের সারি কম্পোনেন্টের নিজের `:totals` দিয়ে — হাতে একটা
        `<tfoot>` বসালে মোবাইলের কার্ড রূপে ওটা হারিয়ে যেত, কারণ কার্ড
        আঁকা হয় কলামের ঘোষণা থেকে।
    */
    $totals = [];

    foreach (['income', 'expense', 'profit', 'asset', 'liability', 'equity'] as $key) {
        $totals[$key] = \App\Core\Support\Money::format($group['total'][$key]);
    }
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('accounts::menu.group_report') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('accounts::menu.group_report')"
                          :subtitle="__('accounts::message.group_between', [
                              'from' => \App\Core\Support\DateFormat::format($group['from']),
                              'to' => \App\Core\Support\DateFormat::format($group['to']),
                          ])">
            <x-slot:actions>
                <x-ui.button tone="secondary" icon="print" data-action="print">
                    {{ __('core.action.print') }}
                </x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>

    <form method="GET" class="mb-4 flex flex-wrap items-end gap-3 print-hide">
        <x-ui.field name="from" type="date" :label="__('accounts::field.group_from')"
                    :value="$group['from']" />
        <x-ui.field name="to" type="date" :label="__('accounts::field.group_to')"
                    :value="$group['to']" />

        {{-- ⓘ চাবিটা `core.action.apply`, আর সেটা মেপে পাওয়া: প্রথমে
             `core.action.filter` লিখেছিলাম, আর ওটা **নেই** — আসল নামটা
             `core.toolbar.filter` ("Filter By"), যা ড্রপডাউনের লেবেল,
             বোতামের নয়। ⚠️ ভুল চাবিটা [[EveryTranslationKeyExistsTest]]
             লাল করত, তাই স্থিতিপত্রের ফরমটাই নকল করা হয়েছে। --}}
        <x-ui.button type="submit" tone="secondary">{{ __('core.action.apply') }}</x-ui.button>
    </form>

    {{-- ⚠️ একটাই কোম্পানি হলে কারণটা লেখা থাকে।

         "গ্রুপের হিসাব" নামে একটা পাতা একটামাত্র সারি দেখালে ব্যবহারকারী
         ভাবতেন বাকিগুলো হারিয়ে গেছে বা ছাঁকনি কিছু কেটে দিয়েছে। ⓘ আসল
         কথা হলো তিনি একটাই কোম্পানিতে আছেন, আর সেটা বলে দেওয়াই সৎ। --}}
    @if ($group['single'])
        <p role="note" class="mb-4 rounded-(--radius-field) bg-(--color-badge-pending-bg) px-3 py-2
                              text-sm text-(--color-badge-pending-ink)">
            {{ __('accounts::message.group_only_one') }}
        </p>
    @endif

    <x-ui.table :rows="$group['companies']"
                :columns="$columns"
                :totals="$totals"
                :totals-label="__('accounts::message.group_total')"
                :empty="__('accounts::message.group_no_companies')" />

    {{-- ── ⚠️ যা এই পাতাটা করে না, আর সেটা লেখা থাকা দরকার ──────────────
         কোম্পানিগুলোর পারস্পরিক লেনদেন এখানে **বাদ দেওয়া হয় না**।
         ⓘ ADI যদি TCL-কে টাকা দেয়, দুইটা কোম্পানিতেই সেটা বসে, আর
         যোগফলে দুইবার গোনা হয়। ⛔ সত্যিকারের consolidation-এ ওগুলো কাটা
         পড়ে, আর সেটা আন্তঃকোম্পানি লেনদেনের কাজ — যা এখনো নেই।
         তাই সীমাটা পাতাতেই লেখা থাকে, মন্তব্যে লুকানো নয়। --}}
    <p class="mt-3 text-sm text-(--color-ink-muted)">
        {{ __('accounts::message.group_not_consolidated') }}
    </p>
</x-layouts.app>
