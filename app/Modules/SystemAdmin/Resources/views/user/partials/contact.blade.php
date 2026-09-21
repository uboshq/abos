{{--
    যোগাযোগ — ইমেইল আর মোবাইল, দুটোই ছোঁয়া যায়।

    ── ⓘ কেন দুটো একই ঘরে ─────────────────────────────────────────────
    মালিকের নমুনায় এটা একটা কলাম, দুটো নয়। ⚠️ আলাদা করলে ইমেইলের
    কলামটা সবচেয়ে চওড়া হয়ে বাকি সব ঘরকে চেপে ধরত।

    ── ⚠️ লিংক দুটো সাজসজ্জা নয় ───────────────────────────────────────
    প্রশাসক এই তালিকা থেকেই মানুষকে ধরেন। ⓘ `mailto:`/`tel:` না থাকলে
    নম্বরটা হাতে টুকে ফোনে টাইপ করতে হয় — আর সেখানেই একটা অঙ্ক ভুল যায়।
--}}
<span class="block truncate">
    <a href="mailto:{{ $user->email }}" class="hover:underline">{{ $user->email }}</a>
</span>

@if ($user->mobile)
    <span class="block truncate text-2xs text-(--color-ink-muted)">
        <a href="tel:{{ $user->mobile }}" class="hover:underline">{{ $user->mobile }}</a>
    </span>
@endif
