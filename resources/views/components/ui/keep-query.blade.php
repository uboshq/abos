@props(['except' => []])
{{-- ⭐ ঠিকানার চালু ছাঁকনি ফর্মে ধরে রাখা — বারের নিজের ঘর (খোঁজা, সাজানো, পাতা …) ছাড়া (২৭ নভেম্বর ২০২৬; [[ListSort]])।
     ⓘ যে পাতায় বার কেবল সাজানোর জন্য ফর্মে বসে, সাজানো বদলালে সময়কাল বা ব্যাংকের মতো ছাঁকনি যাতে হারায় না।
     ⓘ এক স্তরের অ্যারে (`?status[]=a`) নামসহ; গভীরতর কিছু বাদ — ঠিকানায় যা খুশি লেখা ঘর হয়ে ফেরে না। --}}
@php
    $skip = ['q', 'sort', 'view', 'compact', 'page', 'hide', 'show', 'export', ...$except];
@endphp
@foreach (collect(request()->query())->except($skip) as $name => $value)
    @if (is_array($value))
        @foreach ($value as $one)
            @if (is_scalar($one))
                <input type="hidden" name="{{ $name }}[]" value="{{ $one }}">
            @endif
        @endforeach
    @elseif (is_scalar($value) && (string) $value !== '')
        <input type="hidden" name="{{ $name }}" value="{{ $value }}">
    @endif
@endforeach
