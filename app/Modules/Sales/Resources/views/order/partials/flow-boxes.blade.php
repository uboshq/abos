{{--
    ⭐ আদেশের ধারার বাক্স — সইয়ের অপেক্ষা, সীমায় আটকে, সতর্কবার্তা, ধরা মাল (নকশা "DO বিক্রয় আদেশে মেশানো", ধাপ ৮)।

    ⓘ এক লাইনে এক কথা (মালিকের নিয়ম, ১ অক্টোবর ২০২৬)। যে বাক্সের কিছু বলার নেই সে আঁকা হয় না।
    ⓘ চাই: $order, $approval (অপেক্ষমাণ সই বা null), $held (পণ্য → এই আদেশের ধরা মাল)।
--}}
@php
    use App\Core\Support\DateFormat;
    use App\Core\Support\Money;
    use App\Modules\Sales\Http\Controllers\DepotCheckController;
    use App\Modules\Sales\Support\SalesOrderStatus;

    $box = 'rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4 text-sm';
    $warnings = collect((array) ($order->credit_warnings ?? []))->filter(fn ($w) => is_array($w));
    $heldLines = $order->lines->unique('product_id')
        ->filter(fn ($l) => bccomp((string) ($held[(int) $l->product_id] ?? '0'), '0', 4) > 0);
    $any = $order->status === SalesOrderStatus::AWAITING_APPROVAL
        || $order->status === SalesOrderStatus::CREDIT_HELD
        || $warnings->isNotEmpty()
        || $heldLines->isNotEmpty();
@endphp

@if ($any)
    <div class="grid gap-4 lg:grid-cols-2">
        @if ($order->status === SalesOrderStatus::AWAITING_APPROVAL)
            <section data-order-approval-box class="{{ $box }}">
                <h2 class="font-semibold">{{ __('sales::order_status.box_awaiting') }}</h2>
                @if ($approval !== null)
                    <p class="mt-1">{{ __('sales::order_status.box_level', ['level' => (int) $approval->current_level]) }}</p>
                    <p class="mt-1">
                        <a href="{{ route('approval.inbox.show', $approval) }}" class="text-(--color-brand-700) underline">
                            {{ __('sales::order_status.box_open_signature') }}
                        </a>
                    </p>
                @endif
            </section>
        @endif

        @if ($order->status === SalesOrderStatus::CREDIT_HELD)
            <section data-credit-held class="{{ $box }}">
                <h2 class="font-semibold">{{ __('sales::order_status.box_credit_held') }}</h2>
                <p class="mt-1">{{ __('sales::order_status.box_short', ['amount' => Money::format((string) $order->credit_short)]) }}</p>
                <p class="mt-1">{{ __('sales::order_status.box_held_since', ['date' => DateFormat::format($order->credit_held_at)]) }}</p>
                <p class="mt-1">{{ __('sales::order_status.box_checked_at', ['date' => DateFormat::format($order->credit_checked_at)]) }}</p>
                <p class="mt-1 text-(--color-ink-muted)">{{ __('sales::order_status.box_credit_hint') }}</p>
            </section>
        @endif

        @if ($warnings->isNotEmpty())
            <section data-credit-warnings class="{{ $box }}">
                <h2 class="font-semibold">{{ __('sales::order_status.box_warnings') }}</h2>
                @foreach ($warnings as $warning)
                    <p class="mt-1">⚠️ {{ DepotCheckController::warningText($warning) }}</p>
                @endforeach
            </section>
        @endif

        @if ($heldLines->isNotEmpty())
            <section data-held-stock class="{{ $box }}">
                <h2 class="font-semibold">{{ __('sales::order_status.box_held') }}</h2>
                @foreach ($heldLines as $line)
                    <p class="mt-1">{{ __('sales::order_status.box_held_line', [
                        'product' => $line->product?->name() ?? '—',
                        'qty' => Money::quantity((string) $held[(int) $line->product_id]),
                    ]) }}</p>
                @endforeach
                <p class="mt-1 text-(--color-ink-muted)">{{ __('sales::order_status.box_held_hint') }}</p>
            </section>
        @endif
    </div>
@endif
