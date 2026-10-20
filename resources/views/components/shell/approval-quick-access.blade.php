{{--
    ⭐ অনুমোদন — বারের "আমার কাজ" (SAP Fiori shell bar-এর My Inbox ধরনের কাজ; মালিক, ২০ অক্টোবর ২০২৬: ＋ তৈরি আর 🔔-এর মাঝে)।

    ⓘ সংখ্যাটা সইয়ের পাতার হুবহু একই হিসাব — [[ApprovalEngine::pendingQueryFor()]], যা অনুমোদনের ইনবক্স ([[ApprovalInboxController]])
    আর ফোনের আজকের পাতা গোনে। ⛔ আগে এখানে নিজের আলাদা গোনা ছিল (`approvals.current_approver_id`, আর সেই কলাম না থাকলে গোটা
    কোম্পানির সব অপেক্ষমাণ) — ইনবক্সের সংখ্যার সাথে মিলত না।

    ⓘ যিনি কোনো চালু ছকে সইদাতা নন ([[ApprovalEngine::signers()]]), তাঁর বারে চিহ্নটাই নেই — চাপার মতো কিছু নেই এমন বোতাম রাখা
    স্টাব। ⓘ শূন্য হলে ব্যাজ নেই; ৯৯-এর বেশি হলে "99+"। ⓘ নাম: "অনুমোদন, 5টা অপেক্ষায়" — সংখ্যা ইংরেজি অঙ্কে (মালিকের "খ")।
--}}
@php
    $user = auth()->user();
    $engine = app(\App\Core\Engines\Approval\ApprovalEngine::class);
    $isSigner = $user !== null
        && \Illuminate\Support\Facades\Route::has('approval.inbox.index')
        && array_key_exists((int) $user->id, $engine->signers()); // ⓘ signers(): আইডি => নাম
    $pending = $isSigner ? $engine->pendingQueryFor($user)->count() : 0;
    $label = __('core.notice.approvals_waiting', ['count' => $pending]);
@endphp
@if ($isSigner)
    <a href="{{ route('approval.inbox.index') }}" data-live="approvals" data-shell-approvals="{{ $pending }}"
       class="relative flex size-(--spacing-touch) items-center justify-center rounded-(--radius-field)
              text-(--color-topbar-ink-muted) transition-colors hover:bg-(--color-topbar-hover) hover:text-(--color-topbar-ink)"
       title="{{ $label }}"
       aria-label="{{ $label }}">
        {{-- ✅ টিক-বাক্স — Material Symbols-এর check_box, নমুনার ✅-এর আকার (ফন্টের ইমোজি নয়, তাই দুই থিমেই একই)।
             ⛔ আগের খাঁজকাটা সিলটা ছোট মাপে ⚙-এর মতো দেখাত (fe-র ছবি-যাচাই, ২০ অক্টোবর ২০২৬) --}}
        <svg viewBox="0 0 24 24" class="size-(--spacing-icon) fill-current" aria-hidden="true">
            <path d="M19 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2Zm-9 14-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9Z"/>
        </svg>
        @if ($pending > 0)
            <span class="absolute -end-0.5 -top-0.5 flex min-w-4 items-center justify-center rounded-full bg-(--color-danger) px-1
                         text-[10px] font-semibold leading-4 text-white" aria-hidden="true">{{ $pending > 99 ? '99+' : $pending }}</span>
        @endif
    </a>
@endif
