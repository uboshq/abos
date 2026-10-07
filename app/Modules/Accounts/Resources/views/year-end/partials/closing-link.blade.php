{{-- ⭐ সমাপনী ভাউচারের লিঙ্ক — বছরটা কখনো বন্ধ হলে (ভাউচারের পরিকল্পনা ৩ঙ, ৭ অক্টোবর ২০২৬) --}}
@if ($no !== null)
    <a href="{{ route('accounts.year_end.closing', $year) }}" data-closing-link
       class="num text-(--color-brand-500) underline-offset-2 hover:underline">{{ $no }}</a>
@else
    <span class="text-(--color-ink-muted)">—</span>
@endif
