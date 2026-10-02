{{--
    দোকানির নিজের একটা বিক্রির দাগ — টিকচিহ্ন আর রং, কর্মীর পাতার হুবহু ([[SaleTracking::milestones()]])।
    ⓘ কার নাম দেখানো হয় না — দোকানির কাছে কোন কর্মী কী করলেন সেটা ভেতরের কথা; কেবল ধাপ আর সময়।
--}}
@php $trackingColours = \App\Modules\Sales\Services\SaleTracking::COLOURS; @endphp
<x-sales::portal.layout :customer="$customer">
    <div data-live x-data="liveRefresh({ url: '{{ route('sales.portal.tracking.show', [$sale['kind'], $sale['id']]) }}', seconds: 30 })">
        <h1 class="text-lg font-semibold">{{ $sale['no'] }}</h1>
        <div class="num text-sm">{{ \App\Core\Support\Money::format($sale['total']) }}</div>
        <div class="mb-3 text-sm font-semibold" style="color: {{ $trackingColours[$sale['category']] ?? '#111827' }}">
            {{ __('sales::tracking.step.'.$sale['step']) }}
        </div>

        <ol data-tracking-milestones>
            @foreach ($sale['milestones'] ?? [] as $m)
                @php $c = $trackingColours[$m['category']] ?? '#9CA3AF'; @endphp
                <li class="flex items-start gap-3 pb-2" data-milestone="{{ $m['key'] }}" data-state="{{ $m['state'] }}">
                    <span style="width:20px;height:20px;border-radius:999px;display:flex;align-items:center;justify-content:center;
                                 font-size:11px;font-weight:700;color:#fff;flex:none;
                                 background: {{ in_array($m['state'], ['done', 'rejected', 'hold'], true) ? $c : '#fff' }};
                                 border: 2px solid {{ $m['state'] === 'todo' ? '#D1D5DB' : $c }}">
                        @switch($m['state'])
                            @case('done') ✓ @break
                            @case('rejected') ✕ @break
                            @case('hold') ■ @break
                            @default &nbsp;
                        @endswitch
                    </span>
                    <div>
                        <div class="text-sm {{ $m['state'] === 'todo' ? 'text-(--color-ink-muted)' : 'font-semibold' }}">{{ $m['label'] }}</div>
                        @if ($m['at'])
                            <div class="text-xs text-(--color-ink-muted)">
                                {{ \Illuminate\Support\Carbon::parse($m['at'])->timezone(config('app.timezone'))->format('d/m/Y h:i A') }}
                            </div>
                        @endif
                    </div>
                </li>
            @endforeach
        </ol>
    </div>

    <a href="{{ route('sales.portal.tracking') }}" class="mt-4 block text-center text-sm text-(--color-brand-500) hover:underline">
        {{ __('sales::tracking.title') }}
    </a>
</x-sales::portal.layout>
