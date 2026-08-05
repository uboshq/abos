{{--
    অনুমোদন — আমার সিদ্ধান্তের অপেক্ষায় কতগুলো।

    ঘণ্টার পাশে, কিন্তু ঘণ্টা নয়। ঘণ্টা বলে "কিছু একটা ঘটেছে"; এটা বলে
    "কেউ আপনার জন্য থেমে আছে"। দ্বিতীয়টা অন্য জাতের জরুরি: ওদিকে একটা
    চালান আটকে আছে, একটা গাড়ি গেটে দাঁড়িয়ে আছে।

    <b>সংখ্যাটা আমার, কোম্পানির নয়।</b> স্ট্যাটাস বারের নোটিশটা কোম্পানির
    সব ঝুলে থাকা অনুমোদন গোনে — ওটা মালিকের প্রশ্ন। এখানে গোনা হয় কেবল
    সেগুলো যেখানে সিদ্ধান্তটা এই মানুষটার, কারণ ব্যাজটা তার নিজের কাজের
    তালিকা।

    <b>শূন্য হলে ব্যাজ নেই।</b> "০" প্রতিদিন দেখে লোকে ব্যাজ দেখা বন্ধ করে
    দেয়, আর যেদিন সংখ্যাটা ৩ হয় সেদিনও দেখে না।

    <b>পর্দাটা না থাকলে এটা লিংক নয়।</b> Phase 0-তে অনুমোদনের টেবিল আছে,
    দেখার পর্দা এখনো নেই। তখন চিহ্নটা থাকে — জায়গাটা ধরে রাখে, মডিউল এলে
    কোথায় বসবে তা আগেই বলে দেয় — কিন্তু চাপা যায় না এবং চাপার মতো দেখায়ও
    না। যে বোতাম চাপা যায় অথচ কিছুই হয় না, সেটাই সবচেয়ে খারাপ স্টাব।
--}}
@php
    $pending = 0;
    $hasScreen = \Illuminate\Support\Facades\Route::has('approvals');

    if (auth()->check() && \Illuminate\Support\Facades\Schema::hasTable('approvals')) {
        $pending = \Illuminate\Support\Facades\DB::table('approvals')
            ->where('company_id', \App\Core\Support\CompanyContext::id())
            ->where('status', 'pending')
            // যেখানে সিদ্ধান্তটা এই মানুষটার। কলামটা না থাকলে গোটা
            // কোম্পানির সংখ্যা — ভুল উত্তরের চেয়ে বড় উত্তর ভালো, আর
            // পর্দাটা এলে এটা নিজে থেকেই সরু হয়ে আসবে।
            ->when(
                \Illuminate\Support\Facades\Schema::hasColumn('approvals', 'current_approver_id'),
                fn ($q) => $q->where('current_approver_id', auth()->id()),
            )
            ->count();
    }
@endphp

{{-- পর্দাটা আসার আগ পর্যন্ত এটা লিংক নয়, লেবেল — চাপলে কোথাও যায় না বলে
     চাপার মতো দেখায়ও না (কোনো hover, কোনো হাতের কার্সার)। যে বোতাম চাপা
     যায় অথচ কিছু হয় না, সেটাই সবচেয়ে খারাপ ধরনের স্টাব।

     আগে এটা ফিকেও করা ছিল (opacity-45)। কিন্তু চিহ্নটার রংই তার পরিচয় —
     ফিকে করায় সবুজটা ধূসর হয়ে যেত, আর টপবারে ওটা আর চেনাই যেত না। এখন
     রং পুরো, শুধু আচরণটা নিষ্ক্রিয়: চেনা যায়, কিন্তু চাপার মতো দেখায় না। --}}
@php
    $tag = $hasScreen ? 'a' : 'span';

    /*
     * href-টা এখানে তৈরি, ট্যাগের ভেতরে @if দিয়ে নয়।
     *
     * `<{{ $tag }} @if (...) href="..." @endif>` লিখলে Blade-এর ট্যাগ
     * পার্সার if/endif-এর গোনা হারিয়ে ফেলে, আর কম্পাইল করা ফাইলে একটা
     * বাড়তি endif পড়ে থাকে — "syntax error, unexpected token endif"।
     * ফল: শেল আঁকে এমন প্রতিটা পাতায় ৫০০।
     *
     * ভুলটা চোখে পড়ে না, কারণ সোর্সটা পড়তে ঠিকই দেখায়। এই বিল্ডে
     * অ্যাট্রিবিউটের ভেতরে ডিরেক্টিভ নিয়ে এটাই তৃতীয় ফাঁদ।
     */
    $href = $hasScreen ? 'href="'.e(route('approvals')).'"' : '';
@endphp

<{{ $tag }} {!! $href !!}
   @class([
       'relative flex size-(--spacing-touch) items-center justify-center rounded-(--radius-field)',
       'transition-colors hover:bg-(--color-surface-hover)' => $hasScreen,
   ])
   title="{{ $hasScreen ? __('core.notice.awaiting_mine') : __('core.notice.approvals_later') }}"
   aria-label="{{ $hasScreen ? __('core.notice.awaiting_mine') : __('core.notice.approvals_later') }}">
        {{-- অনুমোদনের সিল — আঁকা, ইমোজি নয়।

             একবার ✅ ইমোজি বসানো হয়েছিল, ubos-dms যেভাবে করে। কিন্তু
             ইমোজির আঁকাটা ফন্টের, আর ফন্ট মেশিনভেদে আলাদা: Windows-এর
             Segoe UI Emoji-তে ওটা সবুজ চারকোনা বাক্স, Noto-তে দাঁতানো
             সিল। ফলে একই চিহ্ন এক মেশিনে একরকম, অন্যটায় অন্যরকম — আর
             ব্যবহারকারী যেটা চেনেন সেটা সিলটাই।

             টিকটা কাটা, আঁকা নয় (fill-rule="evenodd")। দুইটা আলাদা path
             দিলে গাঢ় থিমে সাদা টিকটা সবুজের উপর বসে থাকত; গর্ত করায়
             সেখান দিয়ে পেছনের রংটাই দেখা যায়, থিম যা-ই হোক। --}}
        <svg viewBox="0 0 24 24" aria-hidden="true"
             class="size-(--spacing-icon) fill-(--color-approval-seal)">
            <path fill-rule="evenodd" clip-rule="evenodd"
                  d="M12.00 0.60L14.41 3.02L17.70 2.13L18.58 5.42L21.87 6.30L20.98 9.59L23.40 12.00L20.98 14.41L21.87 17.70L18.58 18.58L17.70 21.87L14.41 20.98L12.00 23.40L9.59 20.98L6.30 21.87L5.42 18.58L2.13 17.70L3.02 14.41L0.60 12.00L3.02 9.59L2.13 6.30L5.42 5.42L6.30 2.13L9.59 3.02Z
                     M10.35 16.62L6.05 12.32L7.62 10.75L10.35 13.48L16.38 7.45L17.95 9.02L10.35 16.62Z"/>
        </svg>

        @if ($pending > 0)
            <span class="absolute -end-0.5 -top-0.5 flex min-w-4 items-center justify-center rounded-full
                         bg-(--color-danger) px-1 text-[10px] font-semibold leading-4 text-white">
                {{ $pending }}
            </span>
        @endif
</{{ $tag }}>
