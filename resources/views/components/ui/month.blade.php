@props([
    'name' => 'month',
    // ⓘ Y-m — খালি হলে চলতি মাস (placeholder থাকলে "সব মাস")
    'value' => null,
    // ⓘ সবচেয়ে পরের যে মাস বাছা যায় (Y-m) — ব্রাউজারের `max`-এর একই কাজ; না দিলে সামনে `ahead` মাস
    'max' => null,
    // ⓘ কত মাস পিছনে আর সামনে দেখাবে — চলতি মাস ঘিরে
    'back' => 36,
    'ahead' => 12,
    'required' => false,
    // ⓘ ঐচ্ছিক ছাঁকনিতে "সব মাস"-এর মতো খালি সারি
    'placeholder' => null,
])
{{--
    ⭐ মাস বাছাই বাংলায় — পাতা সাজানোর পরিকল্পনা ধাপ ১, ১০ অক্টোবর ২০২৬: *"ব্রাউজারের ইংরেজি 'October 2026',
    'mm/dd/yyyy' আর দেখাবে না"*।

    ⓘ ব্রাউজারের নিজের মাস-ঘর (type=month) তার ভাষা নিজে বাছে (ইংরেজি উইন্ডোজে "October 2026") — পর্দার ভাষা মানে না। ⭐ তাই
    একটা সাধারণ তালিকা: প্রতিটা সারি "অক্টোবর 2026" (চলতি ভাষায়, অন্য পর্দার মতোই `translatedFormat('F Y')`), মান `Y-m` —
    সার্ভার যা আগে পেত হুবহু তাই পায়, কোনো কন্ট্রোলার বদলায় না। জাভাস্ক্রিপ্ট নেই।

    ⓘ দেওয়া মান তালিকার সীমার বাইরে হলে সেটাও তালিকায় বসে — পুরনো মাস খুললে চুপচাপ অন্য মাসে সরে যেত না।
    ⓘ পাতার নিজের `class` দিলে সেটাই খাটে (মাপ পাতার সাথে মেলে); বাকি বৈশিষ্ট্য (data-…, x-…) সরাসরি বসে।
--}}
@php
    $toMonth = fn ($v) => \Illuminate\Support\Carbon::createFromFormat('!Y-m', (string) $v)->startOfMonth();
    $latest = filled($max) ? $toMonth($max) : now()->startOfMonth()->addMonthsNoOverflow((int) $ahead);
    $chosen = filled($value) ? $toMonth($value) : ($placeholder !== null ? null : now()->startOfMonth()->min($latest));
    $months = collect(range(0, (int) $back + (int) $ahead))
        ->map(fn (int $i) => $latest->copy()->subMonthsNoOverflow($i))
        ->when($chosen !== null, fn ($c) => $c->push($chosen))
        ->unique(fn ($m) => $m->format('Y-m'))
        ->sortByDesc(fn ($m) => $m->format('Y-m'))
        ->values();
    $base = 'h-(--spacing-field) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-3';
@endphp

<select name="{{ $name }}" data-month-select @required($required)
        class="{{ $attributes->get('class', $base) }}" {{ $attributes->except('class') }}>
    @if ($placeholder !== null)
        <option value="" @selected($chosen === null)>{{ $placeholder }}</option>
    @endif
    @foreach ($months as $m)
        <option value="{{ $m->format('Y-m') }}" @selected($chosen?->format('Y-m') === $m->format('Y-m'))>{{ $m->translatedFormat('F Y') }}</option>
    @endforeach
</select>
