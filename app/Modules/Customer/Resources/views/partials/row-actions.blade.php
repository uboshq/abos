{{--
    ⭐ গ্রাহকের সারির "অ্যাকশন ▾" — মালিক, ৯ নভেম্বর ২০২৬ ("customar list update koto ni action botamer sathe?"): কাগজের তালিকাগুলোর
    একই মেনু ([[x-ui.row-actions]], [[x-ui.paper-row-menu]]-এর একই তিন ভাগ) — দেখা · কাগজ · কাজ।

    দেখা: সারাংশ (পাশে খোলে), পুরো পাতা, বাকি-নিয়ন্ত্রণের খবর (চাবি থাকলে), বদলের ইতিহাস (চাবি থাকলে)।
    কাগজ: হিসাব মেলানোর চিঠি (balance confirmation) — ছাপা।
    কাজ: নতুন অর্ডার আর টাকা নিন — এই গ্রাহক বসানো অবস্থায় খোলে (`?customer=`); সম্পাদনা। যা করা যায় না তা ধূসর, কারণসহ।

    ── ⛔ সক্রিয়/নিষ্ক্রিয় আর মোছা এখানে নেই, আর সেটা ইচ্ছাকৃত (আগের নিয়ম অক্ষত) ─────────────────
    অবস্থার কলামের পিলটাই সেই বোতাম (মালিকের নমুনা, ২০২৬-০৮-০৭); মোছা নেই — যে গ্রাহকের নামে বিল আছে তাঁকে মুছলে বিলের মালিক
    হারায়। নিষ্ক্রিয় করাই যা মানুষ আসলে চায়।
--}}
@php
    $user = auth()->user();
    $id = $customer->public_id ?? $customer->id;
    $inactive = ! $customer->is_active;
    // ⓘ মাথায় "কোড · নাম · পয়েন্ট · শাখা" — গ্রাহকের নাম কখনো একা নয় (মালিক, ১৯ অক্টোবর ২০২৬)
    $items = [\App\Core\Support\RowMenu::head(trim(($customer->code ? $customer->code.' · ' : '').$customer->nameWithPoint()),
        __($inactive ? 'customer::filter.inactive' : 'customer::filter.active'))];

    $items[] = \App\Core\Support\RowMenu::group('look');
    $items[] = ['label' => __('core.row_menu.preview'), 'icon' => 'search', 'url' => route('customer.summary', $customer)];
    $items[] = ['label' => __('core.row_menu.view'), 'icon' => 'eye', 'url' => route('customer.show', $customer), 'peek' => false, 'key' => 'Enter', 'opens' => true];
    if ($user?->can('credit_control.view') && \Illuminate\Support\Facades\Route::has('credit_control.customer.show')) {
        $items[] = ['label' => __('customer::row_menu.credit'), 'icon' => 'scale', 'url' => route('credit_control.customer.show', $customer), 'peek' => false];
    }
    if ($customer->code && $user?->can('governance.audit.view')) {
        $items[] = ['label' => __('core.row_menu.history'), 'icon' => 'clock',
            'url' => route('governance.audit.index', ['q' => $customer->code, 'module' => 'customer']), 'peek' => false];
    }

    $items[] = \App\Core\Support\RowMenu::group('paper');
    $items[] = ['label' => __('customer::row_menu.balance_letter'), 'icon' => 'printer', 'url' => route('customer.balance_confirmation', $customer), 'blank' => true];

    $items[] = \App\Core\Support\RowMenu::group('work');
    // ⛔ গ্রাহক মডিউল বিক্রয়কে চেনে না (Boundaries) — বিক্রয়ের মডেল নয়, তাদের policy যে চাবি পড়ে সেটাই; বিক্রয় বন্ধ থাকলে কাজ দুটোই নেই
    if (\Illuminate\Support\Facades\Route::has('sales.order.create')) {
        $canOrder = (bool) $user?->can('sales.order.create');
        $items[] = ['label' => __('customer::row_menu.new_order'), 'icon' => 'plus', 'url' => route('sales.order.create', ['customer' => $customer->id]), 'peek' => false,
            'off' => $inactive ? __('customer::row_menu.why_inactive') : ($canOrder ? null : __('core.row_menu.why_no_key'))];
    }
    if (\Illuminate\Support\Facades\Route::has('sales.collection.create')) {
        $canCollect = (bool) $user?->can('sales.collection.create');
        $items[] = ['label' => __('core.row_menu.collect'), 'icon' => 'cash', 'url' => route('sales.collection.create', ['customer' => $id]), 'peek' => false,
            'off' => $canCollect ? null : __('core.row_menu.why_no_key')];
    }
    $items[] = ['label' => __('core.row_menu.edit'), 'icon' => 'edit', 'url' => route('customer.edit', $customer), 'peek' => false,
        'off' => $user?->can('customer.update') ? null : __('core.row_menu.why_no_key')];
@endphp

<x-ui.row-actions :items="$items" :label="__('core.table.action_menu')" />
