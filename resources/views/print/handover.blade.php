@extends('print.layout')

{{--
    টাকা হস্তান্তরের স্লিপ — দুই সইয়ের কাগজ।

    ── কেন এই কাগজটা লাগে ──────────────────────────────────────────────
    হাতে হাতে টাকা যায় দিনে কয়েকবার: কাউন্টার থেকে সিন্দুকে, সিন্দুক
    থেকে ব্যাংকে, ডেলিভারির লোক থেকে ক্যাশিয়ারে। ঝগড়াটা কখনো "টাকা
    দিয়েছি কি না" নিয়ে হয় না — হয় **কত** আর **কখন** নিয়ে, আর তখন
    দুইজনের কারও হাতে কিছু থাকে না।

    পর্দায় রেকর্ড থাকা যথেষ্ট নয়: যিনি টাকা নিচ্ছেন তাঁর হাতে পর্দা
    থাকে না, আর যিনি দিচ্ছেন তিনি পরে পর্দা বদলে ফেলতে পারেন বলে সন্দেহ
    থেকেই যায়। কাগজে দুইজনের সই — ওটাই একমাত্র জিনিস যা দুইজনের কাছেই
    সমান।

    ── দুইটা কপি, ইচ্ছাকৃতভাবে ─────────────────────────────────────────
    A4-তে একই স্লিপ দুইবার ছাপা হয়, মাঝে কাটার দাগ। একটা দাতার, একটা
    গ্রহীতার। এক কপি দিলে যাঁর কাছে থাকল না তিনিই পরে প্রমাণহীন।

    তাপীয় কাগজে একটাই — ৫৮মিমি রোল কেটে দুই টুকরো করা যায় না, আর
    ওখানে স্লিপটা সাধারণত সাথে সাথেই হাতে যায়।
--}}

@section('body')
    @php
        $thermal = $paper->isThermal;

        // A4-তে দুইবার, রোলে একবার — কারণ উপরের মন্তব্যে
        $copies = $thermal
            ? [__('accounts::print.handover_copy_single')]
            : [__('accounts::print.handover_copy_giver'), __('accounts::print.handover_copy_receiver')];
    @endphp

    @foreach ($copies as $copyIndex => $copyLabel)
        @if ($copyIndex > 0)
            {{-- কাটার দাগ — কাঁচি কোথায় চালাতে হবে সেটা কাগজেই লেখা --}}
            <div style="border-top: 0.3mm dashed #000; margin: 8mm 0 6mm;
                        text-align: center; font-size: 8pt; color: #333;">
                <span style="background: #fff; padding: 0 2mm; position: relative; top: -3mm;">
                    {{ __('accounts::print.handover_cut_here') }}
                </span>
            </div>
        @endif

        <div style="text-align: center; font-weight: bold; font-size: {{ $thermal ? 10 : 12 }}pt;
                    margin-bottom: 2mm;">
            {{ __('accounts::print.handover_title') }}
            <div style="font-weight: normal; font-size: {{ $thermal ? 7 : 8 }}pt; color: #333;">
                {{ $copyLabel }}
            </div>
        </div>

        @if (! empty($notice))
            {{-- বাতিল করা স্লিপ যেন বৈধ কাগজ হিসেবে না চলে --}}
            <div style="text-align: center; font-weight: bold; border: 0.4mm solid #000;
                        padding: {{ $thermal ? '1mm' : '2mm' }}; margin-bottom: {{ $thermal ? 2 : 4 }}mm;
                        font-size: {{ $thermal ? 8 : 11 }}pt;">
                {{ $notice }}
            </div>
        @endif

        <table class="meta">
            <tr>
                <td class="label" style="width: 24mm">{{ __('core.print.document_no') }}</td>
                <td>{{ $handover['document_no'] }}</td>
                @unless ($thermal)
                    <td class="label" style="width: 20mm">{{ __('core.print.date') }}</td>
                    <td>{{ $handover['date'] }}</td>
                @endunless
            </tr>

            @if ($thermal)
                <tr>
                    <td class="label">{{ __('core.print.date') }}</td>
                    <td>{{ $handover['date'] }}</td>
                </tr>
            @endif

            <tr>
                <td class="label">{{ __('accounts::print.handover_from') }}</td>
                <td @unless($thermal) colspan="3" @endunless>{{ $handover['from'] }}</td>
            </tr>

            <tr>
                <td class="label">{{ __('accounts::print.handover_to') }}</td>
                <td @unless($thermal) colspan="3" @endunless>{{ $handover['to'] }}</td>
            </tr>

            @if (! empty($handover['branch']))
                <tr>
                    <td class="label">{{ __('core.company.branch') }}</td>
                    <td @unless($thermal) colspan="3" @endunless>{{ $handover['branch'] }}</td>
                </tr>
            @endif

            @if (! empty($handover['narration']))
                <tr>
                    <td class="label">{{ __('core.table.narration') }}</td>
                    <td @unless($thermal) colspan="3" @endunless>{{ $handover['narration'] }}</td>
                </tr>
            @endif
        </table>

        {{-- অঙ্কটা বড় করে, একা।

             স্লিপের একটাই কাজ — কত টাকা হাত বদলাল সেটা বিতর্কের বাইরে
             রাখা। বাকি সব সারির মাঝে ছোট করে লিখলে ওটা খুঁজে বের করতে
             হয়, আর তাড়াহুড়োয় কেউ পড়েই না। --}}
        <div style="border: 0.3mm solid #000; padding: {{ $thermal ? '2mm' : '3mm' }};
                    text-align: center; margin: {{ $thermal ? 2 : 4 }}mm 0;">
            <div style="font-size: {{ $thermal ? 7 : 9 }}pt; color: #333;">
                {{ __('accounts::print.handover_amount') }}
            </div>
            <div class="num" style="font-size: {{ $thermal ? 13 : 18 }}pt; font-weight: bold;">
                {{ $handover['amount'] }}
            </div>
            <div style="font-size: {{ $thermal ? 7 : 8 }}pt;">
                {{ $handover['amount_in_words'] }}
            </div>
        </div>

        {{-- দুইটা সইয়ের ঘর, পাশাপাশি।

             নাম ছাপা থাকে সইয়ের রেখার নিচে: পরে কাগজটা দেখে বোঝা যায়
             কার সই থাকার কথা ছিল, আর কেউ অন্যের হয়ে সই করলে সেটা
             মিলিয়ে দেখা যায়। শুধু "দাতা / গ্রহীতা" লিখলে ছয় মাস পরে
             কাগজটা কিছুই বলত না। --}}
        <table class="signatures" style="margin-top: {{ $thermal ? 4 : 8 }}mm">
            <tr>
                @foreach ([
                    [__('accounts::print.handover_given_by'), $handover['given_by']],
                    [__('accounts::print.handover_received_by'), $handover['received_by']],
                ] as [$role, $person])
                    <td style="width: 50%">
                        <div class="sig-line">{{ $role }}</div>
                        @if ($person !== '')
                            <div style="font-size: {{ $thermal ? 7 : 8 }}pt; color: #333;">
                                {{ $person }}
                            </div>
                        @endif
                    </td>
                @endforeach
            </tr>
        </table>
    @endforeach
@endsection
