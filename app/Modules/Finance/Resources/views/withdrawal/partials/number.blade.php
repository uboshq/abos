{{--
    উত্তোলনের নম্বর → যে ভাউচারে টাকাটা বসেছে।

    ── ⓘ নিরীক্ষা চ১, ২১ সেপ্টেম্বর ২০২৬ ─────────────────────────────
    ⛔ নম্বরটা নিছক লেখা ছিল। ⓘ উত্তোলনের নিজের কোনো পাতা নেই — লেখা
    হয় ফর্মে, আর বসার পর সে একটা ভাউচার — তাই নম্বরটা ভাউচারেই নামে।

    ⚠️ খসড়ায় ভাউচার নেই, তখন নিছক লেখাই থাকে: টাকাটা তখনো কোথাও
    বসেনি, নামার মতো পাতাও নেই।

    @param $row  উত্তোলনের মডেল
--}}
@if ($row->voucher)
    <a href="{{ route('accounts.voucher.show', $row->voucher) }}"
       class="num text-(--color-brand-600) underline-offset-2 hover:underline">{{ $row->document_no }}</a>
@else
    <span class="num">{{ $row->document_no }}</span>
@endif
