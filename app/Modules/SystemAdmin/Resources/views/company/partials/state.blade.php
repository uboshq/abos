{{--
    সচল না নিষ্ক্রিয় — পিলটাই বোতাম, আলাদা কলাম নয় (মালিকের নমুনা)।

    ── ⛔ সুইচটা চাপলে কিছুই হত না, ২২ সেপ্টেম্বর ২০২৬ ──────────────────
    মালিক: *"কোম্পানি লিস্টে ইন এক্টিভ/ডিলেট নাই কেন?"* — আর জিনিসটা
    ছিলই, কেবল **কাজ করত না**।

    ⓘ [[x-ui.state-toggle]]-এর ডিফল্ট `method` হলো `DELETE`, আর
    `system_admin.company.toggle` রুটটা `POST` চায়। ⚠️ ফলে পিলে চাপলে
    যেত `DELETE`, আর সার্ভার বলত **৪০৫ — ঐ পথ নেই**।

    ⛔ লাইভে মেপে দেখা: `DELETE → 405`, `POST → 419` (কেবল CSRF বাকি)।

    ⚠️ আর ব্যর্থতাটা **নীরব**: পর্দা একটা সুইচ দেখায়, মানুষ চাপেন,
    কিছু বদলায় না, আর কোথাও কোনো বার্তা নেই। ⓘ গ্রাহক ও সরবরাহকারীর
    পর্দায় `:method` স্পষ্ট লেখা ছিল — এখানেই কেবল বাদ পড়েছিল।

    ── ⭐ কেন `title` ও `aria-label` ───────────────────────────────────
    পিলটা দেখতে একটা ব্যাজের মতো, তাই কেউ আন্দাজ করেন না যে ওটা চাপা
    যায় — মালিক নিজেই খুঁজে পাননি। ⓘ এখন মাউস রাখলেই লেখা ওঠে, আর
    পর্দা-পাঠক ব্যবহারকারীও কাজটা শুনতে পান।
--}}
<x-ui.state-toggle
    :active="$company->is_active"
    :action="route('system_admin.company.toggle', $company->id)"
    method="POST"
    size="sm"
    :title="$company->is_active
        ? __('system_admin::action.deactivate_company')
        : __('system_admin::action.activate_company')"
    :aria-label="($company->is_active
        ? __('system_admin::action.deactivate_company')
        : __('system_admin::action.activate_company')).' — '.$company->name_en"

    {{--
        ⚠️ নিষ্ক্রিয় করার আগে জিজ্ঞেস করা হয়, চালু করার আগে নয়।

        ⓘ নিষ্ক্রিয় করা মানে ঐ কোম্পানির সবাই কাজ থামিয়ে দেবেন, আর
        ভুল সারিতে চাপা সহজ। ⛔ চালু করা কারো কাজ থামায় না, তাই সেখানে
        প্রশ্নটা কেবল একটা বাড়তি ক্লিক।
    --}}
    :confirm="$company->is_active ? __('system_admin::message.confirm_deactivate_company') : null" />
