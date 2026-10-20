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
        {{-- সিল-টিক — আকারটা নিজের, তাই আলো-অন্ধকার দুই থিমেই একই (ফন্টের ইমোজি নয়) --}}
        <svg viewBox="0 0 24 24" class="size-(--spacing-icon) fill-current" aria-hidden="true">
            <path fill-rule="evenodd" d="M12 2 9.6 4.2 6.4 3.8 5.6 7 2.8 8.6 4 11.6 2.8 14.6 5.6 16.2 6.4 19.4 9.6 19 12 21.2 14.4 19l3.2.4.8-3.2 2.8-1.6-1.2-3 1.2-3-2.8-1.6-.8-3.2-3.2.4L12 2Zm-1.2 13.4 5.6-5.6-1.4-1.4-4.2 4.2-2-2-1.4 1.4 3.4 3.4Z"/>
        </svg>
        @if ($pending > 0)
            <span class="absolute -end-0.5 -top-0.5 flex min-w-4 items-center justify-center rounded-full bg-(--color-danger) px-1
                         text-[10px] font-semibold leading-4 text-white" aria-hidden="true">{{ $pending > 99 ? '99+' : $pending }}</span>
        @endif
    </a>
@endif
