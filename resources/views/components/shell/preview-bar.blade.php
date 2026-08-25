{{--
    প্রিভিউ চলছে — একটা পটি যা লুকানো যায় না।

    ── কেন সবচেয়ে উপরে, আর কেন সবসময় ───────────────────────────────────
    প্রিভিউ মানে গোটা ERP অন্য রঙে দেখা। ব্যানারটা না থাকলে মানুষ ভুলে
    যেতেন, তারপর অন্য কাজে চলে যেতেন, আর একদিন পরে ভাবতেন ABOS-এর রংটাই
    বদলে গেছে — অথচ কেবল তাঁরই পর্দা, কেবল তাঁরই সেশনে।

    তাই এটা বন্ধ করার কোনো "x" নেই। বন্ধ করার একটাই উপায়: প্রিভিউটাই
    থামানো। একটা সতর্কবার্তা যেটা চুপ করানো যায়, সেটা সতর্কবার্তা নয়।

    ── রংটা টোকেন থেকেই ─────────────────────────────────────────────────
    প্রিভিউ করা রূপের টোকেন দিয়েই এটা আঁকা হয়, ইচ্ছাকৃতভাবে। ফলে
    ব্যানারটা নিজেই একটা নমুনা: যে রূপে সতর্কবার্তা পড়া যায় না, সেটা
    এখানেই ধরা পড়ে — প্রকাশের আগেই।
--}}
@php($previewing = \App\Core\Support\LookPreview::skin())

@if ($previewing)
    <div role="status"
         class="flex flex-wrap items-center justify-between gap-2 border-b border-(--color-border)
                bg-(--color-badge-warning-bg) px-4 py-2 text-sm text-(--color-badge-warning-ink)">
        <span>
            {{ __('core.look.preview_on', [
                'name' => $previewing->name,
                'minutes' => \App\Core\Support\LookPreview::MINUTES,
            ]) }}
        </span>

        <form method="POST" action="{{ route('system_admin.look.preview.stop') }}">
            @csrf
            <button type="submit"
                    class="min-h-(--spacing-touch) rounded-(--radius-field) border border-current px-3
                           font-medium underline-offset-2 hover:underline">
                {{ __('core.look.preview_stop') }}
            </button>
        </form>
    </div>
@endif
