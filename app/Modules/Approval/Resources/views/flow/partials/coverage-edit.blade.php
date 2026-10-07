{{--
    ⓘ যেখানে নিয়ম আছে সেখানে "সম্পাদনা", যেখানে নেই সেখানে "বসান" —
    আর দ্বিতীয়টাই এই পর্দার আসল কাজ।

    ⚠️ মডিউল ও কাজ ঠিকানায় পাঠানো হয়, যাতে ফর্মটা খোলার সাথে সাথেই
    ঠিক জায়গাটা বাছা থাকে। ⛔ নাহলে মানুষ এখান থেকে গিয়ে আবার
    ড্রপডাউনে ঐ জায়গাটা খুঁজতেন — আর ঠিক সেই খোঁজাটা এড়ানোর জন্যই
    পর্দাটা বানানো।
--}}
@if ($row['flow'] === null)
    <a href="{{ route('approval.flow.create', ['module' => $row['module'] ?? null, 'action' => $row['action']]) }}"
       class="text-(--color-brand-500) underline-offset-2 hover:underline">{{ __('approval::action.new_flow') }}</a>
@else
    <a href="{{ route('approval.flow.edit', $row['flow']->id) }}"
       class="text-(--color-brand-500) underline-offset-2 hover:underline">{{ __('core.action.edit') }}</a>
@endif
