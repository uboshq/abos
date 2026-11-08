@props([
    'paper',
    'show',
    'state',
    'module',
    'edit' => null,
    'prints' => [],
    'pdf' => null,
    'share' => null,
    'work' => [],
    'confirmable' => false,
    'editable' => false,
    'cancellable' => false,
    'cancelLabel' => null,
    /* ⓘ অনুমোদনে পাঠানো খসড়া আর "খসড়া" নয় — বাতিলের নাম তখন সাধারণ "বাতিল করুন" (ভাউচার; ADraftAndAPaperAwaitingASignatureLookedTheSameTest) */
    'draftCancelLabel' => null,
])

{{--
    কাগজের তালিকার "অ্যাকশন ▾" — সব কাগজের একই ছাঁচ (মালিক, ৮ নভেম্বর ২০২৬; তাঁর নমুনা "লিস্টের অ্যাকশন বোতাম")।

    মাথা (নম্বর · অবস্থা) → দেখা (দেখুন · প্রিভিউ · ইতিহাস · ফাইল) → ছাপা ও পাঠানো → কাজ (নিশ্চিত · সম্পাদনা · কাগজের
    নিজের কাজ) → রেখার নিচে লাল বাতিল। প্রতিটা কাগজ কেবল নিজের ঠিকানা আর অনুমতির ফল দেয় — পাতার বোতামের **একই**
    শর্তে, তাই পাতায় যা নেই মেনুতেও নেই।

    @param $show        কাগজের পাতা
    @param $state       অবস্থার লেখা (মাথায়)
    @param $module      ইতিহাসের ছাঁকনি (governance.audit.index?module=)
    @param $edit        সম্পাদনার ঠিকানা; $editable মিথ্যা হলে ধূসর, কারণসহ
    @param $prints      [[label, url, key?], …] — নতুন ট্যাবে
    @param $pdf         PDF-এর ঠিকানা (`?download=1`)
    @param $share       ['route' =>, 'param' =>, 'type' =>] — "গ্রাহককে পাঠান" ([[PaperTrail::DOCUMENT_ROUTES]]-এর ধরন)
    @param $work        কাগজের নিজের কাজ — [[x-ui.row-actions]]-এর সারি
    @param $confirmable নিশ্চিত করা যায় (পাতার "নিশ্চিত" বোতামের শর্ত) — ⛔ সরাসরি POST নয়, পাতায় সারাংশের পপ-আপ
    @param $cancellable বাতিল করা যায় (পাতার বাতিল-ফর্মের শর্ত) — ফর্মটা খোলা অবস্থায় (`?cancel=1#cancel`)
    @param $cancelLabel নিশ্চিত কাগজের বাতিলের নিজের নাম (ভাউচার: "বাতিল (রিভার্স)" — উল্টো দাখিলা বসে)
--}}
@php
    $status = $paper->status;
    $isDraft = $status === \App\Core\Support\DocumentStatus::DRAFT;
    $isCancelled = $status === \App\Core\Support\DocumentStatus::CANCELLED;
    $user = auth()->user();
    $glue = str_contains($show, '?') ? '&' : '?';

    $items = [\App\Core\Support\RowMenu::head($paper->document_no, $state)];

    $items[] = \App\Core\Support\RowMenu::group('look');
    $items[] = ['label' => __('core.row_menu.view'), 'icon' => 'eye', 'url' => $show, 'peek' => false, 'key' => 'Enter', 'opens' => true];
    $items[] = ['label' => __('core.row_menu.preview'), 'icon' => 'search', 'url' => $show];
    if ($user?->can('governance.audit.view')) {
        $items[] = ['label' => __('core.row_menu.history'), 'icon' => 'clock',
            'url' => route('governance.audit.index', ['q' => $paper->document_no, 'module' => $module]), 'peek' => false];
    }
    $items[] = ['label' => __('core.row_menu.files'), 'icon' => 'attachment', 'url' => $show.'#attachments', 'peek' => false];

    if (($prints !== [] || $pdf || $share) && ! $isCancelled) {
        $items[] = \App\Core\Support\RowMenu::group('paper');
        foreach ($prints as $i => $print) {
            $items[] = ['label' => $print[0], 'icon' => 'printer', 'url' => $print[1], 'blank' => true, 'key' => $i === 0 ? 'F10' : null];
        }
        if ($pdf) {
            $items[] = ['label' => __('core.row_menu.pdf'), 'icon' => 'download', 'url' => $pdf, 'blank' => true];
        }
        if ($share) {
            $items[] = ['label' => __('core.row_menu.send'), 'icon' => 'share', 'url' => route('paper.share'), 'method' => 'post',
                'fields' => [
                    'route' => $share['route'],
                    'params['.$share['param'].']' => $paper->id,
                    'document_type' => $share['type'],
                    'document_id' => $paper->id,
                    'document_no' => $paper->document_no,
                    'paper' => \App\Core\Engines\Print\PaperSize::A4,
                ]];
        }
    }

    $items[] = \App\Core\Support\RowMenu::group('work');
    if ($confirmable) {
        $items[] = ['label' => __('core.row_menu.confirm'), 'icon' => 'check-circle', 'url' => $show.'#confirm', 'peek' => false,
            'tone' => 'success', 'hint' => __('core.row_menu.confirm_hint')];
    }
    if ($edit) {
        $items[] = ['label' => __('core.row_menu.edit'), 'icon' => 'edit', 'url' => $edit, 'peek' => false,
            'off' => $editable ? null : ($isCancelled ? __('core.row_menu.why_cancelled') : ($isDraft ? __('core.row_menu.why_no_key') : __('core.row_menu.why_posted')))];
    }
    foreach ($work as $row) {
        $items[] = $row;
    }

    if ($cancellable && ! $isCancelled) {
        $items[] = ['line' => true];
        $items[] = ['label' => $isDraft ? ($draftCancelLabel ?? __('core.row_menu.delete_draft')) :($cancelLabel ?? __('core.row_menu.cancel_paper')), 'icon' => 'trash', 'tone' => 'danger',
            'url' => $show.$glue.'cancel=1#cancel', 'peek' => false, 'key' => $isDraft ? 'Del' : null];
    }
@endphp

<x-ui.row-actions :items="$items" :label="__('core.table.action_menu')" />
