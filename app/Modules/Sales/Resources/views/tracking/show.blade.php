{{--
    একটা বিক্রির সময়রেখা — কে কখন কী করলেন, পুরনো আগে ([[SaleTracking::story()]])।
    ⓘ তালিকা থেকে পপ-আপে খোলে; সরাসরি খুললে পুরো পাতা।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('sales::tracking.title') }} — {{ $sale['no'] }}</x-slot:title>

    <div class="mx-auto grid max-w-3xl gap-3" data-tracking-story>
        <div>
            <h1 class="text-lg font-semibold">{{ $sale['no'] }}</h1>
            <div class="text-sm">{{ $sale['customer'] }}</div>
            <div class="text-sm text-(--color-ink-muted)">{{ $sale['date'] }}</div>
            <div class="num text-sm">{{ \App\Core\Support\Money::format($sale['total']) }}</div>
            <div class="mt-1 text-sm font-medium">
                {{ __('sales::tracking.step.'.$sale['step']) }}
                @if ($sale['billed'])
                    · {{ __('sales::tracking.step.billed') }}
                @endif
            </div>
        </div>

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
