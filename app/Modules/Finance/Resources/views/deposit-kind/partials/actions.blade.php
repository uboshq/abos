{{-- সম্পাদনা · চালু-বন্ধ · মোছা।

     ⛔ মোছার বোতামটা কেবল তখন, যখন এই ধরনে একটাও জমা খোলা হয়নি।
     ⚠️ ব্যবহৃত ধরন মুছলে পুরনো কাগজ অনাথ হত — তখন নিষ্ক্রিয় করাই পথ,
     আর সেটা পাশের বোতামেই আছে ([[DepositKindController::destroy()]])। --}}
{{-- ⭐ "অ্যাকশন ▾" — মালিক, ৯ নভেম্বর ২০২৬ ("Abos er sokol list sob skin dekhe deke update koro"): আগের লেখা-লিংকগুলো গ্রাহকের তালিকার মতো মেনুতে --}}
@php
    $items = auth()->user()?->can('finance.deposit_kind.manage') ? array_values(array_filter([
        ['label' => __('core.action.edit'), 'url' => route('finance.deposit_kind.edit', $kind)],
        ['label' => __($kind->is_active ? 'finance::action.kind_off' : 'finance::action.kind_on'), 'url' => route('finance.deposit_kind.toggle', $kind),
            'method' => 'post', 'tone' => $kind->is_active ? 'danger' : 'success'],
        $kind->deposits_count === 0 ? ['label' => __('core.action.delete'), 'url' => route('finance.deposit_kind.destroy', $kind), 'method' => 'delete', 'tone' => 'danger'] : null,
    ])) : [];
@endphp
<x-ui.row-actions :label="__('core.table.action_menu')" :items="\App\Core\Support\RowMenu::dress($items, \App\Core\Support\RowMenu::title($kind), \App\Core\Support\RowMenu::activeState($kind), null, $kind->code ?? null, 'finance')" />
