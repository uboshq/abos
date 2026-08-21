{{--
    ঋণের তালিকা।

    ── দুই ধরনের ঋণ এক তালিকায়, কিন্তু কলামগুলো একই ─────────────────
    টার্ম লোনে "মঞ্জুরিকৃত", CC-তে "সীমা" — শব্দ আলাদা, প্রশ্ন এক:
    সর্বোচ্চ কত। তাই একটাই কলাম, আর ধরনটা পাশে লেখা থাকে।

    বকেয়া খতিয়ান থেকে গোনা হয়, কোনো কলামে জমা রাখা নয় — দুই জায়গায়
    একই সংখ্যা রাখলে একদিন আলাদা হবেই।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('accounts::menu.loans') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header
            :title="__('accounts::menu.loans')"
            :subtitle="__('accounts::message.loan_total') . ': ' . \App\Core\Support\Money::format($total)">
            <x-slot:actions>
                @can('accounts.loan.manage')
                    <x-ui.button tone="primary" icon="plus" :href="route('accounts.loan.create')">
                        {{ __('core.action.create') }}
                    </x-ui.button>
                @endcan
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    <p class="mb-4 max-w-(--spacing-prose-max) text-sm text-(--color-ink-muted)">
        {{ __('accounts::message.loan_note') }}
    </p>

    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                bg-(--color-surface-card)">
        <x-ui.table
            :empty="__('accounts::message.no_loans')"
            :rows="$loans"
            :columns="[
                ['key' => 'document_no', 'label' => __('core.print.document_no'), 'width' => '13rem',
                 'render' => fn ($l) => new \Illuminate\Support\HtmlString(
                     '<a href=\'' . route('accounts.loan.show', $l->id) . '\' '
                     . 'class=\'text-(--color-brand-500) underline-offset-2 hover:underline\'>'
                     . e($l->document_no) . '</a>')],
                ['key' => 'lender', 'label' => __('accounts::field.lender'),
                 'render' => fn ($l) => $l->lender],
                ['key' => 'kind', 'label' => __('accounts::field.loan_kind'), 'width' => '9rem',
                 'render' => fn ($l) => $l->isTerm()
                     ? __('accounts::field.loan_term')
                     : __('accounts::field.loan_cc')],
                ['key' => 'sanctioned', 'label' => __('accounts::field.sanctioned'),
                 'numeric' => true, 'width' => '10rem',
                 'render' => fn ($l) => \App\Core\Support\Money::format($l->sanctioned)],
                ['key' => 'rate', 'label' => __('accounts::field.interest_rate'),
                 'numeric' => true, 'width' => '7rem',
                 'render' => fn ($l) => rtrim(rtrim((string) $l->interest_rate, '0'), '.')],
                ['key' => 'outstanding', 'label' => __('accounts::message.loan_outstanding'),
                 'numeric' => true, 'width' => '11rem',
                 'render' => fn ($l) => $l->isSettled()
                     ? __('accounts::message.loan_settled')
                     : \App\Core\Support\Money::format($l->outstanding())],
            ]" />
    </div>
</x-layouts.app>
