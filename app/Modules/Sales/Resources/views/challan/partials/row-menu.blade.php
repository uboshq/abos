{{--
    ডেলিভারি চালানের "অ্যাকশন ▾" — মালিক, ৮ নভেম্বর ২০২৬ ([[x-ui.row-actions]])।

    ⓘ পাতার বোতামের একই চাবি আর একই শর্ত: সম্পাদনা/নিশ্চিত `update` নীতি, বিল `create SalesInvoice` (নিশ্চিত, গেটে
    ছাড়া নয়), গাড়ি ও ভাড়া `sales.challan.create`, বাতিল `delete` নীতি। ⓘ "বাকি মাল বিল হয়নি" সারিপ্রতি খোঁজা হয় না —
    বিল বানানোর পাতা নিজেই বলে কিছু বাকি নেই। ছাপার আগে গাড়ি না বসালে ছাপার ঠিকানা থামায় (RequireTransportBeforePrint)।
--}}
@php
    $user = auth()->user();
    $status = $challan->status;
    $isDraft = $status === \App\Core\Support\DocumentStatus::DRAFT;
    $isConfirmed = $status === \App\Core\Support\DocumentStatus::CONFIRMED;
    $isCancelled = $status === \App\Core\Support\DocumentStatus::CANCELLED;
    $show = route('sales.challan.show', $challan);

    $items = [\App\Core\Support\RowMenu::head($challan->document_no, __('core.status.'.$status))];

    $items[] = \App\Core\Support\RowMenu::group('look');
    $items[] = ['label' => __('core.row_menu.view'), 'icon' => 'eye', 'url' => $show, 'peek' => false, 'key' => 'Enter', 'opens' => true];
    $items[] = ['label' => __('core.row_menu.preview'), 'icon' => 'search', 'url' => $show];
    if ($user?->can('governance.audit.view')) {
        $items[] = ['label' => __('core.row_menu.history'), 'icon' => 'clock',
            'url' => route('governance.audit.index', ['q' => $challan->document_no, 'module' => 'sales']), 'peek' => false];
    }
    $items[] = ['label' => __('core.row_menu.files'), 'icon' => 'attachment', 'url' => $show.'#attachments', 'peek' => false];

    if ($user?->can('sales.challan.view') && ! $isCancelled) {
        $items[] = \App\Core\Support\RowMenu::group('paper');
        $items[] = ['label' => __('core.row_menu.print_challan_prices'), 'icon' => 'printer', 'url' => route('sales.print.challan', [$challan, 'prices' => 1]), 'blank' => true, 'key' => 'F10'];
        $items[] = ['label' => __('core.row_menu.print_challan_plain'), 'icon' => 'printer', 'url' => route('sales.print.challan', [$challan, 'prices' => 0]), 'blank' => true];
        $items[] = ['label' => __('core.row_menu.print_gatepass'), 'icon' => 'printer', 'url' => route('sales.print.gatepass', $challan), 'blank' => true];
        $items[] = ['label' => __('core.row_menu.pdf'), 'icon' => 'download', 'url' => route('sales.print.challan', [$challan, 'prices' => 1, 'download' => 1]), 'blank' => true];
        $items[] = ['label' => __('core.row_menu.send'), 'icon' => 'share', 'url' => route('paper.share'), 'method' => 'post',
            'fields' => [
                'route' => 'sales.print.challan',
                'params[challan]' => $challan->id,
                'document_type' => 'sales_challan',
                'document_id' => $challan->id,
                'document_no' => $challan->document_no,
                'paper' => \App\Core\Engines\Print\PaperSize::A4,
            ]];
    }

    $items[] = \App\Core\Support\RowMenu::group('work');

    if ($user?->can('update', $challan)) {
        $items[] = ['label' => __('core.row_menu.confirm'), 'icon' => 'check-circle', 'url' => $show.'#confirm', 'peek' => false,
            'tone' => 'success', 'hint' => __('core.row_menu.confirm_hint')];
    }

    $items[] = ['label' => __('core.row_menu.edit'), 'icon' => 'edit', 'url' => route('sales.challan.edit', $challan), 'peek' => false,
        'off' => $user?->can('update', $challan) ? null : ($isCancelled ? __('core.row_menu.why_cancelled') : ($isDraft ? __('core.row_menu.why_no_key') : __('core.row_menu.why_posted')))];

    if (! $isCancelled && $user?->can('sales.challan.create')) {
        $items[] = ['label' => __('core.row_menu.transport'), 'icon' => 'challan', 'url' => route('sales.challan.transport', $challan)];
    }

    if (! $challan->issue_at_gate && $user?->can('create', \App\Modules\Sales\Models\SalesInvoice::class)) {
        $items[] = ['label' => __('core.row_menu.make_bill'), 'icon' => 'receipt',
            'url' => route('sales.invoice.create', ['delivery_challan_id' => $challan->id]), 'peek' => false,
            'off' => $isConfirmed ? null : ($isCancelled ? __('core.row_menu.why_cancelled') : __('core.row_menu.why_need_confirmed'))];
    }

    if (! $isCancelled && $user?->can('delete', $challan)) {
        $items[] = ['line' => true];
        $items[] = ['label' => $isDraft ? __('core.row_menu.delete_draft') : __('core.row_menu.cancel_paper'), 'icon' => 'trash', 'tone' => 'danger',
            'url' => route('sales.challan.show', ['challan' => $challan, 'cancel' => 1]).'#cancel', 'peek' => false, 'key' => $isDraft ? 'Del' : null];
    }
@endphp

<x-ui.row-actions :items="$items" :label="__('core.table.action_menu')" />
