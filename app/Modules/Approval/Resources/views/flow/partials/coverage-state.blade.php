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
