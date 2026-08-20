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
         'render' => fn ($r) => $r->created_at?->format('d M Y, H:i')],

        ['key' => 'who', 'label' => __('governance::field.who'), 'width' => '12rem',
         'render' => fn ($r) => $r->who()],

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
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('governance::menu.login_history') }}</x-slot:title>

    <p class="mb-4 rounded-(--radius-field) bg-(--color-surface-hover) px-3 py-2 text-2xs
              text-(--color-ink-muted)">
        {{ __('governance::message.login_why') }}
    </p>

    {{--
        গত চব্বিশ ঘণ্টার ব্যর্থ চেষ্টা — পাতার মাথায়, একটা সংখ্যা।

        কেউ এই পর্দায় রোজ আসে না। যেদিন আসে, প্রথম প্রশ্নটা "কিছু
        অস্বাভাবিক ঘটছে কি না" — আর সেই উত্তরটা তালিকা পড়ে বের করতে হলে
        বেশিরভাগ দিন কেউ বের করত না।

        শূন্য হলে দেখানোই হয় না: "০টি ব্যর্থ চেষ্টা" প্রতিদিন দেখলে
        সংখ্যাটা অদৃশ্য হয়ে যায়, আর যেদিন ২৫ হবে সেদিনও চোখে পড়ত না।
    --}}
    @if ($failedToday > 0)
        <p class="mb-4 flex items-center gap-2 rounded-(--radius-field) bg-(--color-badge-danger-bg)
                  px-3 py-2 text-sm text-(--color-badge-danger-ink)">
            <x-ui.icon name="alert-triangle" :size="16" />
            {{ __('governance::message.failed_today', ['count' => $failedToday]) }}
        </p>
    @endif

    <div class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form method="GET" class="contents">
            {{-- খাতাটা নিজে রপ্তানি হয় না — রপ্তানির খাতার একই কারণে --}}
            <x-ui.toolbar :title="__('governance::menu.login_history')" :count="trans_choice('core.count.records', $rows->total(), ['count' => $rows->total()])" :export="false" :search="false">
                <select name="user" aria-label="{{ __('governance::field.who') }}"
                        class="h-(--spacing-field-compact) rounded-(--radius-field) border border-(--color-border)
                               bg-(--color-surface-app) px-2 text-sm">
                    <option value="">{{ __('governance::field.who') }}</option>
                    @foreach ($users as $user)
                        <option value="{{ $user->id }}" @selected(request('user') == $user->id)>
                            {{ $user->name }}
                        </option>
                    @endforeach
                </select>

                <label class="flex items-center gap-1.5 text-sm">
                    <input type="checkbox" name="only" value="failed" @checked(request('only') === 'failed')
                           onchange="this.form.submit()">
                    {{ __('governance::action.only_failed') }}
                </label>

                <label>
                    <span class="sr-only">{{ __('core.table.from_date') }}</span>
                    <x-ui.date name="from"
                               value="{{ request('from') }}"
                               class="h-(--spacing-field-compact) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-2 text-sm" />
                               </label>

                <label>
                    <span class="sr-only">{{ __('core.table.to_date') }}</span>
                    <x-ui.date name="to"
                               value="{{ request('to') }}"
                               class="h-(--spacing-field-compact) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-2 text-sm" />
                               </label>
                               </x-ui.toolbar>
        </form>

        @if ($rows->isEmpty())
            <x-ui.empty-state :message="__('governance::message.no_logins')" />
        @else
            <x-ui.table :rows="$rows" :columns="$columns" />
        @endif
    </div>

    @if ($rows->hasPages())
        <div class="mt-4">{{ $rows->links() }}</div>
    @endif
</x-layouts.app>
