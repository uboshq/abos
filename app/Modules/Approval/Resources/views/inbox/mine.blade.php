{{--
    আমার অনুরোধ — নতুন আগে।

    এখানে যিনি আসেন তিনি জানতে চান "আমারটার কী হলো", আর সেটা সাধারণত
    সবচেয়ে শেষ অনুরোধটা।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('approval::menu.mine') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header
            :title="__('approval::menu.mine')"
            :subtitle="trans_choice('core.count.records', $approvals->total(), ['count' => $approvals->total()])" />
    </x-slot:header>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    <div class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <x-ui.table
            :empty="__('approval::message.no_requests')"
            :rows="$approvals"
            :compact="request()->boolean('compact')"
            :columns="[
                ['key' => 'requested_at', 'label' => __('approval::field.requested_at'), 'width' => '11rem',
                 'render' => fn ($a) => $a->requested_at?->format('d M Y, H:i')],
                ['key' => 'module', 'label' => __('approval::field.action'),
                 'render' => fn ($a) => view('approval::inbox.partials.what', ['approval' => $a, 'labels' => $labels])],
                ['key' => 'amount', 'label' => __('approval::field.amount'), 'numeric' => true, 'width' => '9rem',
                 'render' => fn ($a) => $a->amount === null ? '—' : \App\Core\Support\Money::format($a->amount)],
                ['key' => 'status', 'label' => __('approval::field.status'), 'width' => '8rem',
                 'render' => fn ($a) => view('approval::inbox.partials.status', ['approval' => $a])],
                ['key' => 'open', 'label' => '', 'width' => '7rem',
                 'render' => fn ($a) => view('approval::inbox.partials.open', ['approval' => $a])],
            ]" />

        @if ($approvals->hasPages())
            <div class="border-t border-(--color-border) px-3 py-2">{{ $approvals->links() }}</div>
        @endif
    </div>
</x-layouts.app>
