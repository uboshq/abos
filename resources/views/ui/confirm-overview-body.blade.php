{{--
    ⭐ সারাংশের ভিতরের অংশ — কেন্দ্রীয় ইঞ্জিনের আকার ([[ConfirmOverview::toArray()]]), এক লাইনে এক জিনিস (মালিকের নিয়ম)।
    ⓘ `$overview` — toArray()। `data-overview-blocks` সত্য হলে পপ-আপের "নিশ্চিত" বন্ধ, খসড়া খোলা ([[confirm-overview.js]])।
--}}
<div data-overview-blocks="{{ $overview['blocks'] ? '1' : '0' }}" class="grid gap-2">
    <h2 class="text-base font-semibold">{{ $overview['title'] }}</h2>

    @foreach ($overview['head'] as $row)
        <div>{{ $row['label'] }}: <strong>{{ $row['value'] }}</strong></div>
    @endforeach

    <hr class="border-(--color-border)">

    @foreach ($overview['lines'] as $line)
        <div class="grid gap-0.5" data-overview-line>
            <div class="font-semibold">{{ $line['title'] }}</div>
            @foreach ($line['details'] as $detail)
                <div>{{ $detail }}</div>
            @endforeach
            @if ($line['amount'] !== null)
                <div class="num">৳ {{ $line['amount'] }}</div>
            @endif
        </div>
    @endforeach

    <hr class="border-(--color-border)">

    @foreach ($overview['totals'] as $total)
        <div @class(['font-semibold text-base' => $total['strong']])>{{ $total['label'] }}: <span class="num">৳ {{ $total['amount'] }}</span></div>
    @endforeach

    @if ($overview['money'] !== [])
        <hr class="border-(--color-border)">
        @foreach ($overview['money'] as $money)
            <div @class([
                'text-(--color-danger) font-semibold' => $money['tone'] === 'bad',
                'text-(--color-success)' => $money['tone'] === 'good',
            ])>{{ $money['label'] }}: <span class="num">৳ {{ $money['amount'] }}</span></div>
        @endforeach
    @endif

    @foreach ($overview['notes'] as $note)
        <div data-overview-note="{{ $note['tone'] }}" @class([
            'rounded-(--radius-field) px-3 py-2',
            'bg-(--color-badge-danger-bg) text-(--color-badge-danger-ink) font-semibold' => $note['tone'] === 'stop',
            'bg-(--color-badge-warning-bg) text-(--color-badge-warning-ink)' => $note['tone'] === 'warn',
            'bg-(--color-badge-info-bg) text-(--color-badge-info-ink)' => $note['tone'] === 'info',
        ])>{{ $note['text'] }}</div>
    @endforeach
</div>
