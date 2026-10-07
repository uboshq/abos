@extends('print.layout')

{{--
    ⭐ রিপোর্টের PDF — খতিয়ান আর রিপোর্ট, তারিখ ধরে, ফোন থেকে দেখা · ছাপা · পাঠানো (মালিক, ৪ অক্টোবর ২০২৬:
    *"all ledger & report date veue print share korazay pdf e"*)।

    ⓘ সংখ্যা নিজে কষে না: সারি আর সর্বমোট রপ্তানির একই ধরা টেবিল থেকে ([[ReportExport::into()]] → [[ListExport]]),
    তাই csv, xlsx আর PDF-এ হুবহু একই অঙ্ক — আর চাবিহীন কলাম ইঞ্জিন আগেই বাদ দিয়েছে।
    ⓘ শব্দ বাংলায় থাকে, সংখ্যা ইংরেজি অঙ্কে — কাগজের বাকি সবকিছুর মতো।
--}}
@section('body')
    @if (! empty($range))
        <div class="meta" style="text-align: center;">{{ $range }}</div>
    @endif

    <table class="lines">
        <thead>
            <tr>
                @foreach ($columns as $column)
                    <th @class(['num' => $column['numeric']])>{{ $column['label'] }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $i => $row)
                <tr @class(['alt' => $i % 2 === 1])>
                    @foreach ($row as $c => $cell)
                        <td @class(['num' => $columns[$c]['numeric'] ?? false])>{{ $cell }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ count($columns) }}" style="text-align: center;">{{ __('core.empty.nothing_here') }}</td></tr>
            @endforelse

            @if (! empty($footer))
                <tr>
                    @foreach ($footer as $c => $cell)
                        <td @class(['num' => $columns[$c]['numeric'] ?? false]) style="font-weight: bold; border-top: 0.4mm solid #000;">{{ $cell }}</td>
                    @endforeach
                </tr>
            @endif
        </tbody>
    </table>
@endsection
