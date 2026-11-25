{{--
    ঢোকার খাতা — কে ঢুকল, আর কে ঢুকতে চেয়ে পারল না।

    ── কেন ব্যর্থ চেষ্টাগুলো আলাদা করে দেখানো ─────────────────────────
    সফল ঢোকা রোজকার ঘটনা; একশো সারির নিরানব্বইটা। যেটা দেখা দরকার সেটা
    একই নামে পঁচিশটা ব্যর্থ চেষ্টা এক ঘণ্টায়, আর সেটা সফলগুলোর ভিড়ে
    হারিয়ে যায়।
--}}
@php
    $columns = [
        ['key' => 'created_at', 'label' => __('governance::field.when'), 'width' => '12rem',
         'render' => fn ($r) => \App\Core\Support\DateFormat::formatWithTime($r->created_at)],

        ['key' => 'who', 'label' => __('governance::field.who'), 'width' => '12rem',
         'render' => fn ($r) => view('governance::partials.user-link', [
             'id' => $r->user_id, 'label' => $r->who(),
         ])],

        /*
         * ফল — রঙ একা যথেষ্ট নয়, লেখাও থাকে।
         *
         * রঙ-অন্ধ পাঠকের কাছে সবুজ আর লাল ব্যাজ একই, আর এই পর্দায়
         * ওই তফাতটাই একমাত্র জিনিস যা দেখা হয়।
         */
        ['key' => 'succeeded', 'label' => __('governance::field.result'), 'width' => '8rem',
         'render' => fn ($r) => new \Illuminate\Support\HtmlString(
             '<span class="rounded-(--radius-pill) px-2 py-0.5 text-2xs font-medium '
             .($r->succeeded
                 ? 'bg-(--color-badge-success-bg) text-(--color-badge-success-ink)">'
                     .e(__('governance::message.got_in'))
                 : 'bg-(--color-badge-danger-bg) text-(--color-badge-danger-ink)">'
                     .e(__('governance::message.refused')))
             .'</span>')],

        /*
         * কেন ব্যর্থ — পর্দা যা কোনোদিন বলে না।
         *
         * লগইনের পাতা তিনটা ক্ষেত্রেই একই বার্তা দেয়, নাহলে কেউ
         * ব্যবহারকারীর তালিকা বের করে ফেলত। কিন্তু এখানে আসল কারণটাই
         * লেখা, কারণ অচেনা নামে পঁচিশটা চেষ্টা আর চেনা নামে পঁচিশটা —
         * দুইটা সম্পূর্ণ আলাদা ঘটনা।
         */
        ['key' => 'reason', 'label' => __('governance::field.why'), 'width' => '10rem',
         'render' => fn ($r) => $r->reason ? __('governance::message.why_'.$r->reason) : ''],

        ['key' => 'ip_address', 'label' => __('governance::field.where_from'), 'width' => '10rem'],

        /*
         * ⭐ জায়গা — মালিক, ১ অক্টোবর ২০২৬: *"jaygar nam soho dekhabe, zemon bridge more, mymensingh"*।
         * ⓘ ব্রাউজার লোকেশন দিলে নাম ([[LoginPlace]]) আর ম্যাপের লিংক; নাম তখনো না এলে কেবল লিংক।
         * না দিলে "দেয়নি" — খালি ঘর দেখে কেউ ভাববেন না যে ব্যবস্থাটা ভাঙা।
         */
        ['key' => 'place', 'label' => __('governance::field.place'), 'width' => '16rem',
         'render' => fn ($r) => $r->latitude === null
             ? new \Illuminate\Support\HtmlString('<span class="text-(--color-ink-muted)">'.e(__('governance::message.no_place')).'</span>')
             : new \Illuminate\Support\HtmlString(
                 e($r->place ?: __('governance::message.place_pending'))
                 .' <a class="text-(--color-brand-600) underline-offset-4 hover:underline" target="_blank" rel="noopener noreferrer" href="'
                 .e('https://www.openstreetmap.org/?mlat='.$r->latitude.'&mlon='.$r->longitude.'#map=17/'.$r->latitude.'/'.$r->longitude)
                 .'">'.e(__('governance::message.on_map')).'</a>'
                 .($r->accuracy_m ? ' <span class="text-(--color-ink-muted)">±'.e((string) $r->accuracy_m).' '.e(__('governance::message.metres')).'</span>' : ''))],
    ];

    // ⭐ ছাঁকনি ডান পাশের প্যানেলে — আগের ঘরগুলো, একই চাবি (মালিক, ২৩ নভেম্বর ২০২৬; [[Facets]])
    $facetPanel = \App\Core\ListFilter\Facets::panel([
        \App\Core\ListFilter\Facets::pick('user', __('governance::field.who'),
            collect($users)->mapWithKeys(fn ($u) => [(string) $u->id => $u->name])->all(), __('governance::field.who')),
        \App\Core\ListFilter\Facets::flag('only', __('governance::action.only_failed'), 'failed'),
        \App\Core\ListFilter\Facets::dates(__('core.list_filter.date')),
    ], null, ['from' => $dates['from'] ?? null, 'to' => $dates['to'] ?? null]);
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('governance::menu.login_history') }}</x-slot:title>

    @unless (\App\Core\Support\AbosHead::on())
    <p class="mb-4 rounded-(--radius-field) bg-(--color-surface-hover) px-3 py-2 text-2xs
              text-(--color-ink-muted)">
        {{ __('governance::message.login_why') }}
    </p>
    @endunless

    {{--
        গত চব্বিশ ঘণ্টার ব্যর্থ চেষ্টা — পাতার মাথায়, একটা সংখ্যা।

        কেউ এই পর্দায় রোজ আসে না। যেদিন আসে, প্রথম প্রশ্নটা "কিছু
        অস্বাভাবিক ঘটছে কি না" — আর সেই উত্তরটা তালিকা পড়ে বের করতে হলে
        বেশিরভাগ দিন কেউ বের করত না।

        শূন্য হলে দেখানোই হয় না: "০টি ব্যর্থ চেষ্টা" প্রতিদিন দেখলে
        সংখ্যাটা অদৃশ্য হয়ে যায়, আর যেদিন ২৫ হবে সেদিনও চোখে পড়ত না।
    --}}
    @unless (\App\Core\Support\AbosHead::on())
        @include('governance::login.partials.failed')
    @endunless

    <x-ui.filter-layout :filters="$facetPanel" form="list-form">
    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form id="list-form" method="GET" class="contents">
            {{-- খাতাটা নিজে রপ্তানি হয় না — রপ্তানির খাতার একই কারণে --}}
            <x-ui.toolbar :title="__('governance::menu.login_history')" :info="\App\Core\Support\AbosHead::on() ? __('governance::message.login_why') : null" :count="trans_choice('core.count.records', $rows->total(), ['count' => $rows->total()])" :export="false" :print="false" :share="false"
                          :search-placeholder="__('governance::field.who').', '.__('governance::field.ip')"
                          :sort="['newest' => __('core.notify.sort.newest'), 'oldest' => __('core.notify.sort.oldest')]" :facets="$facetPanel">
            </x-ui.toolbar>
        </form>
        {{-- ⭐ আগে মাথা, বাক্স তার নিচে (মালিকের নিয়ম; তিন ABOS রূপে) --}}
        @if ($failedToday > 0 && \App\Core\Support\AbosHead::on())
            <div class="px-3 pt-3">@include('governance::login.partials.failed')</div>
        @endif

        @if ($rows->isEmpty())
            <x-ui.empty-state :message="__('governance::message.no_logins')" />
        @else
            <x-ui.table :rows="$rows" :columns="$columns" />
        @endif
    </div>

    <x-ui.pager :rows="$rows" />
    <x-ui.list-totals :rows="$rows" />
    </x-ui.filter-layout>
</x-layouts.app>
