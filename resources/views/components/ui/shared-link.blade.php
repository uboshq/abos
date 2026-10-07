{{--
    গ্রাহককে পাঠানোর লিংক — "পাঠান" চাপার পরে এই বাক্সটা আসে।

    ── ⚠️ কেন লিংকটা পর্দায় দেখানো হয়, নিজে থেকে পাঠানো হয় না ──────────
    ⓘ হোয়াটসঅ্যাপে **ফাইল** পাঠানোর কোনো পথ ব্রাউজার থেকে নেই — যা পাঠানো
    যায় তা লেখা, আর লেখার ভেতরে লিংক। ⛔ তাই "পাঠিয়ে দিলাম" বলে ভান করা
    হয়নি: লিংকটা মানুষের হাতে দেওয়া হয়, আর তিনি যেখানে খুশি পাঠান।

    ⓘ ৩০ দিন পরে লিংকটা নিজে থেকে মরে যায়, আর সেটা এখানেই লেখা — যাতে
    কেউ ধরে না নেন এটা চিরকালের ঠিকানা।
--}}
@if (session('shared_link'))
    @php
        $link = session('shared_link');
        $text = trim(($message ?? '').' '.$link);
    @endphp

    <div role="status" x-data="sharedLink({ url: @js($link) })"
         class="mb-4 rounded-(--radius-card) border border-(--color-brand-500)
                bg-(--color-surface-card) p-3">
        <p class="mb-2 text-sm font-medium">{{ __('core.print.link_ready') }}</p>

        <div class="flex flex-wrap items-center gap-2">
            {{-- ⓘ ঘরটা পড়ার জন্য, লেখার জন্য নয় — কিন্তু বেছে নিয়ে কপি করা যায়,
                 কারণ ক্লিপবোর্ডের অনুমতি সব ব্রাউজারে সমান নয়। --}}
            <input type="text" readonly value="{{ $link }}" x-on:focus="$el.select()"
                   class="h-(--spacing-field) min-w-0 flex-1 rounded-(--radius-field)
                          border border-(--color-border) bg-(--color-surface-muted) px-3 text-sm">

            <x-ui.button type="button" tone="secondary" x-on:click="copy()">
                <span x-show="! copied">{{ __('core.print.copy_link') }}</span>
                <span x-show="copied" x-cloak>{{ __('core.action.copied') }}</span>
            </x-ui.button>

            <x-ui.button tone="primary" target="_blank" rel="noopener"
                         :href="'https://wa.me/?text='.urlencode($text)">
                {{ __('core.print.whatsapp') }}
            </x-ui.button>
        </div>
    </div>
@endif
