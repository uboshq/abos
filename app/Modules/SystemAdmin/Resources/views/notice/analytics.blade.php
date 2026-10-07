{{--
    নোটিশের হিসাব — কতজন পড়েছেন, কতজন মেনেছেন।

    ── ⚠️ কেন শতাংশটা রক্ষণশীল ─────────────────────────────────────────
    হরটা *"যতজন ছুঁয়েছেন"*, *"যতজনের পাওয়ার কথা"* নয় — ⓘ দ্বিতীয়টা বের
    করতে হাজার ব্যবহারকারীর চাবি বানাতে হত। ⛔ ফলে সংখ্যাটা কম দেখাতে
    পারে, কিন্তু ভুয়া "সবাই মেনেছেন" কখনো দেখাবে না।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('core.notice.analytics_title') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('core.notice.analytics_title')" />
    </x-slot:header>

    {{-- অবস্থা ধরে গোনা — শূন্যগুলোও দেখানো হয়, কারণ ফাঁকা জায়গা
         শূন্যের চেয়ে খারাপ উত্তর --}}
    <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ($byStatus as $status => $many)
            <div data-boxed
                 class="rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card) px-4 py-3">
                <div class="text-xs text-(--color-ink-muted)">
                    {{ __('core.notice.status.'.$status) }}
                </div>
                <div class="num text-lg font-semibold">{{ $many }}</div>
            </div>
        @endforeach
    </div>

    <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <div class="border-b border-(--color-border) px-4 py-3">
            <h2 class="font-semibold">{{ __('core.notice.waiting_on_signatures') }}</h2>
        </div>

        <x-ui.table
            :empty="__('core.notice.everyone_signed')"
            :rows="$waiting"
            compact
            :columns="[
                ['key' => 'document_no', 'label' => __('core.table.code'), 'width' => '10rem'],
                ['key' => 'title', 'label' => __('core.table.name')],
                ['key' => 'priority', 'label' => __('core.notice.priority_label'), 'width' => '9rem',
                 'render' => fn ($r) => $r->priority?->label() ?? '—'],
                ['key' => 'read', 'label' => __('core.notice.read_count'), 'numeric' => true, 'width' => '7rem',
                 'render' => fn ($r) => $r->reads_count],
                ['key' => 'signed', 'label' => __('core.notice.signed_count'), 'numeric' => true, 'width' => '7rem',
                 'render' => fn ($r) => $r->signatures_count],
                ['key' => 'rate', 'label' => __('core.notice.sign_rate'), 'numeric' => true, 'width' => '7rem',
                 'render' => fn ($r) => $numbers->reachOf($r)['rate'].'%'],
            ]" />
    </section>
</x-layouts.app>
