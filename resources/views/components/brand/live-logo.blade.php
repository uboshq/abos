@props(['class' => '', 'iconOnly' => false])
{{--
    ⭐ জীবন্ত ABOS লোগো — A, সোনালি বিন্দু আর "abos", দশটা নড়াচড়া এলোমেলো ক্রমে বিরতি ছাড়া (মালিক, ১ নভেম্বর ২০২৬)।
    ⓘ ছবি `public/brand/live/`-এ, স্তর আলাদা (A, বিন্দু, চারটা অক্ষর) যাতে আলাদা নড়ে; নড়াচড়া [[live-logo.css]], ক্রম [[live-logo.js]]।
    ⓘ পর্দা-পাঠকের কাছে কেবল "ABOS" — ছবিগুলো সব `alt=""`।
--}}
{{-- ⓘ `icon-only` — কেবল A আর সোনালি বল, নড়াচড়া একই (বলের লাফ, ঝলক); রেলের হোম বোতামে (মালিক, ১৩ নভেম্বর ২০২৬:
     "মডিউল বারে হোম বাটনে … নতুন আইকনের বলটা হচ্ছে গোল্ডেন, এই বলটা দিয়ে এটাও জীবন্ত করা কথা ছিল") --}}
<span data-abos-live role="img" aria-label="ABOS" {{ $attributes->merge(['class' => 'abos-live '.($iconOnly ? 'abos-live-icon ' : '').$class]) }}>
    <span class="lv-icon">
        <img class="lv-a" src="{{ asset('brand/live/a.png') }}" alt="">
        <img class="lv-d" src="{{ asset('brand/live/dot.png') }}" alt="">
    </span>
    @if (! $iconOnly)
    <span class="lv-word">
        @foreach (['a', 'b', 'o', 's'] as $letter)
            <img class="lv-l lv-l{{ $letter }}" src="{{ asset('brand/live/l-'.$letter.'.png') }}" alt="">
        @endforeach
    </span>
    <i class="lv-line" aria-hidden="true"></i>
    @endif
    <i class="lv-sheen" aria-hidden="true"></i>
</span>
