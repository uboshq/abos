@props([
    /**
     * ট্যাবগুলো — একই তালিকার ছাঁকনি, প্রতিটা নিজের ঠিকানায় (`?tab=`)।
     *
     * [['key' => 'pending', 'label' => 'অপেক্ষমাণ', 'url' => '…', 'count' => 12, 'active' => false, 'hint' => '…'], …]
     *
     * `count` আর `hint` ঐচ্ছিক। ⚠️ যে ট্যাব খোলার চাবি নেই, তাকে পর্দা এখানে পাঠায়ই না —
     * কম্পোনেন্ট অনুমতি জানে না, আর জানার কথাও নয়।
     */
    'tabs' => [],
    /** পর্দা-পাঠকের জন্য সারিটার নাম। */
    'label' => '',
])

{{--
    তালিকার ট্যাব — একই তালিকা, ভিন্ন ছাঁকনি, ওপরে এক সারিতে।

    ⭐ নকশার পর্যালোচনা, ধাপ ৭-এর ২ (মালিক, ১ অক্টোবর ২০২৬: "ok kore daw"): মেনুর
    "অপেক্ষমাণ", "আংশিক", "ব্যাক অর্ডার", "ইতিহাস" — একই তালিকার ছাঁকনি-সারিগুলো এক তালিকার ট্যাবে।

    ── এটা কী, আর এটা কোনটা নয় ─────────────────────────────────────────
        list-tabs    (এটা)  সার্ভারের ছাঁকনি · ঠিকানায় · সব রূপে · গোনা
        view-strip          সংরক্ষিত দৃশ্য · কেবল navy রূপে
        stage-strip         কাগজের ধাপ · গোনা ও টাকা · লিংক নয়

    ⓘ প্রতিটা ট্যাব একটা সাধারণ লিংক — সার্ভার ছাঁকে, বুকমার্ক হয়, পেছনে যাওয়া চলে, JS লাগে না।

    ⚠️ ফোনের চওড়ায় সারিটা পাশে সরে (overflow-x-auto), পাতা নয় — ট্যাবগুলো ভাঙে না (whitespace-nowrap,
    shrink-0)। ⛔ নিচের দাগটা `-mb-px` দিয়ে নয়: overflow-x-auto থাকলে ঐ এক পিক্সেল খাড়া স্ক্রলবার আনত।

    ⓘ একটাও ট্যাব না থাকলে কিছুই আঁকা হয় না — খালি নিয়ন্ত্রণ "মৃত বোতাম"।
--}}
@if ($tabs !== [])
    <nav data-list-tabs aria-label="{{ $label }}"
         {{ $attributes->merge(['class' => 'mb-3 flex max-w-full gap-1 overflow-x-auto border-b border-(--color-border) text-sm']) }}>
        @foreach ($tabs as $tab)
            @php($isActive = (bool) ($tab['active'] ?? false))
            <a href="{{ $tab['url'] }}" data-tab="{{ $tab['key'] }}"
               @if ($isActive) aria-current="page" @endif
               @if (! empty($tab['hint'])) title="{{ $tab['hint'] }}" @endif
               @class([
                   'flex min-h-(--spacing-touch) shrink-0 items-center gap-2 whitespace-nowrap border-b-2 px-3',
                   'border-(--color-brand-500) font-semibold text-(--color-ink)' => $isActive,
                   'border-transparent text-(--color-ink-muted) hover:text-(--color-ink)' => ! $isActive,
               ])>
                {{ $tab['label'] }}
                @isset($tab['count'])
                    <span class="num rounded-full bg-(--color-surface-sunken) px-2 text-2xs text-(--color-ink-muted)">{{ $tab['count'] }}</span>
                @endisset
            </a>
        @endforeach
    </nav>
@endif
