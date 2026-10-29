{{-- ⓘ কোন কাগজ থেকে — নম্বরটা সারিতেই রাখা (`source_no`), কাগজ খোলা গেলে লিংক ([[DrillResolver::describe()]]) --}}
@if ($d['resolved'] && $d['route'] !== null)
    <a href="{{ route($d['route'][0], $d['route'][1]) }}" class="text-(--color-brand-600) hover:underline" data-obligation-source>{{ $o->source_no ?? $d['label'] }}</a>
@else
    <span data-obligation-source>{{ $o->source_no ?? $d['label'] }}</span>
@endif
