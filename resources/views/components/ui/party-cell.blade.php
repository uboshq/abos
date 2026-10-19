@props([
    /** কাগজের নম্বর — লেখা, বা `$slot` (লিংক/ড্রিল) */
    'no' => null,

    /** পক্ষের নাম, পয়েন্টসহ — "নাম · পয়েন্ট" ([[PartyRegistry::labelsOf()]]); null/খালি হলে কেবল নম্বর */
    'party' => null,
])

{{--
    ⭐ নম্বরের পাশে পক্ষ — মালিক, ১৯ অক্টোবর ২০২৬: *"অনেক জায়গায় তালিকায় শুধু ভাউচার নম্বর দেওয়া। সেখানে নম্বরের পাশে গ্রাহকের নাম
    আর পয়েন্টের নাম দিলে সহজে বোঝা যায়।"*

    ⓘ এক লাইনে (১০৮০p-এ সারি ভাঙে না): নম্বর পুরো, পক্ষ কাটা পড়লে "…" আর পুরো নাম `title`-এ — মাউস রাখলে, স্ক্রিন-রিডারেও।
    ⚠️ নাম আনে ডাকনেওয়ালা, একবারে, গোটা পাতার ([[PartyRegistry::labelsOf()]]) — এই ঘর কখনো নিজে প্রশ্ন করে না।
--}}
@php $party = is_string($party) ? trim($party) : ''; @endphp
<span {{ $attributes->merge(['class' => 'inline-flex max-w-full min-w-0 items-baseline gap-1.5 whitespace-nowrap']) }}>
    <span class="shrink-0">{{ $slot->isEmpty() ? $no : $slot }}</span>
    @if ($party !== '')
        <span class="min-w-0 max-w-[24ch] truncate text-(--color-ink-muted)" title="{{ $party }}" data-party data-party-beside>· {{ $party }}</span>
    @endif
</span>
