{{--
    একটা রেকর্ডের পুরো ইতিহাস — তৈরি থেকে আজ পর্যন্ত।

    ── কেন এই পর্দাটা আলাদা ────────────────────────────────────────────
    মূল তালিকা প্রশ্নের উত্তর দেয় "আজ কে কী করেছে"। এই পর্দাটা উল্টো
    প্রশ্নের: "এই বিলটার সাথে কী কী হয়েছে"। দুইটা আলাদা প্রশ্ন, আর
    একটার উত্তর দিয়ে অন্যটা খুঁজতে গেলে ছাঁকনি নিয়ে ধস্তাধস্তি করতে হত।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $trail->title() }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header
            :title="$trail->title()"
            :subtitle="$trail->moduleLabel() . ' · ' . trans_choice('core.count.records', $history->total(), ['count' => $history->total()])">
            <x-slot:actions>
                <x-ui.button :href="route('governance.audit.index')">
                    {{ __('governance::label.back_to_trail') }}
                </x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>

    @if ($record === null)
        <p class="mb-4 rounded-(--radius-field) bg-(--color-surface-hover) px-3 py-2 text-2xs
                  text-(--color-ink-muted)">
            {{ __('governance::message.record_gone') }}
        </p>
    @endif

    <div class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <x-ui.table
            :empty="__('governance::message.nothing_yet')"
            :rows="$history"
            :columns="[
                ['key' => 'created_at', 'label' => __('governance::field.when'), 'width' => '12rem',
                 'render' => fn ($t) => $t->created_at->format('d M Y, H:i')],
                ['key' => 'user', 'label' => __('governance::field.who'), 'width' => '11rem',
                 'render' => fn ($t) => $t->user?->name ?? __('governance::message.system')],
                ['key' => 'action', 'label' => __('governance::field.action'), 'width' => '8rem',
                 'render' => fn ($t) => __('governance::action.' . $t->action)],
                ['key' => 'changes', 'label' => __('governance::field.changes'),
                 'render' => fn ($t) => view('governance::audit.partials.summary', ['trail' => $t])],
                ['key' => 'open', 'label' => '—', 'width' => '6rem',
                 'render' => fn ($t) => view('governance::audit.partials.open', ['trail' => $t])],
            ]" />
    </div>

    <div class="mt-4">{{ $history->links() }}</div>
</x-layouts.app>
