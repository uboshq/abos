{{--
    একটা ছাপার ডকুমেন্টের দেহ — মাথা, ঘর, লাইন, মোট, স্বাক্ষর।

    ── কেন এটা আলাদা ফাইলে ──────────────────────────────────────────
    বেতনশিট একসাথে অনেকগুলো ছাপতে হয় — বিশ জন কর্মীর বিশটা, প্রতিটা
    নিজের পাতায়। document.blade.php একটা ডকুমেন্ট চেনে, আর সেটাই ঠিক;
    তাই দেহটা এখানে সরানো হল, আর যার একগুচ্ছ লাগে সে পাতা ভাগ করে
    এটাকেই বারবার ডাকে।

    কোরে "গুচ্ছ" বলে কোনো ধারণা যোগ করা হয়নি: গুচ্ছের দাবিটা একটামাত্র
    জায়গা থেকে এসেছে, আর একজনের দাবিতে শেয়ার্ড বিমূর্ততা বানানো নিষেধ।
--}}
    @php
        $thermal = $paper->isThermal;
        $columns = $paper->maxColumns();

        // ৫৮mm-এ তিনটা কলাম, ৮০mm-এ চারটা, A4-তে সবগুলো
        $showUnit = $columns >= 8;
        $showRate = $doc->showMoney && $columns >= 4;
        $showAmount = $doc->showMoney;

        /*
         * ⭐ ফ্রি পরিমাণ আলাদা — ১৮ সেপ্টেম্বর ২০২৬, মালিকের নির্দেশে।
         *
         * ── ⛔ অভিযোগ ────────────────────────────────────────────────
         * *"ইনভয়েস প্রিন্টিংয়ে আলাদা দেখানোর কথা, দেখাচ্ছে না।"*
         *
         * ⓘ কাগজে কেবল একটা `qty` ছিল। ⚠️ ফল: চল্লিশ পিস কেনা আর চার
         * পিস ফ্রি — দুইটা এক সংখ্যায় মিশে যেত, আর ক্রেতা বুঝতেন না
         * তাঁকে কতটা ফ্রি দেওয়া হলো। ⛔ অথচ ফ্রি দেওয়াটাই বিক্রির
         * সবচেয়ে বড় দর-কষাকষির জায়গা।
         *
         * ── ⚠️ কলামটা সব কাগজে বসে না, আর সেটা ইচ্ছাকৃত ──────────────
         * ৫৮mm থার্মালে কলামের সংখ্যাই বাজেট — এখানে একটা কলাম কাটলে
         * **পণ্যের নামটা** ভেঙে দুই-তিন লাইনে যেত, আর নামটাই সবচেয়ে
         * বেশি পড়া হয়। ⓘ তাই সরু কাগজে সংখ্যাটা নামের নিচে ছোট লেখায়
         * যায় (`note`-এর মতোই), হারায় না।
         *
         * ⛔ আর কলামটা কেবল তখনই বসে যখন **এই কাগজে সত্যিই কোনো ফ্রি
         * আছে** — নাহলে প্রতিটা চালানে একটা শূন্যের কলাম জায়গা নিত।
         */
        $hasFree = collect($doc->lines)->contains(
            fn (array $line) => ($line['free'] ?? '') !== '' && $line['free'] !== '0',
        );

        $showFree = $hasFree && $columns >= 8;
        $freeInNote = $hasFree && ! $showFree;
    @endphp

    @if ($doc->notice)
        {{-- খসড়ার সতর্কবার্তা — কাগজটা দেখেই বোঝা যেতে হবে এটা চূড়ান্ত নয়,
             নাহলে কেউ খসড়া বিল নিয়ে টাকা চাইতে চলে যেতেন --}}
        <div style="text-align: center; font-weight: bold; border: 0.4mm solid #000;
                    padding: {{ $thermal ? '1mm' : '2mm' }}; margin-bottom: {{ $thermal ? 2 : 4 }}mm;
                    font-size: {{ $thermal ? 8 : 11 }}pt;">
            {{ $doc->notice }}
        </div>
    @endif

    <table class="meta">
        @php
            $metaRows = collect($doc->meta)->filter(fn ($value) => filled($value));
            // A4-তে দুই জোড়া এক সারিতে, থার্মালে এক জোড়া — সরু কাগজে
            // দুই জোড়া দিলে মান দুই লাইনে ভেঙে যায়
            $chunks = $metaRows->chunk($thermal ? 1 : 2);

            /*
             * থার্মালে প্রতিটা ঘর এক লাইনেই।
             *
             * ── কেন ─────────────────────────────────────────────────
             * গ্রাহকের নাম লম্বা হলে (বাংলা নামে সেটাই স্বাভাবিক —
             * "বিসমিল্লাহ ডিস্ট্রিবিউশন এন্টারপ্রাইজ") মানটা দুই লাইনে
             * ভেঙে যেত, আর রসিদের মাথাটা এলোমেলো দেখাত। কাউন্টারে
             * দাঁড়িয়ে থাকা গ্রাহকের হাতে যাওয়া কাগজ ওটাই।
             *
             * লেবেলের ঘরটা ছোট করা হয়েছে (২৪ → ১৭mm): ৮০mm রোলে ছাপার
             * প্রস্থ ৭২mm, তাই মানের জন্য ৪৮ থেকে ৫৫mm খালি থাকে।
             *
             * তবু না ধরলে লেখাটা কেটে যায় — ভাঙার বদলে কাটা, কারণ
             * নামের শেষটুকু হারানো একটা এলোমেলো রসিদের চেয়ে ভালো, আর
             * সংখ্যা বা নম্বরের ঘরগুলো এত লম্বা হয়ই না।
             */
            $oneLine = fn (string $value) => $thermal
                ? mb_strimwidth($value, 0, $columns >= 4 ? 34 : 24, '…')
                : $value;
        @endphp

        @foreach ($chunks as $chunk)
            <tr>
                @foreach ($chunk as $label => $value)
                    <td class="label" style="width: {{ $thermal ? '17mm' : '22mm' }}">{{ __($label) }}</td>
                    <td @if ($thermal) style="white-space: nowrap" @endif>{{ $oneLine((string) $value) }}</td>
                @endforeach
            </tr>
        @endforeach
    </table>

    @if ($doc->lines !== [])
        <table class="lines">
            <thead>
                <tr>
                    <th style="width: {{ $thermal ? '6mm' : '10mm' }}">#</th>
                    <th>{{ __('core.print.item') }}</th>
                    @if ($showUnit)
                        <th style="width: 16mm">{{ __('core.print.unit') }}</th>
                    @endif
                    <th class="num" style="width: {{ $thermal ? '13mm' : '20mm' }}">{{ __('core.print.qty') }}</th>
                    @if ($showFree)
                        <th class="num" style="width: 16mm">{{ __('core.print.free_qty') }}</th>
                    @endif
                    {{-- ⭐ টাকার ঘর দুইটা চৌড়া — ২১ সেপ্টেম্বর ২০২৬।

                         লাখ-কোটির কমায় সংখ্যা লম্বা হয় (প্রতি লাখে একটা করে কমা),
                         আর `.num`-এ `white-space: nowrap` — না ধরলে লেখাটা ঘর ছাড়িয়ে
                         পাশের ঘরে ওঠে। ⛔ ভাঙে না, চুপচাপ বিশ্রী হয়।

                         ── ⚠️ মাপা হয়েছে ঘরের নিজের মাপে, শিরোনামের মাপে নয় ──────
                         ⛔ প্রথমবার ৯পয়েন্টে মেপে ভুল মাপ বসানো হয়েছিল — `th` ৯পয়েন্টে,
                         কিন্তু `td`-তে কোনো `font-size` নেই, তাই সে `body` থেকে পায়
                         (`$paper->fontSize` — A4-তে ১০)। ⓘ [[abos-77]] মিলিয়ে দেখে ধরেছে।

                         mPDF-এর `GetStringWidth()` দিয়ে মাপা, dejavusans, ঘরের নিজের মাপে:
                           A4 ১০pt   `12,31,87,500.00` = ২৯.২mm → ৩৬মিমি ঘরে ধরে (১২ কোটি)
                           ৮০mm ৮.৫pt `1,23,456.00`     = ১৮.১mm → ২১মিমি ঘরে ধরে (এক লাখ)
                           ৫৮ mm ৭.৫pt `12,34,567.00`    = ১৭.৭mm → ২১মিমি ঘরে ধরে (১২ লাখ)

                         ⚠️ থার্মালে এর বেশি বাড়ানো যায় না: ৮০mm-এ পণ্যের ঘর নেমে ১৭mm,
                         ৫৮-এ ১৪mm — নামটা আরও ছোট করলে পণ্য চেনাই যাবে না। ⓘ তার
                         বেশি দরকার হলে প্রশ্নটা আর মাপের নয় — রসিদে পয়সার `.00`
                         রাখা হবে কি না, আর সেটা মালিকের সিদ্ধান্ত। --}}
                    @if ($showRate)
                        <th class="num" style="width: {{ $thermal ? '17mm' : '32mm' }}">{{ __('core.print.rate') }}</th>
                    @endif
                    @if ($showAmount)
                        <th class="num" style="width: {{ $thermal ? '21mm' : '36mm' }}">{{ __('core.print.amount') }}</th>
                    @endif
                </tr>
            </thead>

            <tbody>
                @foreach ($doc->lines as $index => $line)
                    <tr>
                        <td>{{ $index + 1 }}</td>
                        <td>
                            {{ $line['name'] }}

                            {{--
                                লাইনের নিচের ছোট লেখা — ব্যাচ ও মেয়াদ।

                                ── কেন আলাদা কলাম নয় ────────────────────
                                সরু কাগজে (৫৮mm) কলামের সংখ্যাই বাজেট।
                                ব্যাচের জন্য একটা কলাম কাটলে পণ্যের নামটা
                                ভেঙে দুই-তিন লাইনে যেত, আর নামটাই সবচেয়ে
                                বেশি পড়া হয়।

                                খালি হলে কিছুই আসে না, তাই যে ব্যবসায় লট
                                ধরা হয় না তার কাগজ অবিকল আগের মতো।
                            --}}
                            @if (($line['note'] ?? '') !== '')
                                <div class="note">{{ $line['note'] }}</div>
                            @endif

                            {{-- ⭐ সরু কাগজে ফ্রি-টা এখানে — কলাম নেই, তবু
                                 সংখ্যাটা হারায় না। ⓘ পাশের `$showFree`-এর মন্তব্য দেখুন। --}}
                            @if ($freeInNote && ($line['free'] ?? '') !== '' && $line['free'] !== '0')
                                <div class="note">{{ __('core.print.free_qty') }}: {{ $line['free'] }}</div>
                            @endif
                        </td>
                        @if ($showUnit)
                            <td>{{ $line['unit'] }}</td>
                        @endif
                        <td class="num">{{ $line['qty'] }}</td>
                        @if ($showFree)
                            <td class="num">{{ ($line['free'] ?? '') !== '' && $line['free'] !== '0' ? $line['free'] : '' }}</td>
                        @endif
                        @if ($showRate)
                            <td class="num">{{ $line['rate'] }}</td>
                        @endif
                        @if ($showAmount)
                            <td class="num">{{ $line['amount'] }}</td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if ($doc->showMoney && $doc->totals !== [])
        <table class="totals">
            @foreach ($doc->totals as $label => $value)
                <tr @if ($loop->last) class="grand" @endif>
                    <td>{{ __($label) }}</td>
                    <td class="num" style="width: {{ $thermal ? '21mm' : '36mm' }}">{{ $value }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    @if ($doc->showMoney && $doc->amountInWords)
        <div class="words">
            <strong>{{ __('core.print.in_words') }}:</strong> {{ $doc->amountInWords }}
        </div>
    @endif

    @if ($doc->narration)
        <div class="words">
            <strong>{{ __('core.table.narration') }}:</strong> {{ $doc->narration }}
        </div>
    @endif

    @if ($doc->signatures !== [])
        <table class="signatures">
            <tr>
                @php
                    // সরু কাগজে একটাই স্বাক্ষরের ঘর — তিনটা পাশাপাশি দিলে
                    // প্রতিটার প্রস্থে নাম লেখাই যায় না
                    $lines = $thermal ? array_slice($doc->signatures, -1) : $doc->signatures;
                    $width = (int) round(100 / max(count($lines), 1));
                @endphp

                @foreach ($lines as $label)
                    <td style="width: {{ $width }}%">
                        <div class="sig-line">{{ __($label) }}</div>
                    </td>
                @endforeach
            </tr>
        </table>
    @endif
