{{--
    ভুলের খাতা — ব্যবস্থাটা নিজে যা লিখে রেখেছে।

    ── কেন একই ভুল একটাই সারি ─────────────────────────────────────────
    একটা ভাঙা পাতা পঞ্চাশ জন রিফ্রেশ করলে পাঁচশো সারি বসত, আর তার নিচে
    চাপা পড়ত সেই একটা ভিন্ন ভুল যেটা সত্যিই নতুন। তাই একই শ্রেণি + ফাইল
    + লাইন = একটাই সারি, আর "কতবার" একটা কলাম।

    ── কেন মোছার বোতাম নেই ────────────────────────────────────────────
    মুছতে দিলে যে ভুলটা কেউ বুঝতে পারেনি সেটাই সবার আগে মুছে যেত।
    "দেখেছি" বললে তালিকা পরিষ্কার হয়, ইতিহাস থাকে।
--}}
@php
    $columns = [
        ['key' => 'last_seen_at', 'label' => __('governance::field.when'), 'width' => '11rem',
         'render' => fn ($r) => $r->last_seen_at?->format('d M Y, H:i')],

        /*
         * শ্রেণির ছোট নাম, আর নিচে বার্তাটা।
         *
         * পুরো namespace দেখালে প্রতিটা সারির অর্ধেক জায়গা
         * `Illuminate\Database\Eloquent\` জাতীয় লেখায় চলে যেত, আর
         * যেটা আলাদা করে চেনায় সেই শেষ শব্দটাই কাটা পড়ত।
         */
        ['key' => 'class', 'label' => __('governance::field.what_broke'),
         'render' => fn ($r) => new \Illuminate\Support\HtmlString(
             '<span class="font-medium">'.e($r->shortClass()).'</span>'
             .'<br><span class="text-2xs text-(--color-ink-muted)">'
             .e(\Illuminate\Support\Str::limit($r->message, 140)).'</span>')],

        ['key' => 'where', 'label' => __('governance::field.where_in_code'), 'width' => '18rem',
         'render' => fn ($r) => new \Illuminate\Support\HtmlString(
             '<span class="text-2xs">'.e($r->shortFile()).($r->line ? ':'.$r->line : '').'</span>'
             .($r->path ? '<br><span class="text-2xs text-(--color-ink-muted)">'
                 .e($r->method.' '.$r->path).'</span>' : ''))],

        /*
         * কতবার — সংখ্যাটাই বলে দেয় জিনিসটা নতুন না পুরনো।
         *
         * একবার মানে হয়তো কারও একটা অদ্ভুত ক্লিক; দুইশোবার মানে
         * একটা পর্দা কারও জন্যই খুলছে না।
         */
        ['key' => 'times', 'label' => __('governance::field.how_many_times'), 'width' => '7rem',
         'render' => fn ($r) => $r->times > 1
             ? new \Illuminate\Support\HtmlString(
                 '<span class="rounded-(--radius-pill) bg-(--color-badge-danger-bg) px-2 py-0.5 '
                 .'text-2xs font-medium text-(--color-badge-danger-ink)">'.e($r->times).'</span>')
             : '1'],

        ['key' => 'seen', 'label' => '', 'width' => '7rem',
         'render' => fn ($r) => $r->acknowledged_at
             ? new \Illuminate\Support\HtmlString(
                 '<span class="text-2xs text-(--color-ink-muted)">'
                 .e(__('governance::message.seen_by', ['name' => $r->acknowledger?->name ?? '—'])).'</span>')
             : view('governance::error.seen-button', ['row' => $r])],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('governance::menu.error_log') }}</x-slot:title>

    <p class="mb-4 rounded-(--radius-field) bg-(--color-surface-hover) px-3 py-2 text-2xs
              text-(--color-ink-muted)">
        {{ __('governance::message.error_why') }}
    </p>

    {{--
        গত চব্বিশ ঘণ্টায় কয়টা — শূন্য হলে দেখানোই হয় না।

        রোজ "০টি ভুল" দেখলে সংখ্যাটা অদৃশ্য হয়ে যায়, আর যেদিন ১৭ হবে
        সেদিনও চোখে পড়ত না।
    --}}
    @if ($freshCount > 0)
        <p class="mb-4 flex items-center gap-2 rounded-(--radius-field) bg-(--color-badge-danger-bg)
                  px-3 py-2 text-sm text-(--color-badge-danger-ink)">
            <x-ui.icon name="alert-triangle" :size="16" />
            {{ __('governance::message.errors_today', ['count' => $freshCount]) }}
        </p>
    @endif

    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form method="GET" class="contents">
            {{-- খাতাটা রপ্তানি হয় না — ভিতরের পথ ও ট্রেস বাইরে যাওয়ার জিনিস নয় --}}
            <x-ui.toolbar :title="__('governance::menu.error_log')"
                          :count="trans_choice('core.count.records', $rows->total(), ['count' => $rows->total()])"
                          :export="false" :search="false">
                <label class="flex items-center gap-1.5 text-sm">
                    <input type="checkbox" name="only" value="all" @checked(request('only') === 'all')
                           onchange="this.form.submit()">
                    {{ __('governance::action.show_seen_too') }}
                </label>
            </x-ui.toolbar>
        </form>

        @if ($rows->isEmpty())
            <x-ui.empty-state :message="__('governance::message.no_errors')" />
        @else
            <x-ui.table :rows="$rows" :columns="$columns" />
        @endif
    </div>

    <x-ui.pager :rows="$rows" />
</x-layouts.app>
