{{--
    বিলের নিজের ঘরগুলো ([[InvoicePaperView::billFacts()]]) — $layout 'cells' হলে এক সারির ঘরে
    (`table.meta`, `.cap`), 'rows' হলে একটার নিচে একটা লাইন। চাই: $v।
--}}
@php
    $layout = $layout ?? 'rows';
    $list = $v->billFacts();
    $width = round(100 / max(1, count($list)), 2);
@endphp
@if ($layout === 'cells')
    <table class="meta"><tr>
        @foreach ($list as [$label, $value, $marker])
            <td style="width: {{ $width }}%" @if ($marker) {{ $marker }} @endif><div class="cap">{{ mb_strtoupper($label) }}</div><div>{{ $value }}</div></td>
        @endforeach
    </tr></table>
@else
    @foreach ($list as [$label, $value, $marker])
        <div @if ($marker) {{ $marker }} @endif>{{ $label }}: {{ $value }}</div>
    @endforeach
@endif
