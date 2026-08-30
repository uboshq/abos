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

    {{-- বাঁধা থাকলে সেটা বলা হয়, চুপ থাকা হয় না।

         ---- কেন এটা তালিকাতেই ----
         বাঁধা জমা তালিকায় "আছে" দেখায়, অথচ ধার শোধ না হওয়া পর্যন্ত
         ভাঙানো যায় না। পার্থক্যটা না বললে কেউ দরকারের দিনে ওই টাকার
         উপর ভরসা করে সিদ্ধান্ত নেবেন -- আর ওটাই সবচেয়ে দামি ভুল।

         ধারটা শোধ হয়ে গেলে লেখাটাও চলে যায়: বন্ধক থাকে দায়ের জন্য,
         আর দায় না থাকলে বন্ধকেরও কারণ থাকে না
         ([[Deposit::isLocked()]])। --}}
    @if ($deposit->isLocked())
        <span class="mt-0.5 inline-flex w-fit rounded-(--radius-field) bg-(--color-badge-warning-bg)
                     px-2 py-0.5 text-2xs text-(--color-badge-warning-ink)">
            {{ __('finance::state.pledged', ['loan' => $deposit->pledgedToLoan->document_no]) }}
        </span>
    @endif
</span>
