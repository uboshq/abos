{{--
    বিক্রয় বিলের "অ্যাকশন ▾" — মালিক, ৮ নভেম্বর ২০২৬; নমুনা "লিস্টের অ্যাকশন বোতাম" ("ok")।

    ── তিন ভাগ, তারপর লাল ─────────────────────────────────────────────
    দেখা (দেখুন · প্রিভিউ · ইতিহাস · ফাইল) → ছাপা ও পাঠানো (ছাপুন · PDF · গ্রাহককে লিংক) → কাজ (নিশ্চিত · সম্পাদনা ·
    গাড়ি ও ভাড়া · টাকা নিন · ফেরত) → রেখার নিচে লাল: খসড়ায় "মুছুন", নিশ্চিত বিলে "বাতিল (রিভার্স)"।

    ── ⓘ যা এখন করা যায় না, তা ধূসর — নিচে কারণ ───────────────────────
    নমুনার নিয়ম: "লুকানো থাকে না — ধূসর থাকে"। ⛔ কিন্তু চাবি না থাকলে কাজটা মেনুতে আসেই না (নিয়ম ৪)।

    ── ⛔ "মুছুন" লেখা নেই — খসড়াও মোছে না, বাতিল হয় ─────────────────────
    নমুনায় "মুছুন (খসড়া)" ছিল; ⓘ কিন্তু নম্বর পাওয়া কাগজ কখনো মোছে না (মালিক, ২১ সেপ্টেম্বর ২০২৬ "মোছা কখনো নয়";
    [[TheInvoiceListOffersWhatEachRowAllowsTest]]) — খসড়া বাতিল হয়, কাগজটা থাকে। বোতাম যা বলে কাজটাও তাই: "খসড়া বাতিল"।

    ── ⚠️ "খসড়া বাতিল" আর "বাতিল (রিভার্স)" দুইটাই কাগজের পাতায় নিয়ে যায় ─
    দুইটাতেই লিখিত কারণ বাধ্যতামূলক, আর মেনুর একটা বোতাম কারণ নিতে পারে না। ⓘ খসড়া মোছা = খসড়া বাতিল
    ([[SalesInvoiceController::cancel]], মালিক ২ অক্টোবর ২০২৬: নিশ্চিত বিলে এই পথ বন্ধ); নিশ্চিত বিল উল্টায় আলাদা
    বাতিল-কাগজে ([[SalesInvoiceController::cancellation]], `sales.invoice.cancellation` চাবি)।

    ⓘ ভাড়া বাকি থাকলে ছাপা আটকায় ছাপার ঠিকানাই, কারণসহ ([[FarePayment::assertInvoiceFareSet]]) — এখানে সারিপ্রতি
    চালান খুঁজলে তালিকা ধীর হত।
--}}
@php
    $user = auth()->user();
    $status = $invoice->status;
    $isDraft = $status === \App\Core\Support\DocumentStatus::DRAFT;
    $isConfirmed = $status === \App\Core\Support\DocumentStatus::CONFIRMED;
    $isCancelled = $status === \App\Core\Support\DocumentStatus::CANCELLED;
    $open = \App\Core\Support\RowMenu::monthOpen($invoice->trx_date);
    $show = route('sales.invoice.show', $invoice);
    $due = $isConfirmed ? $invoice->dueAmount() : '0';
    $hasDue = bccomp($due, '0', 4) > 0;

    $items = [\App\Core\Support\RowMenu::head($invoice->document_no, $status)];

    /* ── দেখা ── */
    $items[] = \App\Core\Support\RowMenu::group('look');
    $items[] = ['label' => __('core.row_menu.view'), 'icon' => 'eye', 'url' => $show, 'peek' => false, 'key' => 'Enter', 'opens' => true];
    $items[] = ['label' => __('core.row_menu.preview'), 'icon' => 'search', 'url' => $show];
    if ($user?->can('governance.audit.view')) {
        $items[] = ['label' => __('core.row_menu.history'), 'icon' => 'clock',
            'url' => route('governance.audit.index', ['q' => $invoice->document_no, 'module' => 'sales']), 'peek' => false];
    }
    $items[] = ['label' => __('core.row_menu.files'), 'icon' => 'attachment', 'url' => $show.'#attachments', 'peek' => false];

    /* ── ছাপা ও পাঠানো ── ⓘ খসড়া ছাপে খসড়ার ছাপে (জলছাপসহ); পাঠানো আর PDF কেবল নিশ্চিত বিলে */
    if ($user?->can('sales.invoice.view') && ! $isCancelled) {
        $items[] = \App\Core\Support\RowMenu::group('paper');
        $print = $isDraft ? route('sales.print.draft', $invoice) : route('sales.print.invoice', $invoice);
        $items[] = ['label' => __('core.row_menu.print'), 'icon' => 'printer', 'url' => $print, 'blank' => true, 'key' => 'F10'];
        if ($isConfirmed) {
            $items[] = ['label' => __('core.row_menu.pdf'), 'icon' => 'download', 'url' => route('sales.print.invoice', ['invoice' => $invoice, 'download' => 1]), 'blank' => true];
            $items[] = ['label' => __('core.row_menu.send'), 'icon' => 'share', 'url' => route('paper.share'), 'method' => 'post',
                'fields' => [
                    'route' => 'sales.print.invoice',
                    'params[invoice]' => $invoice->id,
                    'document_type' => 'sales_invoice',
                    'document_id' => $invoice->id,
                    'document_no' => $invoice->document_no,
                    'paper' => \App\Core\Engines\Print\PaperSize::A4,
                ]];
        }
    }

    /* ── কাজ ── */
    $items[] = \App\Core\Support\RowMenu::group('work');

    if ($isDraft && $user?->can('sales.invoice.create')) {
        $items[] = ['label' => __('core.row_menu.confirm'), 'icon' => 'check-circle', 'url' => route('sales.invoice.confirm', $invoice),
            'method' => 'post', 'tone' => 'success', 'off' => $open ? null : __('core.row_menu.why_month_closed')];
    }

    if ($user?->can('sales.invoice.create')) {
        $items[] = ['label' => __('core.row_menu.edit'), 'icon' => 'edit', 'url' => route('sales.invoice.edit', $invoice), 'peek' => false,
            'off' => match (true) {
                $isCancelled => __('core.row_menu.why_cancelled'),
                ! $isDraft => __('core.row_menu.why_posted'),
                ! $open => __('core.row_menu.why_month_closed'),
                default => null,
            }];
    }

    /* ⭐ "মাল কীভাবে যাবে" — চালান নিশ্চিতের চাবিতে (মালিকের অনুমোদিত বদল, ১ অক্টোবর ২০২৬) */
    if (! $isCancelled && $user?->can('sales.challan.create')) {
        $items[] = ['label' => __('core.row_menu.transport'), 'icon' => 'challan', 'url' => route('sales.invoice.transport', $invoice)];
    }

    if ($user?->can('sales.collection.create')) {
        $items[] = ['label' => __('core.row_menu.collect'), 'icon' => 'cash',
            'url' => route('sales.collection.create', ['sales_invoice_id' => $invoice->id]), 'peek' => false,
            'hint' => $hasDue ? __('core.row_menu.due', ['amount' => \App\Core\Support\Money::format($due)]) : null,
            'off' => match (true) {
                $isCancelled => __('core.row_menu.why_cancelled'),
                ! $isConfirmed => __('core.row_menu.why_not_posted_collect'),
                ! $hasDue => __('core.row_menu.why_no_due'),
                default => null,
            }];
    }

    if ($user?->can('sales.return.create')) {
        $items[] = ['label' => __('core.row_menu.return'), 'icon' => 'swap',
            'url' => route('sales.return.create', ['sales_invoice_id' => $invoice->id]), 'peek' => false,
            'off' => match (true) {
                $isCancelled => __('core.row_menu.why_cancelled'),
                ! $isConfirmed => __('core.row_menu.why_not_posted_return'),
                default => null,
            }];
    }

    /* ── লাল, রেখার নিচে ── */
    if ($isDraft && $user?->can('sales.invoice.cancel')) {
        $items[] = ['line' => true];
        $items[] = ['label' => __('core.row_menu.delete_draft'), 'icon' => 'trash', 'tone' => 'danger',
            'url' => route('sales.invoice.show', ['invoice' => $invoice, 'cancel' => 1]).'#cancel', 'peek' => false, 'key' => 'Del'];
    }

    if ($isConfirmed && $user?->can('sales.invoice.cancellation')) {
        $items[] = ['line' => true];
        $items[] = ['label' => __('core.row_menu.reverse'), 'icon' => 'trash', 'tone' => 'danger',
            'url' => route('sales.invoice.show', ['invoice' => $invoice, 'cancellation' => 1]).'#cancellation', 'peek' => false,
            'off' => $open ? null : __('core.row_menu.why_month_closed')];
    }
@endphp

<x-ui.row-actions :items="$items" :label="__('core.table.action_menu')" />
