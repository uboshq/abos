{{--
    বিক্রয় আদেশের "অ্যাকশন ▾" — মালিক, ৮ নভেম্বর ২০২৬; নমুনা "লিস্টের অ্যাকশন বোতাম" ([[x-ui.row-actions]])।

    ⓘ প্রতিটা কাজ পাতার বোতামের **একই চাবিতে**: সম্পাদনা/নিশ্চিত `update` নীতি, চালান `create DeliveryChallan`, বন্ধ
    `sales.order.close`, বাতিল `delete` নীতি — পাতায় যা দেখা যায় না, মেনুতেও আসে না।
    ⛔ "নিশ্চিত" সরাসরি নয় — পাতায় সারাংশের পপ-আপ (মালিক, ৪ অক্টোবর ২০২৬)। বাতিল আর বন্ধ কারণ চায়, তাই পাতার ফর্মে, খোলা অবস্থায়।
--}}
@php
    $user = auth()->user();
    $status = $order->status;
    $S = \App\Modules\Sales\Support\SalesOrderStatus::class;
    $isDraft = $status === $S::DRAFT;
    $isConfirmed = $status === $S::CONFIRMED;
    $ended = in_array($status, [$S::CANCELLED, $S::CLOSED, $S::REJECTED], true);
    $show = route('sales.order.show', $order);

    $items = [\App\Core\Support\RowMenu::head($order->document_no, $S::label($status))];

    /* ── দেখা ── */
    $items[] = \App\Core\Support\RowMenu::group('look');
    $items[] = ['label' => __('core.row_menu.view'), 'icon' => 'eye', 'url' => $show, 'peek' => false, 'key' => 'Enter', 'opens' => true];
    $items[] = ['label' => __('core.row_menu.preview'), 'icon' => 'search', 'url' => $show];
    if ($user?->can('governance.audit.view')) {
        $items[] = ['label' => __('core.row_menu.history'), 'icon' => 'clock',
            'url' => route('governance.audit.index', ['q' => $order->document_no, 'module' => 'sales']), 'peek' => false];
    }
    $items[] = ['label' => __('core.row_menu.files'), 'icon' => 'attachment', 'url' => $show.'#attachments', 'peek' => false];

    /* ── ছাপা ও পাঠানো ── */
    if ($user?->can('sales.order.view') && $status !== $S::CANCELLED) {
        $items[] = \App\Core\Support\RowMenu::group('paper');
        $items[] = ['label' => __('core.row_menu.print'), 'icon' => 'printer', 'url' => route('sales.print.order', $order), 'blank' => true, 'key' => 'F10'];
        $items[] = ['label' => __('core.row_menu.print_do'), 'icon' => 'printer', 'url' => route('sales.print.delivery_order', $order), 'blank' => true];
        $items[] = ['label' => __('core.row_menu.pdf'), 'icon' => 'download', 'url' => route('sales.print.order', ['order' => $order, 'download' => 1]), 'blank' => true];
        $items[] = ['label' => __('core.row_menu.send'), 'icon' => 'share', 'url' => route('paper.share'), 'method' => 'post',
            'fields' => [
                'route' => 'sales.print.order',
                'params[order]' => $order->id,
                'document_type' => 'sales_order',
                'document_id' => $order->id,
                'document_no' => $order->document_no,
                'paper' => \App\Core\Engines\Print\PaperSize::A4,
            ]];
    }

    /* ── কাজ ── */
    $items[] = \App\Core\Support\RowMenu::group('work');

    if ($user?->can('update', $order)) {
        $items[] = ['label' => __('core.row_menu.confirm'), 'icon' => 'check-circle', 'url' => $show.'#confirm', 'peek' => false,
            'tone' => 'success', 'hint' => __('core.row_menu.confirm_hint')];
    }

    $items[] = ['label' => __('core.row_menu.edit'), 'icon' => 'edit', 'url' => route('sales.order.edit', $order), 'peek' => false,
        'off' => $user?->can('update', $order) ? null : ($ended ? __('core.row_menu.why_cancelled') : ($isDraft ? __('core.row_menu.why_no_key') : __('core.row_menu.why_posted')))];

    if ($user?->can('create', \App\Modules\Sales\Models\DeliveryChallan::class)) {
        $items[] = ['label' => __('core.row_menu.make_challan'), 'icon' => 'challan',
            'url' => route('sales.challan.create', ['sales_order_id' => $order->id]), 'peek' => false,
            'off' => $isConfirmed ? null : ($ended ? __('core.row_menu.why_cancelled') : __('core.row_menu.why_need_confirmed'))];
    }

    if ($isConfirmed && $user?->can('sales.order.close')) {
        $items[] = ['label' => __('core.row_menu.close_order'), 'icon' => 'check-circle',
            'url' => route('sales.order.show', ['order' => $order, 'close' => 1]).'#close', 'peek' => false];
    }

    /* ── লাল ── ⓘ বন্ধ আদেশ বাতিল হয় না — বন্ধের দিনেই তার বাকি মাল ছাড়া হয়েছে (পাতার একই শর্ত) */
    if (! $ended && $user?->can('delete', $order)) {
        $items[] = ['line' => true];
        $items[] = ['label' => $isDraft ? __('core.row_menu.delete_draft') : __('core.row_menu.cancel_paper'), 'icon' => 'trash', 'tone' => 'danger',
            'url' => route('sales.order.show', ['order' => $order, 'cancel' => 1]).'#cancel', 'peek' => false, 'key' => $isDraft ? 'Del' : null];
    }
@endphp

<x-ui.row-actions :items="$items" :label="__('core.table.action_menu')" />
