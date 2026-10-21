{{--
    ⛔ দুই হাতের অনুরোধ — অনুরোধকারী ছাড়া সই দিতে পারেন এমন কেউ নেই; কোন চাবি কাকে দিতে হবে তা বাংলায় (cb-র রিভিউ, a6,
    ২১ অক্টোবর ২০২৬; [[ApprovalEngine::noSecondSignerNote()]])। ⓘ বেতনের রান, বেতনের পাতা, কর্মীর ব্যাংক-ব্যানার আর অনুমোদনের পাতা — এক উৎস।
--}}
@props(['approval' => null])
@php
    $note = $approval === null ? null : app(\App\Core\Engines\Approval\ApprovalEngine::class)->noSecondSignerNote($approval);
@endphp
@if ($note !== null)
    <p data-no-second-signer role="alert"
       class="mt-2 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm text-(--color-badge-danger-ink)">
        {{ $note }}
    </p>
@endif
