{{--
    রপ্তানির খাতা — কে কোন তালিকা নামিয়ে নিয়ে গেছে।

    ── কেন সারিতেই সব ─────────────────────────────────────────────────
    প্রশ্নটা ওঠে নির্দিষ্ট একটা মুহূর্তে: কেউ চাকরি ছাড়ল, বা দর ফাঁস
    হলো। তখন কেউ বিশটা সারিতে ক্লিক করে বেড়ায় না — তাকিয়েই বুঝতে হয়
    কোনটা স্বাভাবিক আর কোনটা নয়। তাই ছাঁকনি ও সারির সংখ্যা দুইটাই
    তালিকাতেই, আলাদা কোনো পাতায় নয়।
--}}
@php
    $columns = [
        ['key' => 'created_at', 'label' => __('governance::field.when'), 'width' => '12rem',
         'render' => fn ($r) => $r->created_at?->format('d M Y, H:i')],
        ['key' => 'user', 'label' => __('governance::field.who'), 'width' => '11rem',
         'render' => fn ($r) => $r->who()],
        ['key' => 'title', 'label' => __('governance::field.what_was_taken'),
         'render' => fn ($r) => $r->title ?: $r->route],

        /*
         * কয়টা সারি — সবচেয়ে বেশি যেটা দেখে সন্দেহ হয়।
         *
         * দশ সারির একটা ফাইল রোজকার কাজ; দশ হাজার সারির ফাইল একটা
         * প্রশ্ন। দুইটাই "একটা রপ্তানি" লিখলে তফাতটা হারিয়ে যেত।
         */
        ['key' => 'row_count', 'label' => __('governance::field.rows'), 'width' => '7rem',
         'render' => fn ($r) => number_format($r->row_count)],

        ['key' => 'filters', 'label' => __('governance::field.filters'),
         'render' => fn ($r) => $r->filterSummary()],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('governance::menu.export_log') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header
            :title="__('governance::menu.export_log')"
            :subtitle="trans_choice('core.count.records', $rows->total(), ['count' => $rows->total()])" />
    </x-slot:header>

    <p class="mb-4 rounded-(--radius-field) bg-(--color-surface-hover) px-3 py-2 text-2xs
              text-(--color-ink-muted)">
        {{ __('governance::message.export_why') }}
    </p>

    <div class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form method="GET" class="contents">
            {{--
                রপ্তানির খাতাটা নিজে রপ্তানি হয় না।

                ── কেন ─────────────────────────────────────────────────
                হলে প্রতিবার নামানোর ফলে খাতায় আরেকটা সারি বসত, আর
                সেই সারিটাও রপ্তানিযোগ্য — একটা খাতা যা নিজের দিকেই
                তাকিয়ে বাড়ে। তার চেয়েও বড় কথা: যিনি নিজের চিহ্ন
                ঢাকতে চান, তাঁর প্রথম কাজই হত পুরো খাতাটা নামিয়ে দেখা
                কী কী ধরা পড়েছে।
            --}}
            <x-ui.toolbar :export="false" :search="false">
                <select name="user" aria-label="{{ __('governance::field.who') }}"
                        class="h-9 rounded-(--radius-field) border border-(--color-border)
                               bg-(--color-surface-app) px-2 text-sm">
                    <option value="">{{ __('governance::field.who') }}</option>
                    @foreach ($users as $user)
                        <option value="{{ $user->id }}" @selected(request('user') == $user->id)>
                            {{ $user->name }}
                        </option>
                    @endforeach
                </select>

                <select name="route" aria-label="{{ __('governance::field.what_was_taken') }}"
                        class="h-9 rounded-(--radius-field) border border-(--color-border)
                               bg-(--color-surface-app) px-2 text-sm">
                    <option value="">{{ __('governance::field.what_was_taken') }}</option>
                    @foreach ($routes as $route)
                        <option value="{{ $route }}" @selected(request('route') === $route)>{{ $route }}</option>
                    @endforeach
                </select>

                <label class="flex items-center gap-1 text-sm">
                    <span class="sr-only">{{ __('core.table.from_date') }}</span>
                    <x-ui.date name="from"
                               value="{{ request('from') }}"
                               class="h-9 rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-2 text-sm" />
                               </label>

                <label class="flex items-center gap-1 text-sm">
                    <span class="sr-only">{{ __('core.table.to_date') }}</span>
                    <x-ui.date name="to"
                               value="{{ request('to') }}"
                               class="h-9 rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-2 text-sm" />
                               </label>
                               </x-ui.toolbar>
        </form>

        @if ($rows->isEmpty())
            <x-ui.empty-state :message="__('governance::message.nothing_exported')" />
        @else
            <x-ui.table :rows="$rows" :columns="$columns" />
        @endif
    </div>

    @if ($rows->hasPages())
        <div class="mt-4">{{ $rows->links() }}</div>
    @endif
</x-layouts.app>
