{{--
    ⭐ ছাপা চেকের খাতা — মালিকের নমুনার পাতা ৫ (১০ নভেম্বর ২০২৬)। কে, কবে, কোন বইয়ের কোন নম্বর, কাকে, কত; কতবার ছাপা; নষ্ট পাতা।
    ⓘ মোট কেবল ছাপা চেকের (নষ্ট পাতা টাকা নয়); হিসাব ছাড়া চেক কেবল সেই চাবিওয়ালার চোখে ([[ChequePrintController::log()]])।
--}}
@php
    $columns = [
        ['key' => 'cheque_no', 'label' => __('accounts::cheque_print.col_no'), 'width' => '8rem',
         'render' => fn ($p) => $p->book?->number($p->cheque_no) ?? (string) $p->cheque_no],
        ['key' => 'book', 'label' => __('accounts::cheque_print.col_book'),
         'render' => fn ($p) => (string) $p->book?->label],
        ['key' => 'payee', 'label' => __('accounts::cheque_print.col_payee'),
         'render' => fn ($p) => $p->payee ?: '—'],
        ['key' => 'amount', 'total' => 'money', 'label' => __('accounts::cheque_print.col_amount'), 'numeric' => true, 'width' => '10rem',
         'render' => fn ($p) => $p->amount === null ? '—' : \App\Core\Support\Money::format($p->amount)],
        ['key' => 'voucher', 'label' => __('accounts::cheque_print.col_voucher'), 'width' => '9rem',
         'render' => fn ($p) => $p->off_books ? __('accounts::cheque_print.state_free') : (string) $p->voucher?->document_no],
        ['key' => 'who', 'label' => __('accounts::cheque_print.col_who'),
         'render' => fn ($p) => view('accounts::cheque_print.partials.who', ['print' => $p])],
        ['key' => 'state', 'label' => __('accounts::cheque_print.col_state'), 'width' => '12rem',
         'render' => fn ($p) => view('accounts::cheque_print.partials.actions', ['print' => $p, 'mayPrint' => $mayPrint, 'mayFree' => $mayFree])],
    ];
@endphp
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('accounts::cheque_print.log_title') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('accounts::cheque_print.log_title')" />
    </x-slot:header>

    @if (session('saved'))
        <div role="status" class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm text-(--color-badge-success-ink)">{{ session('saved') }}</div>
    @endif

    @if ($errors->any())
        <div role="alert" class="mb-4 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm text-(--color-badge-danger-ink)">
            <ul class="list-inside list-disc">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div data-cheque-log>
        <x-ui.table :rows="$prints" :grand="$grand ?? []" :columns="$columns" :empty="__('accounts::cheque_print.log_none')" />
    </div>

    <div class="mt-3">{{ $prints->links() }}</div>
    <x-ui.list-totals :rows="$prints" :grand="$grand ?? []" :columns="$columns" />
</x-layouts.app>
