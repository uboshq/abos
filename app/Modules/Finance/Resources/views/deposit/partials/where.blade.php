{{-- কোথায় রাখা — প্রতিষ্ঠান, শাখা, আর ব্যাংকের নিজের নম্বর।

     ── কেন নম্বরটা এখানে, আলাদা কলামে নয় ─────────────────────────────
     ফোনে ব্যাংক ওই নম্বরটাই চায়, আর সেটা প্রতিষ্ঠানের নাম ছাড়া কোনো
     কাজে লাগে না — "১২৩৪৫৬" কোন ব্যাংকের তা না জানলে অর্থহীন। এক
     ঘরে থাকলে দুইটা একসাথে পড়া যায়; আলাদা কলামে চোখ দুইবার যেত। --}}
<span class="inline-flex flex-col">
    <span>{{ $deposit->institution }}</span>

    @if ($deposit->branch_name || $deposit->reference_no)
        <span class="text-2xs text-(--color-ink-muted)">
            {{ collect([$deposit->branch_name, $deposit->reference_no])->filter()->implode(' · ') }}
        </span>
    @endif
</span>
