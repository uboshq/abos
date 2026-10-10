{{--
    একটা বিক্রির অগ্রগতি — টিকচিহ্নের দাগ (মালিকের আদেশ, ২ অক্টোবর ২০২৬), নিচে সময়রেখা ([[SaleTracking::story()]])।
    ⓘ তালিকা থেকে পপ-আপে খোলে; সরাসরি খুললে পুরো পাতা। রং — [[SaleTracking::COLOURS]]।
--}}
@php $trackingColours = \App\Modules\Sales\Services\SaleTracking::COLOURS; @endphp
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('sales::tracking.title') }} — {{ $sale['no'] }}</x-slot:title>

    {{-- ⛔ কেন হলো না, এই পাতাতেই — বোতাম আটকালে বার্তাটা এখানে দেখায় (ARefusalNobodyEverSawTest, ১০ অক্টোবর ২০২৬) --}}
    <x-ui.errors />

    {{-- ⭐ প্রতি ৩০ সেকেন্ডে নিজে নতুন — পপ-আপের ভেতরেও, তাই ঠিকানাটা হাতে ([[screens.js::liveRefresh]]) --}}
    <div class="mx-auto grid max-w-3xl gap-3" data-tracking-story data-live
         x-data="liveRefresh({ url: '{{ route('sales.tracking.show', [$sale['kind'], $sale['id']]) }}', seconds: 30 })">
        <div>
            <h1 class="text-lg font-semibold">{{ $sale['no'] }}</h1>
            <div class="text-sm">{{ $sale['customer'] }}</div>
            <div class="text-sm text-(--color-ink-muted)">{{ $sale['date'] }}</div>
            <div class="num text-sm">{{ \App\Core\Support\Money::format($sale['total']) }}</div>
            <div class="mt-1 text-sm font-semibold" style="color: {{ $trackingColours[$sale['category']] ?? '#111827' }}">
                {{ __('sales::tracking.step.'.$sale['step']) }}
                @if ($sale['billed'])
                    · {{ __('sales::tracking.step.billed') }}
                @endif
            </div>
        </div>

        {{-- ⭐ অগ্রগতির দাগ — হয়েছে টিক, এখন গোল-দাগ, ফেরত ✕, থামানো ■, সামনে ফাঁপা --}}
        <ol class="grid gap-0" data-tracking-milestones>
            @foreach ($sale['milestones'] ?? [] as $m)
                @php
                    $c = $trackingColours[$m['category']] ?? '#9CA3AF';
                    $on = in_array($m['state'], ['done', 'current', 'rejected', 'hold'], true);
                @endphp
                <li class="flex gap-3" data-milestone="{{ $m['key'] }}" data-state="{{ $m['state'] }}">
                    <div class="flex flex-col items-center">
                        <span style="width:22px;height:22px;border-radius:999px;display:flex;align-items:center;justify-content:center;
                                     font-size:12px;font-weight:700;color:#fff;
                                     background: {{ $m['state'] === 'done' || $m['state'] === 'rejected' || $m['state'] === 'hold' ? $c : '#fff' }};
                                     border: 2px solid {{ $on ? $c : '#D1D5DB' }}">
                            @switch($m['state'])
                                @case('done') ✓ @break
                                @case('rejected') ✕ @break
                                @case('hold') ■ @break
                                @default &nbsp;
                            @endswitch
                        </span>
                        @unless ($loop->last)
                            <span style="width:2px;flex:1;min-height:18px;background: {{ $m['state'] === 'done' ? $c : '#E5E7EB' }}"></span>
                        @endunless
                    </div>
                    <div class="pb-3">
                        <div class="text-sm {{ $m['state'] === 'todo' ? 'text-(--color-ink-muted)' : 'font-semibold' }}"
                             @if ($m['state'] === 'current') style="color: {{ $c }}" @endif>
                            {{ $m['label'] }}
                        </div>
                        @if ($m['at'] || $m['by'])
                            <div class="text-xs text-(--color-ink-muted)">
                                {{ $m['at'] ? \Illuminate\Support\Carbon::parse($m['at'])->timezone(config('app.timezone'))->format('d/m/Y h:i A') : '' }}
                                @if ($m['by'])
                                    · {{ $m['by'] }}
                                @endif
                            </div>
                        @endif
                    </div>
                </li>
            @endforeach
        </ol>

        {{-- ⭐ চালানের সময়রেখা — সাত ধাপ, গাড়ি আর চালকসহ (৪ অক্টোবর ২০২৬) --}}
        @if (! empty($sale['timeline']))
            @include('sales::tracking.partials.timeline', ['timeline' => $sale['timeline']])
        @endif

        <h2 class="text-sm font-semibold">{{ __('sales::tracking.history') }}</h2>
        <ol class="grid gap-2 border-s-2 border-(--color-border) ps-4">
            @foreach ($sale['events'] as $event)
                <li data-tracking-event>
                    <div class="text-sm font-medium">{{ $event['text'] }}</div>
                    <div class="text-xs text-(--color-ink-muted)">
                        {{ $event['at'] ? \Illuminate\Support\Carbon::parse($event['at'])->timezone(config('app.timezone'))->format('d/m/Y h:i A') : '—' }}
                        @if ($event['by'])
                            · {{ $event['by'] }}
                        @endif
                    </div>
                </li>
            @endforeach
        </ol>
    </div>
</x-layouts.app>
