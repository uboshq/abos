{{--
    আমার সিদ্ধান্তের অপেক্ষায়।

    পুরনোটা উপরে — যেটা সবচেয়ে বেশিক্ষণ ঝুলে আছে সেটাই সবচেয়ে বেশি
    কাউকে আটকে রেখেছে। নতুনটা উপরে রাখলে পুরনো অনুরোধগুলো নিচে চাপা
    পড়ত, আর ঠিক ওগুলোই মানুষ ভুলে যায়।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('approval::menu.inbox') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header
            :title="__('approval::menu.inbox')"
            :subtitle="trans_choice('core.count.records', $approvals->count(), ['count' => $approvals->count()])" />
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
            :empty="__('approval::message.nothing_waiting')"
            :rows="$approvals"
            :compact="request()->boolean('compact')"
            :columns="[
                ['key' => 'requested_at', 'label' => __('approval::field.requested_at'), 'width' => '11rem',
                 'render' => fn ($a) => $a->requested_at?->format('d M Y, H:i')],
                ['key' => 'module', 'label' => __('approval::field.action'),
                 'render' => fn ($a) => view('approval::inbox.partials.what', ['approval' => $a, 'labels' => $labels])],
                ['key' => 'requested_by', 'label' => __('approval::field.requested_by'), 'width' => '11rem',
                 'render' => fn ($a) => $a->requester?->name],
                ['key' => 'amount', 'label' => __('approval::field.amount'), 'numeric' => true, 'width' => '9rem',
                 'render' => fn ($a) => $a->amount === null ? '—' : number_format((float) $a->amount, 2)],
                ['key' => 'open', 'label' => '', 'width' => '7rem',
                 'render' => fn ($a) => view('approval::inbox.partials.open', ['approval' => $a])],
            ]" />
    </div>
</x-layouts.app>
