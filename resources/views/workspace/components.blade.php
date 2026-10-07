{{--
    কম্পোনেন্ট গ্যালারি — /components

    সব শেয়ার্ড কম্পোনেন্ট এক পাতায়। উদ্দেশ্য দুটো: নতুন স্ক্রিন লেখার সময়
    কী কী আছে দেখে নেওয়া, আর কোনো কম্পোনেন্ট বদলালে চার প্রস্থে চোখে দেখে
    নেওয়া (সেকশন ২০.৮)।

    v9-এর Design System-এর ভিত্তি — সেকশন ১৫.২৭।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('core.components.title') }}</x-slot:title>

    <div class="space-y-4">

        {{-- বাটন --}}
        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="mb-3 font-semibold">{{ __('core.components.buttons') }}</h2>
            <div class="flex flex-wrap gap-2">
                @foreach (['primary', 'success', 'warning', 'danger', 'secondary', 'ghost'] as $tone)
                    <x-ui.button :tone="$tone">{{ ucfirst($tone) }}</x-ui.button>
                @endforeach
            </div>
        </section>

        {{-- ব্যাজ --}}
        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="mb-3 font-semibold">{{ __('core.components.badges') }}</h2>
            <div class="flex flex-wrap items-center gap-2">
                @foreach (\App\Core\Support\DocumentStatus::all() as $status)
                    <x-ui.status-badge :status="$status" />
                @endforeach
                <x-ui.badge tone="inventory">{{ __('core.source.purchase_invoice') }}</x-ui.badge>
                <x-ui.badge tone="info">{{ __('core.company.branch') }}</x-ui.badge>
            </div>
        </section>

        {{-- টেবিল --}}
        <section data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
            <x-ui.toolbar :title="__('core.components.title')" :count="__('core.components.subtitle')">
        <x-slot:actions>
            <x-ui.button tone="secondary" icon="refresh">{{ __('core.action.history') }}</x-ui.button>
                <x-ui.button tone="primary" icon="plus">{{ __('core.action.create') }}</x-ui.button>
        </x-slot:actions>
        </x-ui.toolbar>

            <x-ui.table
                :columns="[
                    ['key' => 'date', 'label' => __('core.table.date'), 'width' => '8rem'],
                    ['key' => 'document', 'label' => __('core.table.document')],
                    ['key' => 'party', 'label' => __('core.table.party')],
                    ['key' => 'debit', 'label' => __('core.table.debit'), 'numeric' => true],
                    ['key' => 'credit', 'label' => __('core.table.credit'), 'numeric' => true],
                ]"
                :rows="$sampleRows" />
        </section>

        {{-- ফাঁকা অবস্থা --}}
        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
            <x-ui.empty-state>
                <x-ui.button tone="primary">{{ __('core.action.create') }}</x-ui.button>
                <x-ui.button tone="secondary">{{ __('core.action.help') }}</x-ui.button>
            </x-ui.empty-state>
        </section>
    </div>
</x-layouts.app>
