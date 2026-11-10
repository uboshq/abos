{{--
    ⭐ সরবরাহকারীর সারির "অ্যাকশন ▾" — মালিক, ৯ নভেম্বর ২০২৬ ("Abos er sokol list sob skin dekhe deke update koro"): গ্রাহকের তালিকা
    আর কাগজের তালিকাগুলোর একই মেনু ([[x-ui.row-actions]]) — দেখা · কাগজ · কাজ। (আগে: মালিকের ২১ সেপ্টেম্বরের ছকের দেখা আর সম্পাদনা
    — দুটোই মেনুতে আছে।)

    দেখা: দেখুন (পুরো পাতা), প্রিভিউ (পাশে), বদলের ইতিহাস (চাবি থাকলে)। কাগজ: হিসাব মেলানোর চিঠি ছাপুন। কাজ: সম্পাদনা।
    ⓘ "নতুন ক্রয় আদেশ" / "টাকা দিন" এখানে নেই — ঐ পাতাগুলো এখনো সরবরাহকারী বসিয়ে খোলে না; আধা-কাজের লিংক বসানো নয়।

    ── ⛔ সক্রিয়/নিষ্ক্রিয় আর মোছা এখানে নেই, আগের নিয়মই — অবস্থার পিলটাই সেই বোতাম; যার নামে কাগজ আছে তাকে মোছা নয়।
--}}
@php
    $user = auth()->user();
    $code = (string) ($supplier->code ?? '');
    $items = [\App\Core\Support\RowMenu::head(trim(($code !== '' ? $code.' · ' : '').$supplier->name()),
        __($supplier->is_active ? 'customer::filter.active' : 'customer::filter.inactive'))];

    $items[] = \App\Core\Support\RowMenu::group('look');
    $items[] = ['label' => __('core.row_menu.view'), 'icon' => 'eye', 'url' => route('supplier.show', $supplier), 'peek' => false, 'key' => 'Enter', 'opens' => true];
    $items[] = ['label' => __('core.row_menu.preview'), 'icon' => 'search', 'url' => route('supplier.show', $supplier)];
    if ($user?->can('governance.audit.view') && $code !== '') {
        $items[] = ['label' => __('core.row_menu.history'), 'icon' => 'clock',
            'url' => route('governance.audit.index', ['q' => $code, 'module' => 'supplier']), 'peek' => false];
    }

    $items[] = \App\Core\Support\RowMenu::group('paper');
    $items[] = ['label' => __('customer::row_menu.balance_letter'), 'icon' => 'printer', 'url' => route('supplier.balance_confirmation', $supplier), 'blank' => true];

    $items[] = \App\Core\Support\RowMenu::group('work');
    $items[] = ['label' => __('core.row_menu.edit'), 'icon' => 'edit', 'url' => route('supplier.edit', $supplier), 'peek' => false,
        'off' => $user?->can('supplier.update') ? null : __('core.row_menu.why_no_key')];
@endphp

<x-ui.row-actions :items="$items" :label="__('core.table.action_menu')" />
