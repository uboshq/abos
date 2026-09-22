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

        /*
         * ⭐ কোন কলাম, কোন ক্রমে — মালিকের সুইচ থেকে, ২২ সেপ্টেম্বর ২০২৬।
         *
         * ⓘ আগে এখানে সাতটা হাতে লেখা `$show…` ছিল, আর ক্রমটা মার্কআপেই
         * বাঁধা ছিল। ⚠️ ক্রম বদলাতে হলে ছয় জায়গায় কেটে বসাতে হত — একবার
         * শিরোনামে, একবার ঘরে — আর দুইটা মিলিয়ে না রাখলে **কাগজের
         * শিরোনাম এক কলামের, ঘরটা আরেকটার** হত। ⛔ ঐ ভুলটা চোখে ধরা
         * পড়ত না, কারণ সংখ্যাগুলো ঠিক জায়গাতেই দেখাত, কেবল নাম ভুল।
         *
         * ⭐ এখন একটাই তালিকা, আর শিরোনাম ও ঘর দুইটাই ওটাকেই লুপ করে —
         * দুইটার আলাদা হওয়ার পথটাই বন্ধ।
         */
        $cols = $profile->columnsFor($paper, $doc->showMoney, $hasFree);

        /* সরু কাগজে ফ্রি-র কলাম বাদ পড়ে, তাই সংখ্যাটা নামের নিচে যায় — হারায় না */
        $freeInNote = $hasFree && ! in_array('free', $cols, true);

        /*
         * ⭐ ব্যান্ড-ভিত্তিক ভাগ — মালিকের নমুনা, ২২ সেপ্টেম্বর ২০২৬।
         *
         * *"এই রকম একটি ফরমেট রাখ যাতে ব্যান্ড ওয়াইজ দেখা যায়"* — ঐ
         * কাগজে সারিগুলো ব্র্যান্ড ধরে দল বাঁধা, আর প্রতিটা দলের শেষে
         * একটা উপ-মোট: *"Jabed Food Sub.Total: 46,665.02"*।
         *
         * ⓘ কাজটা চোখের: পনেরো সারির বিলে কোন কোম্পানির মাল কত টাকার
         * হলো, সেটা যোগ না করেই দেখা যায় — আর পরিবেশকের হিসাব ঐ
         * সংখ্যাটা ধরেই মেলে।
         *
         * ⚠️ সারিগুলো **সাজানো হয় না**, কেবল পাশাপাশি দল দেখে উপ-মোট
         * বসে। ⛔ সাজাতে গেলে কাগজের ক্রম আর বিলে লেখা ক্রম আলাদা হয়ে
         * যেত, আর গুদামের লোক সারি ধরে মাল মেলাতে গিয়ে হারিয়ে যেতেন।
         */
        /*
         * ⭐ যোগটা এখানে হয় না — সারির গায়েই লেখা আসে।
         *
         * ⓘ [[SalesPrintController::closeEachBand()]] প্রতিটা দলের শেষ
         * সারিতে `band_total` বসিয়ে দেয়, `bcadd`-এ, **কাঁচা** অঙ্ক ধরে।
         *
         * ⛔ প্রথম খসড়ায় যোগটা এই ফাইলেই হচ্ছিল, কাগজে ছাপা লেখা থেকে
         * কমা ছেঁটে। ⚠️ ওটা দুইভাবে ভাঙত: বাংলা অঙ্কে `bcadd` কিছুই
         * বুঝত না, আর থার্মালে পয়সা ছাঁটা থাকে বলে যোগফলটা কয়েক পয়সা
         * কম আসত — ⓘ আর কয়েক পয়সার ভুল ঠিক ততটাই ভুল, কেবল ধরা পড়তে
         * বেশি সময় নেয়।
         */
        $banded = $profile->shows('band')
            && ! $thermal
            && $doc->showMoney
            && collect($doc->lines)->contains(fn (array $line) => ($line['band_total'] ?? '') !== '');
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

    @if ($profile->shows('meta'))
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
    @endif

    @if ($doc->lines !== [])
        <table class="lines">
            <thead>
                <tr>
                    @foreach ($cols as $name)
                        @php $col = $profile->column($name); @endphp

                        {{-- ⓘ ঘরের মাপগুলো [[PrintProfile::columnTable()]]-এ, আর ওগুলো
                             অনুমান নয় — mPDF-এর `GetStringWidth()` দিয়ে dejavusans-এ,
                             ঘরের নিজের ফন্ট-মাপে মাপা। ⚠️ প্রথমবার পাশের শিরোনামের
                             ৯পয়েন্ট দেখে মাপা হয়েছিল, অথচ ঘরটা ছাপে `$paper->fontSize`-এ
                             — ১০.৫% তফাত, আর ঠিক ততটুকুই উপচে পড়ত। --}}
                        <th @class(['num' => $col['num']])
                            @if ($col[$thermal ? 'thermal' : 'a4'] !== null)
                                style="width: {{ $col[$thermal ? 'thermal' : 'a4'] }}"
                            @endif>{{ __('core.print.column.'.$name) }}</th>
                    @endforeach
                </tr>
            </thead>

            <tbody>
                @foreach ($doc->lines as $index => $line)
                    <tr @class(['alt' => $profile->format->zebra && $index % 2 === 1])>
                        @foreach ($cols as $name)
                            @php $col = $profile->column($name); @endphp

                            <td @class(['num' => $col['num']])>
                                @switch($name)
                                    @case('sl')
                                        {{ $index + 1 }}
                                        @break

                                    @case('code')
                                        {{ $line['code'] ?? '' }}
                                        @break

                                    @case('name')
                                        {{--
                                            ⓘ কোডের নিজের কলাম না থাকলে কোডটা নামের সাথেই
                                            বসে — ⚠️ নাহলে যে কাগজে কোডের কলাম বন্ধ, সেখান
                                            থেকে কোডটা নীরবে উধাও হত, আর গুদামে মাল মেলানো
                                            হয় কোড ধরে, নাম ধরে নয়।
                                        --}}
                                        {{ ($line['code'] ?? '') !== '' && ! in_array('code', $cols, true)
                                            ? $line['code'].' - '.$line['name']
                                            : $line['name'] }}

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
                                             সংখ্যাটা হারায় না। --}}
                                        @if ($freeInNote && ($line['free'] ?? '') !== '' && $line['free'] !== '0')
                                            <div class="note">{{ __('core.print.free_qty') }}: {{ $line['free'] }}</div>
                                        @endif
                                        @break

                                    @case('unit')
                                        {{ $line['unit'] }}
                                        @break

                                    @case('qty')
                                        {{ $line['qty'] }}
                                        @break

                                    @case('free')
                                        {{ ($line['free'] ?? '') !== '' && $line['free'] !== '0' ? $line['free'] : '' }}
                                        @break

                                    @case('rate')
                                        {{ $line['rate'] }}
                                        @break

                                    @case('amount')
                                        {{ $paper->money($line['amount']) }}
                                        @break
                                @endswitch
                            </td>
                        @endforeach
                    </tr>

                    {{-- দলের শেষ সারি — তার নিচেই উপ-মোট --}}
                    @if ($banded && ($line['band_total'] ?? '') !== '')
                        @include('print.partials.band-total', [
                            'label' => $line['group'] ?? '',
                            'amount' => $line['band_total'],
                            'span' => count($cols),
                        ])
                    @endif
                @endforeach
            </tbody>
        </table>
    @endif

    @if ($doc->showMoney && $doc->totals !== [] && $profile->shows('totals'))
        <table class="totals">
            @foreach ($doc->totals as $label => $value)
                <tr @if ($loop->last) class="grand" @endif>
                    <td>{{ __($label) }}</td>
                    <td class="num" style="width: {{ $thermal ? '15mm' : '36mm' }}">{{ $paper->money($value) }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    @if ($doc->showMoney && $doc->amountInWords && $profile->shows('words'))
        <div class="words">
            <strong>{{ __('core.print.in_words') }}:</strong> {{ $doc->amountInWords }}
        </div>
    @endif

    {{-- ⭐ আদায়ের ছক — কাগজের বাঁ-নিচে, টাকার সারিগুলোর পরে।

         ⓘ ছকটা আঁকে [[print/partials/payments]], আর সারিগুলো তোলে
         [[SalesPrintController::paymentsAgainst()]]। ⚠️ খালি হলে
         partial-টা নিজেই কিছু আঁকে না, তাই চালান ও অর্ডারের কাগজ
         অপরিবর্তিত। --}}
    @if ($profile->shows('paid_table'))
        @include('print.partials.payments', ['payments' => $doc->payments])
    @endif

    @if ($doc->narration && $profile->shows('narration'))
        <div class="words">
            <strong>{{ __('core.table.narration') }}:</strong> {{ $doc->narration }}
        </div>
    @endif

    @if ($doc->signatures !== [] && $profile->shows('signatures'))
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
