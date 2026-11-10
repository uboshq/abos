{{--
    খসড়া সারির কাজগুলো — শোধরানো আর ফেলে দেওয়া।

    ── ⚠️ কেন এই ছোট ফাইলটা আছে, আর সরাসরি `view()` নয় ──────────────────
    প্রথমে টেবিলের `render` ক্লোজার থেকে সরাসরি
    `view('components.ui.row-actions', [...])` ডাকা হয়েছিল, আর পাতাটা
    ৫০০ দিয়েছে: *"Undefined variable $slot"*। ⓘ অ্যানোনিমাস কম্পোনেন্ট
    `$slot` ধরে নেয়, আর সাধারণ ভিউ হিসেবে ডাকলে সেটা থাকে না।

    ⭐ আর ধরা পড়েছে **ঐ কম্পোনেন্টের নিজের পাহারা দিয়েই** — সে স্লটে
    কিছু এলে জোরে থামে, কারণ সে কেবল `:items` আঁকে। মন্তব্যে লেখা আছে
    স্লট দিয়ে ডাকলে বোতামটা নীরবে অদৃশ্য থাকত, আর রেসিপির তালিকায়
    ঠিক সেটাই ঘটেছিল।

    ⓘ অর্থাৎ ভুলটা নীরব হতে পারত; কেউ একজন সেটা জোরে করে রেখেছিলেন।
--}}
@php
    $canEdit = auth()->user()?->can('finance.capital.create') === true;
    $canDelete = auth()->user()?->can('finance.capital.delete') === true;

    $draft = $entry->status === \App\Modules\Finance\Models\CapitalEntry::DRAFT;
    $items = [];

    if ($draft && $canEdit) {
        $items[] = [
            'label' => __('core.action.edit'),
            'url' => route('finance.capital.edit', $entry),
        ];
    }

    if ($draft && $canDelete) {
        $items[] = [
            'label' => __('core.action.delete'),
            'url' => route('finance.capital.destroy', $entry),
            'method' => 'delete',
            'tone' => 'danger',
        ];
    }
@endphp

{{-- ⓘ এক সারিতে একটাই মেনু: "দেখুন" এখানেই, তাই টেবিলের :view-url সরানো (Mac রান, ৯ নভেম্বর ২০২৬: টেবিলের মেনু আর এই মেনু পাশাপাশি দুবার আঁকা হত) --}}
<x-ui.row-actions :label="__('core.table.action_menu')" :items="\App\Core\Support\RowMenu::dress($items, \App\Core\Support\RowMenu::title($entry), \App\Core\Support\DocumentStatus::label((string) $entry->status), $entry->voucher_id ? route('accounts.voucher.show', $entry->voucher_id) : null, $entry->document_no, 'finance')" />
