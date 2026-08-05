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
     যায় অথচ কিছু হয় না, সেটাই সবচেয়ে খারাপ ধরনের স্টাব; আর যে চিহ্ন
     জায়গাটা ধরে রাখে, সেটা মডিউল এলে কোথায় বসবে তা আগেই বলে দেয়। --}}
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
       'text-(--color-ink-muted)',
       'transition-colors hover:bg-(--color-surface-hover)' => $hasScreen,
       'opacity-45' => ! $hasScreen,
   ])
   title="{{ $hasScreen ? __('core.notice.awaiting_mine') : __('core.notice.approvals_later') }}"
   aria-label="{{ $hasScreen ? __('core.notice.awaiting_mine') : __('core.notice.approvals_later') }}">
        {{-- টিক চিহ্ন — সিদ্ধান্ত দেওয়ার কাজ, আর সবুজ, কারণ অনুমোদন করাই
             স্বাভাবিক ফল। --}}
        <svg viewBox="0 0 24 24" aria-hidden="true" class="size-(--spacing-icon) fill-current">
            <path d="M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4L9 16.2Z"/>
        </svg>

        @if ($pending > 0)
            <span class="absolute -end-0.5 -top-0.5 flex min-w-4 items-center justify-center rounded-full
                         bg-(--color-danger) px-1 text-[10px] font-semibold leading-4 text-white">
                {{ $pending }}
            </span>
        @endif
</{{ $tag }}>
