{{--
    অডিট ট্রেইল — সব মডিউলের পরিবর্তন এক তালিকায়।

    প্রতিটা সারি একটা ঘটনা, আর নিচে ছোট করে কী কী ঘর বদলেছে তার সারাংশ।
    বিস্তারিত দেখতে সারিতে যাওয়া যায়, কিন্তু বেশিরভাগ প্রশ্নের উত্তর
    তালিকাতেই মেলে — আর সেটাই উদ্দেশ্য: প্রতিবার ভেতরে ঢুকতে হলে কেউ
    অডিট দেখতই না।
--}}
@php
    $columns = [
        ['key' => 'created_at', 'label' => __('governance::field.when'), 'width' => '12rem',
         'render' => fn ($t) => $t->created_at->format('d M Y, H:i')],
        ['key' => 'user', 'label' => __('governance::field.who'), 'width' => '11rem',
         'render' => fn ($t) => $t->user?->name ?? __('governance::message.system')],
        ['key' => 'action', 'label' => __('governance::field.action'), 'width' => '8rem',
         'render' => fn ($t) => __('governance::action.' . $t->action)],
        ['key' => 'record', 'label' => __('governance::field.record'),
         'render' => fn ($t) => view('governance::audit.partials.record', ['trail' => $t])],
        ['key' => 'changes', 'label' => __('governance::field.changes'),
         'render' => fn ($t) => view('governance::audit.partials.summary', ['trail' => $t])],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('governance::menu.audit_trail') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header
            :title="__('governance::menu.audit_trail')"
            :subtitle="trans_choice('core.count.records', $trails->total(), ['count' => $trails->total()])" />
    </x-slot:header>

    <p class="mb-4 rounded-(--radius-field) bg-(--color-surface-hover) px-3 py-2 text-2xs
              text-(--color-ink-muted)">
        {{ __('governance::message.read_only') }}
    </p>

    <div class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        {{--
            ছাঁকনিগুলো শেয়ার্ড টুলবারের ভেতরে, নিজের ফর্মে নয়।

            আগে এখানে ছয় ঘরের নিজস্ব একটা ফর্ম ছিল — দেখতে অন্য প্রতিটা
            তালিকার চেয়ে আলাদা, আর খোঁজার ঘরটাও নিজে লেখা। যে ব্যবহারকারী
            বিলের তালিকায় বাঁ প্রান্তে Filter By চাপতে শিখেছেন, তাঁকে এই
            এক পর্দার জন্য আলাদা করে শিখতে হত।

            চারটা প্রশ্ন — কে, কী কাজ, কোন মডিউল, কোন সময়ে — ফিল্টার
            প্যানেলে; খোঁজার ঘরটা টুলবারের নিজের।
        --}}
        <form method="GET" class="contents">
            <x-ui.toolbar
                :sort="$sortOptions"
                :columns="$columns" :search-placeholder="__('governance::label.search_hint')">
                <select name="user" aria-label="{{ __('governance::field.who') }}"
                        class="h-9 rounded-(--radius-field) border border-(--color-border)
                               bg-(--color-surface-app) px-2 text-sm">
                    <option value="">{{ __('governance::label.all_users') }}</option>
                    @foreach ($users as $user)
                        <option value="{{ $user->id }}" @selected(($filters['user'] ?? null) == $user->id)>
                            {{ $user->name }}
                        </option>
                    @endforeach
                </select>

                <select name="action" aria-label="{{ __('governance::field.action') }}"
                        class="h-9 rounded-(--radius-field) border border-(--color-border)
                               bg-(--color-surface-app) px-2 text-sm">
                    <option value="">{{ __('governance::label.all_actions') }}</option>
                    @foreach ($actions as $action)
                        <option value="{{ $action }}" @selected(($filters['action'] ?? null) === $action)>
                            {{ __('governance::action.' . $action) }}
                        </option>
                    @endforeach
                </select>

                <select name="module" aria-label="{{ __('governance::field.module') }}"
                        class="h-9 rounded-(--radius-field) border border-(--color-border)
                               bg-(--color-surface-app) px-2 text-sm">
                    <option value="">{{ __('governance::label.all_modules') }}</option>
                    @foreach ($modules as $code => $label)
                        <option value="{{ $code }}" @selected(($filters['module'] ?? null) === $code)>
                            {{ $label }}
                        </option>
                    @endforeach
                </select>

                <x-ui.date name="from"
                            value="{{ $filters['from'] ?? '' }}"
                            aria-label="{{ __('governance::field.from') }}"
                            class="h-9 rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-2 text-sm" />

                <x-ui.date name="to"
                            value="{{ $filters['to'] ?? '' }}"
                            aria-label="{{ __('governance::field.to') }}"
                            class="h-9 rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-2 text-sm" />
                            </x-ui.toolbar>
        </form>

        <x-ui.table
            :compact="request()->boolean('compact')"
            :empty="array_filter($filters) ? __('governance::message.none') : __('governance::message.nothing_yet')"
            :rows="$trails"
            :columns="$columns" />

        @if ($trails->hasPages())
            <div class="border-t border-(--color-border) px-3 py-2">{{ $trails->links() }}</div>
        @endif
    </div>
</x-layouts.app>
