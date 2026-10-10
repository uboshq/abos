{{--
    ⓘ মাপের নিয়ম — ১৯২০×১০৮০-এ এক পর্দা, ছোট পর্দায় নিচে নিচে।

    ⓘ আটটা সংখ্যা চওড়া পর্দায় এক সারি, ট্যাবে চারটা, ফোনে দুইটা; বিল্ডে `xl:grid-cols-8` ক্লাস নেই, তাই এখানে।
    ⚠️ নির্দিষ্ট উচ্চতা (`--exec-board`, `--exec-trend` …) কেবল চওড়া পর্দায় — ফোনে ঘরগুলো একটার নিচে আরেকটা বসে, তখন উচ্চতা বেঁধে
    রাখলে একটা আরেকটার উপর চড়ত।
--}}
<style @nonce>
    /* ⓘ পাঁচটা উচ্চতা এক জায়গায়, নাম ধরে — পর্দায় হাতে লেখা `height: …px` নেই (EveryScreenObeysTheThemeTest)।
       ঘন থিম বা পরের নকশা এখানে একটা সংখ্যা বদলালেই সব পাতা একসাথে বদলায়। */
    :root { --exec-bar: 56px; --exec-figure: 90px; --exec-board: 420px; --exec-trend: 260px; --exec-list: 330px; }
    [data-headline] { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    @media (min-width: 768px) { [data-headline] { grid-template-columns: repeat(4, minmax(0, 1fr)); } }
    @media (min-width: 1280px) { [data-headline] { grid-template-columns: repeat(8, minmax(0, 1fr)); } }
    @media (max-width: 1279px) { [data-fit] { height: auto !important; padding-block: 0.5rem; } [data-fit] > * { max-height: 420px; } }
</style>
