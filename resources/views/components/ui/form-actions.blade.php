@props([
    // ⓘ "বাতিল" কোথায় ফেরে — সাধারণত তালিকা বা কাগজের পাতা; না দিলে বাতিল বোতাম নেই
    'cancel' => null,
    'save' => null,
])
{{--
    ⭐ ফর্মের নিচের স্থির পট্টি — পাতা সাজানোর পরিকল্পনা ধাপ ১, ১০ অক্টোবর ২০২৬: *"নিচে সবসময় দেখা যায় এমন পট্টিতে
    'বাতিল · সংরক্ষণ' ডানে"*।

    ⓘ লম্বা ফর্মে সংরক্ষণ পর্দার নিচে হারাত ([[TheSaveButtonWasOffTheScreenTest]]) — পট্টিটা `sticky`, তাই ফর্মের ভেতরে
    স্ক্রল করলেও নিচে লেগে থাকে, আর ফর্ম শেষ হলে নিজের জায়গায় বসে। ⚠️ `fixed` নয়: পাশের কলাম ঢেকে দিত (রোলের ফর্মের
    একই কারণ)। ⓘ বড় পর্দায় নিচের স্থির স্ট্যাটাস বারের ঠিক উপরে (`--spacing-status-bar`), মোবাইলে নিচের মেনুর উপরে।

    ⓘ slot-এ বাড়তি বোতাম (যেমন "খসড়া রাখুন") — বাঁয়ে, প্রধান কাজ ডানে।
--}}
<div data-form-actions
     {{ $attributes->merge(['class' => 'print-hide sticky bottom-(--spacing-bottom-nav) md:bottom-(--spacing-status-bar) z-10 mt-4
        flex flex-wrap items-center justify-end gap-2 rounded-(--radius-card) border border-(--color-border)
        bg-(--color-surface-card) px-4 py-2 shadow-lg']) }}>
    @if (! $slot->isEmpty())
        <div class="flex flex-1 flex-wrap items-center gap-2">{{ $slot }}</div>
    @endif

    @if ($cancel)
        <x-ui.button tone="secondary" :href="$cancel">{{ __('core.action.cancel') }}</x-ui.button>
    @endif

    <x-ui.button type="submit" tone="primary">{{ $save ?? __('core.action.save') }}</x-ui.button>
</div>
