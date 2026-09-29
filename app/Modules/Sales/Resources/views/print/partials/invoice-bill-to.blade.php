{{--
    "Bill To"-এর লেখা — কেবল ভিতরের সারিগুলো; ঘর আর সাজ নকশার। চাই: $v, $facts; ঐচ্ছিক $cap (শিরোনামের class)।
--}}
<div class="{{ $cap ?? 'cap' }}">{{ mb_strtoupper($v->label('bill_to')) }}</div>
<div class="party">{{ $v->en('ms') }} {{ $facts['bill_to']['name'] }}
    @if (filled($facts['bill_to']['code'] ?? ''))<span class="sub">· {{ $facts['bill_to']['code'] }}</span>@endif
</div>
@if (filled($facts['bill_to']['point']))<div>{{ $v->en('point') }} {{ $facts['bill_to']['point'] }}</div>@endif
@if (filled($facts['bill_to']['address']))<div>{{ $facts['bill_to']['address'] }}</div>@endif
@if (filled($facts['bill_to']['phone']))<div>{{ $v->en('phone') }} {{ $facts['bill_to']['phone'] }}</div>@endif
