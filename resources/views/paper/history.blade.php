{{--
    এই কাগজটা কে কখন বের করেছে।

    ── ⭐ কেন সংখ্যাটার পিছনে একটা পাতা ─────────────────────────────────
    মালিক গোনাটা চেয়েছিলেন *"কয়টা কাগজ প্রিন্ট হল কয়টা শেয়ার হইল"* —
    কিন্তু গোনা দিয়ে কোনো তর্ক থামে না। ⓘ গ্রাহক "বিল পাইনি" বললে
    দরকার হয় **কে পাঠিয়েছিল, কখন, আর তিনি খুলেছিলেন কি না** — সেই তিনটা
    উত্তর এই পাতায়, আর সংখ্যাটা এখানেই নিয়ে আসে।

    ⚠️ IP দেখানো হয় কেবল গ্রাহকের খোলার সারিতে, নিজেদের ছাপায় নয় — নিজের
    অফিসের ভিতরের IP কোনো তথ্য নয়, কিন্তু লিংক কোথা থেকে খোলা হল সেটা
    "সত্যিই তিনি খুলেছেন কি না" প্রশ্নের একমাত্র সূত্র।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('core.print.history_title') }}</x-slot:title>

    <div class="mb-3">
        <p class="text-sm text-(--color-ink-muted)">
            {{ __('core.print.history_of', ['no' => $documentNo ?? '—']) }}
        </p>
    </div>

    @php
        $ways = [
            \App\Models\DocumentDelivery::PRINTED => 'core.print.way_printed',
            \App\Models\DocumentDelivery::DOWNLOADED => 'core.print.way_downloaded',
            \App\Models\DocumentDelivery::SHARED => 'core.print.way_shared',
            \App\Models\DocumentDelivery::OPENED => 'core.print.way_opened',
        ];

        $columns = [
            ['key' => 'when', 'label' => __('core.print.history_when'),
             'render' => fn ($r) => $r->created_at?->format('d/m/Y h:i A')],

            ['key' => 'how', 'label' => __('core.print.history_how'),
             'render' => fn ($r) => __($ways[$r->how] ?? 'core.print.way_printed')],

            /*
                ⓘ গ্রাহকের খোলায় কোনো ব্যবহারকারী নেই — লগইনই নেই, সেটাই
                নকশা। তাই ফাঁকা নয়, "গ্রাহক" লেখা হয়: ফাঁকা ঘর দেখলে
                মানুষ ভাবতেন তথ্য হারিয়ে গেছে।
            */
            ['key' => 'who', 'label' => __('core.print.history_who'),
             'render' => fn ($r) => $r->user?->name
                 ?? ($r->how === \App\Models\DocumentDelivery::OPENED
                     ? __('core.print.history_customer')
                     : '—')],

            ['key' => 'paper', 'label' => __('core.print.history_paper'),
             'render' => fn ($r) => \App\Core\Engines\Print\PaperSize::of($r->paper)->label()],

            ['key' => 'from', 'label' => __('core.print.history_from'),
             'render' => fn ($r) => $r->how === \App\Models\DocumentDelivery::OPENED
                 ? ($r->from_ip ?: '—')
                 : '—'],
        ];
    @endphp

    <x-ui.table :rows="$rows"
                :columns="$columns"
                :empty="__('core.print.history_empty')" />

    <x-ui.pager :rows="$rows" />
</x-layouts.app>
