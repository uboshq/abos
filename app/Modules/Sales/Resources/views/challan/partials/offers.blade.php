{{--
    ⭐ চালানের অফার — কী খাটে, কী প্রায় খাটে, কী বসানো (অডিট §১১, ২৯ সেপ্টেম্বর ২০২৬)।

    ⓘ `$offerPanel` দেয় [[DeliveryChallanController::offerPanel()]]; `null` হলে এই
    ফাইল ডাকাই হয় না। ⚠️ বোতাম কেবল অফিসের খসড়ায় (`editable`) — কাউন্টারের চালানে
    প্যানেলটা কেবল পড়ার, আর বলে যে কাউন্টারে বসানো শিগগির আসছে।

    ⛔ সিস্টেম নিজে কিছু বসায় না (স্পেক §১০): "বসান" না চাপলে সারিতে কিছুই বদলায় না।
    ⚠️ কম্পোনেন্টের গুণে ডাবল-কোট নেই — ঐ ভুলে এই রিপোতে ট্যাগ লেখা হয়ে ছাপা হয়েছিল।
--}}
<section data-boxed data-offers class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
    <h2 class="mb-1 font-semibold">{{ __('sales::offers.title') }}</h2>

    @if ($offerPanel['counter'])
        <p class="mb-3 text-xs text-(--color-ink-muted)">{{ __('sales::offers.counter_soon') }}</p>
    @elseif (! $offerPanel['editable'])
        <p class="mb-3 text-xs text-(--color-ink-muted)">{{ __('sales::offers.read_only') }}</p>
    @else
        <p class="mb-3 text-xs text-(--color-ink-muted)">{{ __('sales::offers.hint') }}</p>
    @endif

    @foreach ($offerPanel['rows'] as $row)
        <div class="border-b border-(--color-border) py-2 text-sm">
            <div class="font-medium">{{ $row['line']->product?->name() ?? '#'.$row['line']->product_id }}</div>

            @foreach ($row['applied'] as $done)
                <div class="flex items-center justify-between py-1" data-offer-applied>
                    <span>
                        {{ $done['code'] }} · {{ $done['name'] }}
                        <span class="text-(--color-ink-muted)">· {{ __('sales::offers.discount') }}</span>
                        <span class="tabular-nums">{{ \App\Core\Support\Money::format($done['worth']) }}</span>
                    </span>

                    @if ($offerPanel['editable'])
                        <form method="POST" action="{{ route('sales.challan.offer.destroy', [$challan, $row['line']->id, $done['offer_id']]) }}">
                            @csrf
                            @method('DELETE')
                            <x-ui.button type="submit" tone="danger">{{ __('sales::offers.remove') }}</x-ui.button>
                        </form>
                    @endif
                </div>
            @endforeach

            @foreach ($row['eligible'] as $offer)
                @continue(collect($row['applied'])->contains('offer_id', $offer['id']))

                <div class="flex items-center justify-between py-1" data-offer-eligible>
                    <span>
                        {{ $offer['code'] }} · {{ $offer['name'] }}
                        <span class="text-(--color-ink-muted)">· {{ $offer['kind_label'] }}</span>
                        <span class="tabular-nums">{{ \App\Core\Support\Money::format($offer['worth']) }}</span>
                    </span>

                    @if ($offerPanel['editable'] && $offer['billable'])
                        <form method="POST" action="{{ route('sales.challan.offer.store', $challan) }}">
                            @csrf
                            <input type="hidden" name="line_id" value="{{ $row['line']->id }}">
                            <input type="hidden" name="offer_id" value="{{ $offer['id'] }}">
                            <x-ui.button type="submit" tone="secondary">{{ __('sales::offers.apply') }}</x-ui.button>
                        </form>
                    @elseif (! $offer['billable'])
                        <span class="text-xs text-(--color-ink-muted)">{{ __('sales::offers.not_here') }}</span>
                    @endif
                </div>
            @endforeach

            @foreach ($row['almost'] as $near)
                <div class="py-1 text-xs text-(--color-ink-muted)" data-offer-almost>
                    {{ $near['code'] }} · {{ $near['name'] }} — {{ __('sales::offers.short_by', ['qty' => rtrim(rtrim($near['short_by'], '0'), '.')]) }}
                </div>
            @endforeach

            @if ($row['applied'] === [] && $row['eligible'] === [] && $row['almost'] === [])
                <div class="py-1 text-xs text-(--color-ink-muted)">{{ __('sales::offers.none') }}</div>
            @endif
        </div>
    @endforeach
</section>
