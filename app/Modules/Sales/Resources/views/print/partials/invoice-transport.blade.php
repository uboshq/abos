{{--
    পরিবহনের লেখা — সুইচ বন্ধ হলে কিছুই না। চাই: $v, $facts; ঐচ্ছিক $cap।
--}}
@if ($v->shows('transport'))
    <div data-transport>
        <div class="{{ $cap ?? 'cap' }}">{{ mb_strtoupper($v->label('transport')) }}</div>
        <div class="party">{{ filled($facts['transport']['carrier']) ? $facts['transport']['carrier'] : '—' }}</div>
        @if (filled($facts['transport']['vehicle']))<div>{{ $v->en('vehicle') }} {{ $facts['transport']['vehicle'] }}</div>@endif
        @if (filled($facts['transport']['driver_phone']))<div>{{ $v->en('driver_phone') }} {{ $facts['transport']['driver_phone'] }}</div>@endif
        @if (filled($facts['transport']['delivery_date']))<div>{{ $v->en('delivery_date') }} {{ $facts['transport']['delivery_date'] }}</div>@endif
    </div>
@endif
