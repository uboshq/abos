{{--
    ⭐ অনুমোদনের ইনবক্স — এক পাতায় বাঁয়ে তালিকা, ডানে কাগজ (অনুমোদন পর্দা ভাগ ২; মালিকের "হ্যাঁ", ২১ অক্টোবর ২০২৬;
    SAP Fiori "My Inbox", পরিকল্পনার পাতা ২)।

    বাঁয়ে: চার ট্যাব আর সংখ্যা (দফা ২) · কাজ, শাখা, অঙ্ক, দেরি আর সাজানো (দফা ৩) · কার্ড — পক্ষ, শেষ সময়, ধাপ (দফা ৫, ৭, ৮) ·
    নিচে এই পাতার মোট, সবগুলোর মোট আর পাতা বদল (দফা ৬)।
    ডানে: বেছে নেওয়া কাগজ ([[partials/detail]]) — অনুমোদন দিলে পরের কাগজ নিজে খোলে (দফা ১)।

    ⛔ আগে: ১১ কলামের টেবিল, ৫০-এ কাটা, মোট নেই; কাগজ খুলতে আলাদা পাতা, সিদ্ধান্তের পরে আবার তালিকায় ফিরে পরেরটা খুঁজতে হত।

    ⭐ ছোট পর্দায় (lg-এর নিচে) আগে তালিকা, কার্ড ছুঁলে কাগজ — একবারে একটা (দফা ১ আর ২১: "ফোনে আগে তালিকা, তারপর বিস্তারিত";
    পরিকল্পনার পাতা ৬)। কাগজের মাথায় "‹ ইনবক্স · ২ / ৩" তালিকায় ফেরায়; অনুমোদনের পরে পরের কাগজ নিজে খোলে, তাই ফোনেও।
    ⛔ আগে ফোনে তালিকার নিচে প্রথম কাগজটা পুরো আঁকা থাকত — কার্ড ছুঁলে পাতা নতুন করে খুলত আর কাগজ খুঁজতে নিচে নামতে হত।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('approval::menu.inbox') }}</x-slot:title>

    @php
        $canPick = array_flip($bulkable);
        $keep = array_filter([
            'person' => $person ?: null,
            'tab' => $tab !== 'waiting' ? $tab : null,
        ]);
        $filterKeep = array_filter($keep + [
            'branch' => $branch ?: null,
            'amount' => $band !== '' ? $band : null,
            'sort' => $sort,
            'q' => request('q') ?: null,
        ]);
        $from = http_build_query(array_filter(request()->only(['tab', 'module', 'branch', 'amount', 'late', 'sort', 'q', 'page'])));
        $rows = $approvals->getCollection();
        $at = $opened !== null ? $rows->search(fn ($a) => (int) $a->id === (int) $opened->id) : false;
        $prevUrl = $at !== false && $at > 0 ? request()->fullUrlWithQuery(['open' => $rows[$at - 1]->id]) : null;
        $nextUrl = $at !== false && isset($rows[$at + 1]) ? request()->fullUrlWithQuery(['open' => $rows[$at + 1]->id]) : null;
        // ⓘ ফোনে কাগজ কেবল তখন, যখন কেউ বেছে খুলেছেন (`?open=`); নিজে বেছে নেওয়া প্রথম কাগজটা বড় পর্দার ডান দিকের জন্য
        $picked = request()->filled('open') && $detail !== null;
        $backUrl = request()->fullUrlWithoutQuery(['open']);
        $summary = $tab === 'waiting'
            ? __('approval::message.inbox_summary', ['count' => $visibleTotal, 'late' => $lateCount, 'total' => '৳'.\App\Core\Support\Money::format($sumAll)])
            : __('approval::message.tab_summary', ['count' => $visibleTotal, 'total' => '৳'.\App\Core\Support\Money::format($sumAll)]);
    @endphp

    {{-- ⭐ রঙিন মাথা — লাইভের মাথাটাই (নাম, এক লাইনের হিসাব, একসাথে অনুমোদন), অনুমোদনের রঙে ([[money.page]]; মালিক, ৭ নভেম্বর ২০২৬:
         "অনুমোদনের ইনবক্স ekhon zemon live e ache temoi thakbe setake just colorfull koro", নমুনা "ok")। --}}
    @if ($board ?? false)
        <div data-page-kind="approval" data-inbox-board-page>
            @include('approval::inbox.partials.body')
        </div>
    @else
    <x-money.page kind="approval" :title="$person ? __('approval::menu.inbox_of', ['name' => $personName]) : __('approval::menu.inbox')"
        icon="approval" :count="$summary">
        <x-slot:actions>
                {{-- ⭐ একসাথে অনুমোদন — মালিকের সিদ্ধান্ত ৫; ⛔ টাকা নড়ে এমন কাগজে চেকবক্সই আঁকা হয় না। ⓘ আগে দেখার পাতা (দফা ১৩)
                     সংখ্যা, মোট আর বাদ পড়ার কারণ দেখায় — সেখান থেকেই আসল অনুমোদন। --}}
                {{-- ⓘ on the board the button sits in the list's own pick bar ([[board-list]]) — one button, never two --}}
                @if ($bulkable !== [] && ! ($board ?? false))
                    <x-ui.button type="submit" tone="secondary" form="bulk-form" data-bulk-button
                                 data-label="{{ __('approval::action.bulk_picked', ['count' => '__N__']) }}">
                        {{ __('approval::action.bulk_picked', ['count' => 0]) }}
                    </x-ui.button>
                @endif
        </x-slot:actions>

    @include('approval::inbox.partials.body')
    </x-money.page>
    @endif
</x-layouts.app>
