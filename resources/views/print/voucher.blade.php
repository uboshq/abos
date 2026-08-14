@extends('print.layout')

{{--
    ভাউচার — আদায়, পরিশোধ, খরচ, জাবেদা, কন্ট্রা।

    পাঁচটার জন্য একটাই টেমপ্লেট: কাঠামো এক, শুধু শিরোনাম আলাদা। আলাদা করে
    পাঁচটা লিখলে একটায় স্বাক্ষরের ঘর যোগ করে বাকি চারটায় ভুলে যাওয়া
    নিশ্চিত।

    থার্মালে বিবরণ কলাম বাদ যায় — ৫৮mm-এ হিসাব, ডেবিট ও ক্রেডিট তিনটাই
    কষ্টে ধরে, চারটা দিলে সংখ্যা কেটে যায়।
--}}

@section('body')
    @php
        $thermal = $paper->isThermal;
        $showNarration = $paper->maxColumns() >= 4;
    @endphp

    @if (! empty($notice))
        {{--
            বাতিল করা ভাউচারের গায়ে "বাতিল"।

            ── কী ভেঙেছিল ───────────────────────────────────────────────
            কন্ট্রোলার এই লেখাটা পাঠাত, আর এই টেমপ্লেটে সেটা ধরার কোনো
            ঘর ছিল না — তাই বাতিল করা ভাউচার ছাপলে **হুবহু বৈধ একটা
            কাগজ** বেরোত। কেউ সেটা টাকা পাওয়ার প্রমাণ হিসেবে দেখাতে
            পারতেন, আর কাগজ দেখে ধরার কোনো উপায় ছিল না।

            বিক্রয়-ক্রয়ের কাগজে ব্লকটা `print.document-body`-তে আগে
            থেকেই ছিল; ভাউচার আলাদা টেমপ্লেট বলে সে বাদ পড়েছিল। HP-র
            পরীক্ষক ১৪ আগস্ট ধরেন।
        --}}
        <div style="text-align: center; font-weight: bold; border: 0.4mm solid #000;
                    padding: {{ $thermal ? '1mm' : '2mm' }}; margin-bottom: {{ $thermal ? 2 : 4 }}mm;
                    font-size: {{ $thermal ? 8 : 11 }}pt;">
            {{ $notice }}
        </div>
    @endif

    <table class="meta">
        <tr>
            <td class="label" style="width: 22mm">{{ __('core.print.document_no') }}</td>
            <td>{{ $voucher['document_no'] }}</td>
            @unless ($thermal)
                <td class="label" style="width: 22mm">{{ __('core.print.date') }}</td>
                <td>{{ $voucher['date'] }}</td>
            @endunless
        </tr>

        @if ($thermal)
            <tr>
                <td class="label">{{ __('core.print.date') }}</td>
                <td>{{ $voucher['date'] }}</td>
            </tr>
        @endif

        @if (! empty($voucher['party']))
            <tr>
                <td class="label">{{ __('core.print.party') }}</td>
                <td @unless($thermal) colspan="3" @endunless>{{ $voucher['party'] }}</td>
            </tr>
        @endif

        @if (! empty($voucher['branch']))
            <tr>
                <td class="label">{{ __('core.company.branch') }}</td>
                <td @unless($thermal) colspan="3" @endunless>{{ $voucher['branch'] }}</td>
            </tr>
        @endif
    </table>

    <table class="lines">
        <thead>
            <tr>
                <th>{{ __('core.print.account') }}</th>
                @if ($showNarration)
                    <th>{{ __('core.table.narration') }}</th>
                @endif
                <th class="num" style="width: {{ $thermal ? '16mm' : '28mm' }}">{{ __('core.table.debit') }}</th>
                <th class="num" style="width: {{ $thermal ? '16mm' : '28mm' }}">{{ __('core.table.credit') }}</th>
            </tr>
        </thead>

        <tbody>
            @foreach ($voucher['lines'] as $line)
                <tr>
                    <td>
                        {{ $line['account'] }}

                        {{-- থার্মালে আলাদা কলাম নেই, তাই বিবরণ হিসাবের নিচে
                             ছোট করে — তথ্যটা হারায় না, শুধু জায়গা বদলায়। --}}
                        @if (! $showNarration && ! empty($line['narration']))
                            <div style="font-size: {{ $paper->fontSize - 1 }}pt; color: #333">
                                {{ $line['narration'] }}
                            </div>
                        @endif
                    </td>

                    @if ($showNarration)
                        <td>{{ $line['narration'] ?? '' }}</td>
                    @endif

                    <td class="num">{{ $line['debit'] ?: '' }}</td>
                    <td class="num">{{ $line['credit'] ?: '' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr class="grand">
            <td>{{ __('core.print.total') }}</td>
            <td class="num" style="width: {{ $thermal ? '16mm' : '28mm' }}">{{ $voucher['total_debit'] }}</td>
            <td class="num" style="width: {{ $thermal ? '16mm' : '28mm' }}">{{ $voucher['total_credit'] }}</td>
        </tr>
    </table>

    @if (! empty($voucher['amount_in_words']))
        <div class="words">
            <strong>{{ __('core.print.in_words') }}:</strong> {{ $voucher['amount_in_words'] }}
        </div>
    @endif

    @if (! empty($voucher['narration']))
        <div class="words">
            <strong>{{ __('core.table.narration') }}:</strong> {{ $voucher['narration'] }}
        </div>
    @endif

    @if ($settings->get('accounts.print_signature_lines', true))
        {{--
            সইয়ের ঘরগুলো কন্ট্রোলারের ঠিক করা, এখানকার নয়।

            ── আগে কী ছিল, আর কেন সেটা ভুল ─────────────────────────────
            এখানে তিনটা নাম হাতে লেখা ছিল — prepared / approved /
            received — আর কন্ট্রোলারের `signatures()` ধরনভেদে যে তালিকা
            বানাত সেটা কোনোদিন কাগজে পৌঁছাত না।

            ফল: **জাবেদার কাগজেও "গ্রহণ করলেন" ছাপা হত**, অথচ জাবেদায়
            কেউ কিছু গ্রহণ করে না — ওটা দুই খাতের মধ্যে একটা সমন্বয়।
            আর তাপীয় রসিদে সবসময় "গ্রহণ করলেন" বসত, এমনকি আদায়ের
            রসিদেও — যেখানে গ্রাহক টাকা **দিয়েছেন**, নেননি।

            একটা সই-ঘর ভুল নামে থাকা খালি থাকার চেয়ে খারাপ: কাগজটা
            পরে প্রমাণ হিসেবে দাঁড়ায়, আর তাতে লেখা থাকে কে কী করেছে।

            ── তাপীয় কাগজে একটাই ─────────────────────────────────────
            ৫৮মিমি-তে তিনটা ঘর পাশাপাশি বসালে প্রতিটার চওড়া এক
            ইঞ্চিরও কম — সই করা যায় না। তাই প্রথমটা, আর প্রথমটাই
            সবসময় অপর পক্ষ (দিলেন/পেলেন): হাতে-হাতে লেনদেনে ওই সইটাই
            আসল, বাকি দুইটা অফিসের ভেতরের।
        --}}
        @php($roles = $thermal ? array_slice($signatures, 0, 1) : $signatures)

        <table class="signatures">
            <tr>
                @foreach ($roles as $role)
                    <td style="width: {{ (int) round(100 / max(count($roles), 1)) }}%">
                        <div class="sig-line">{{ $role }}</div>
                    </td>
                @endforeach
            </tr>
        </table>
    @endif
@endsection
