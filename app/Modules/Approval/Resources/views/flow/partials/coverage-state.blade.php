{{--
    এই জায়গায় সই লাগে কি না — তিনটা অবস্থা, আর তিনটাই আলাদা কথা।

    ⓘ "বসানো নেই" আর "বসানো আছে কিন্তু বন্ধ" এক নয়: প্রথমটা মানে কেউ
    কোনোদিন সিদ্ধান্তই নেয়নি, দ্বিতীয়টা মানে নিয়মটা লেখা আছে কিন্তু
    ইচ্ছে করে থামানো। ⚠️ এক রঙে দেখালে দ্বিতীয়টা ভুলে যাওয়া সহজ।
--}}
@if ($row['flow'] === null)
    <span class="rounded-full bg-(--color-surface-muted) px-2 py-0.5 text-2xs text-(--color-ink-muted)">
        {{ __('approval::message.coverage_none') }}
    </span>
@elseif ($row['dormant'])
    <span class="rounded-full bg-(--color-badge-pending-bg) px-2 py-0.5 text-2xs text-(--color-badge-pending-ink)">
        {{ __('approval::message.coverage_off') }}
    </span>
@else
    <span class="rounded-full bg-(--color-badge-success-bg) px-2 py-0.5 text-2xs text-(--color-badge-success-ink)">
        {{ __('approval::message.coverage_on') }}
    </span>
@endif

{{-- ⛔ ঘড়ি বসানো, যাওয়ার জায়গা নেই — ২৪ সেপ্টেম্বর ২০২৬।

     ── ⚠️ কেন এই লাইনটা ঠিক এখানে ──────────────────────────────────
     ⓘ [[ApprovalExceptions]] ফাঁকটা আলাদা পর্দায়ও দেখায়। ⛔ তবু এখানে
     লাগে, আর কারণটা মানুষের: মালিক সময়সীমাটা বসান **এই** সারিটার
     সম্পাদনা থেকে, আর তখনই তিনি ধরে নেন দেরি হলে কিছু একটা হবে।

     ⚠️ গন্তব্য না বসালে কাগজ **কোথাও যায় না** — সময় পার হয়, ঘণ্টার
     কমান্ড চলে, আর কিছুই ঘটে না। ⓘ ভুলটা সম্পূর্ণ নীরব, তাই সেটা
     যেখানে জন্মায় সেখানেই বলা হয়। --}}
@php
    $sla = app(\App\Core\Engines\Approval\ApprovalSla::class);

    $holes = collect($row['flow']?->steps ?? [])
        ->filter(fn ($step) => $sla->hasHoleAt($step))
        ->pluck('level')
        ->all();
@endphp

@if ($holes !== [])
    <span class="mt-1 block rounded-(--radius-field) bg-(--color-badge-danger-bg) px-2 py-0.5
                 text-2xs text-(--color-badge-danger-ink)"
          title="{{ __('approval::message.escalate_hint') }}">
        {{ __('approval::message.coverage_hole', ['levels' => implode(', ', $holes)]) }}
    </span>
@endif
