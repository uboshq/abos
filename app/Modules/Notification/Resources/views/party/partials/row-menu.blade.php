{{--
    ⭐ খাতার সারির ⋯ — লেখাটা দেখুন · আবার পাঠান · অন্য পথে পাঠান · পার্টির খাতা খুলুন (নমুনা HUXqpfhxng8823eEFrvH54 ট্যাব গ)।
    ⓘ যা এখন চলে না তা ধূসর, নিচে কারণ (পথ বন্ধ, অফিসের ফোন নেই, বিদেশের নম্বর …) — লুকানো নয়।
    ⓘ "অন্য পথে" — কোন পথে যাবে তা পাশে লেখা (WhatsApp / SMS)।
--}}
@php
    $road = $roads[(int) $message->id] ?? ['again' => null, 'other' => null, 'other_road' => null];
    $items = [\App\Core\Support\RowMenu::head((string) ($message->party_name ?? ''), $message->document_no ?? '')];
    $items[] = ['label' => __('notification::party_log.menu.view'), 'icon' => 'eye', 'url' => route('notification.party_messages.show', $message->id)];
    $items[] = ['label' => __('notification::party_log.menu.again'), 'icon' => 'refresh', 'url' => route('notification.party_messages.resend', $message->id),
        'method' => 'post', 'off' => $road['again']];
    $items[] = ['label' => __('notification::party_log.menu.other'), 'icon' => 'swap', 'url' => route('notification.party_messages.other_road', $message->id),
        'method' => 'post', 'off' => $road['other'],
        'hint' => $road['other_road'] !== null ? __('notification::party_log.other_to.'.$road['other_road']) : null];
    if (($ledger = $partyRoutes[$message->party_type.':'.$message->party_id] ?? null) !== null) {
        $items[] = ['label' => __('notification::party_log.menu.ledger'), 'icon' => 'book', 'url' => route($ledger[0], $ledger[1]), 'peek' => false];
    }
@endphp

<x-ui.row-actions :items="$items" />
