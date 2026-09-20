{{--
    সরবরাহকারীর নাম → তাঁর নিজের পাতা।

    ⭐ মালিকের কথা, ২০ সেপ্টেম্বর ২০২৬: *"সব জায়গায় হাইপার লিংক দেওয়ার কথা"*।
    ⓘ এই পর্দায় প্রশ্নটা সবসময় জোড়া: "আজ কাকে দিতে হবে" আর "তাঁর কাছে
    সব মিলিয়ে কত বাকি" — দ্বিতীয়টা তাঁর পাতায়, এক ক্লিক দূরে।
--}}
@if ($bill->supplier_id)
    <a href="{{ route('supplier.show', $bill->supplier_id) }}"
       class="text-(--color-brand-600) underline-offset-2 hover:underline">{{ $bill->supplier?->name() ?? '—' }}</a>
@else
    <span class="text-(--color-ink-muted)">—</span>
@endif
