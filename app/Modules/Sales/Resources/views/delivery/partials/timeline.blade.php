{{--
    ডেলিভারির ধাপ — একটা চালানের সময়রেখা, আর হাতে ধাপ বসানোর ঘর।

    ⓘ দুই পাতায় বসে: চালানের পাতা আর ডেলিভারির পাতা। নিজের তথ্য নিজে
    টানে (@inject), যাতে চালানের কন্ট্রোলারে হাত দিতে না হয় — আর যে
    পাতাই এটা ডাকুক, সময়রেখা একই থাকে।

    চাই: $challan। দেখায় কেবল `sales.delivery.view` থাকলে; ধাপ বসানোর
    ঘর কেবল `sales.delivery.update` থাকলে।
--}}
@inject('deliveryStages', 'App\Modules\Sales\Services\DeliveryStageService')

@can('sales.delivery.view')
    @php
        $deliveryState = $deliveryStages->ensure($challan);
        $deliveryEvents = $deliveryStages->timeline($challan);
    @endphp

    <section data-boxed id="delivery-stages"
             class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <h2 class="flex flex-wrap items-center justify-between gap-2 border-b border-(--color-border)
                   bg-(--color-section-head) px-4 py-3 font-semibold">
            <span>{{ __('sales::delivery.timeline.title') }}</span>
            <span class="flex items-center gap-2 text-sm font-normal">
                <span class="text-(--color-ink-muted)">{{ __('sales::delivery.now') }}</span>
                @include('sales::delivery.partials.badge', ['stage' => $deliveryState->stage])
            </span>
        </h2>

        @if ($deliveryEvents->isEmpty())
            <p class="px-4 py-6 text-sm text-(--color-ink-muted)">{{ __('sales::delivery.timeline.empty') }}</p>
        @else
            <ol class="space-y-0 px-4 py-3">
                @foreach ($deliveryEvents as $event)
                    <li class="flex gap-3 border-t border-(--color-border) py-2 first:border-0 first:pt-0">
                        <div class="w-40 shrink-0">
                            @include('sales::delivery.partials.badge', ['stage' => $event->to_stage])
                        </div>

                        <div class="min-w-0 flex-1 text-sm">
                            <p class="text-2xs text-(--color-ink-muted)">
                                {{ \App\Core\Support\DateFormat::formatWithTime($event->occurred_at) }}
                                · {{ __('sales::delivery.source.'.$event->source) }}
                                @if ($event->creator)
                                    · {{ __('sales::delivery.timeline.by') }}: {{ $event->creator->name }}
                                @endif
                                @if ($event->shipment)
                                    · {{ __('sales::delivery.timeline.trip') }}: {{ $event->shipment->document_no }}
                                @endif
                            </p>

                            @if ($event->receiver_name)
                                <p>
                                    {{ __('sales::delivery.timeline.receiver') }}: {{ $event->receiver_name }}
                                    @if ($event->receiver_phone)
                                        <span class="num">({{ $event->receiver_phone }})</span>
                                    @endif
                                </p>
                            @endif

                            @if ($event->reasonCode)
                                <p>{{ __('sales::delivery.timeline.reason') }}: {{ $event->reasonCode->name() }}</p>
                            @endif

                            @if ($event->note)
                                <p class="text-(--color-ink-muted)">{{ $event->note }}</p>
                            @endif

                            @if ($event->lines->isNotEmpty())
                                <ul class="mt-1 text-xs text-(--color-ink-muted)">
                                    @foreach ($event->lines as $eventLine)
                                        <li>
                                            {{ $eventLine->challanLine?->product?->name() }} —
                                            {{ __('sales::delivery.timeline.quantities') }}:
                                            <span class="num">{{ \App\Core\Support\Money::format($eventLine->delivered_qty) }}</span>
                                            / <span class="num">{{ \App\Core\Support\Money::format($eventLine->challanLine?->delivered_qty ?? '0') }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ol>
        @endif

        @can('sales.delivery.update')
            @include('sales::delivery.partials.actions', ['challan' => $challan, 'deliveryStages' => $deliveryStages])
        @endcan
    </section>
@endcan
