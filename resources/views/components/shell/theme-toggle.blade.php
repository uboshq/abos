{{--
    আলো ↔ অন্ধকার — টপবারের এক চাপে।

    চেহারা পাতায় এটা আগেও ছিল, রেডিও বোতাম হিসেবে। কিন্তু যে জিনিস দিনে
    দুবার বদলায়, তার জন্য একটা পাতা খোলা, বেছে নেওয়া আর সেভ চাপা — তিন ধাপ
    বেশি। সন্ধ্যায় ডিপোর আলো কমে আসে, তখনই লোকে অন্ধকার থিম চায়, আর তখন
    হাতের কাছে সুইচটা থাকা দরকার।

    পাতায় রেডিওগুলো থেকেই যায়: ওখানে থিম, রং আর ভাষা একসাথে সাজানো, আর
    কোনটা বেছে রাখা আছে সেটা এক নজরে দেখা যায়। এই সুইচটা সেটার শর্টকাট,
    বিকল্প নয় — দুটোই একই কলামে লেখে।

    ছবিটা বলে *কী হবে*, *কী আছে* তা নয়: এখন আলো হলে চাঁদ দেখায়, কারণ
    চাপলে অন্ধকার হবে। উল্টোটা করলে লোকে ভাবে সুইচটা আটকে গেছে।
--}}
@php
    $dark = (auth()->user()?->theme ?? 'light') === 'dark';
@endphp

<form method="POST" action="{{ route('theme.switch') }}" class="contents">
    @csrf
    <button type="submit" name="theme" value="{{ $dark ? 'light' : 'dark' }}"
            class="flex size-(--spacing-touch) items-center justify-center rounded-(--radius-field)
                   text-(--color-ink-muted) transition-colors hover:bg-(--color-surface-hover)"
            title="{{ $dark ? __('core.appearance.light') : __('core.appearance.dark') }}"
            aria-label="{{ $dark ? __('core.appearance.light') : __('core.appearance.dark') }}">
        @if ($dark)
            {{-- সূর্য: চাপলে আলো হবে। --}}
            <svg viewBox="0 0 24 24" class="size-(--spacing-icon) fill-current" aria-hidden="true">
                <path d="M12 17a5 5 0 1 1 0-10 5 5 0 0 1 0 10Zm0-2a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm-1-13h2v3h-2V2Zm0 17h2v3h-2v-3ZM2 11h3v2H2v-2Zm17 0h3v2h-3v-2ZM4.2 5.6l1.4-1.4 2.1 2.1-1.4 1.4-2.1-2.1Zm12.1 12.1 1.4-1.4 2.1 2.1-1.4 1.4-2.1-2.1Zm2.1-13.5 1.4 1.4-2.1 2.1-1.4-1.4 2.1-2.1ZM5.6 19.8l-1.4-1.4 2.1-2.1 1.4 1.4-2.1 2.1Z"/>
            </svg>
        @else
            {{-- চাঁদ: চাপলে অন্ধকার হবে। --}}
            <svg viewBox="0 0 24 24" class="size-(--spacing-icon) fill-current" aria-hidden="true">
                <path d="M12.1 3a9 9 0 1 0 8.9 10.4A7 7 0 0 1 12.1 3Z"/>
            </svg>
        @endif
    </button>
</form>
