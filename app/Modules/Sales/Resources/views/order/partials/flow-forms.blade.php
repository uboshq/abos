{{--
    ⭐ আদেশের ধারার দুই ফর্ম — সুপারভাইজারের পরিমাণ কমানো, আর এক লাইনের বাকিটা বন্ধ (নকশা "DO বিক্রয় আদেশে মেশানো", ধাপ ৮)।

    ⓘ পরিমাণ কমানো কেবল সইয়ের অপেক্ষায়, কেবল এখনকার অনুমোদনকারী ($mayLower — [[ApprovalEngine::canDecide()]]);
    ০ থেকে চাওয়া পর্যন্ত, বাড়ানো নয় ([[SalesOrderService::setApprovedQuantities()]])।
    ⓘ বাকিটা বন্ধ কেবল সংরক্ষিত আদেশে, বন্ধের চাবিতে, কারণসহ ([[SalesOrderService::rejectRemainder()]])।
    ⓘ এক লাইনে এক ঘর — ছক নয় (মালিকের নিয়ম, ১ অক্টোবর ২০২৬)।
--}}
@php
    use App\Core\Support\Money;
    use App\Modules\Sales\Support\SalesOrderStatus;

    $box = 'rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4';
    $openLines = $order->status === SalesOrderStatus::CONFIRMED
        ? $order->lines->filter(fn ($l) => bccomp($l->pendingQty(), '0', 4) > 0)
        : collect();
@endphp

@if ($mayLower)
    <details data-lower-quantities open class="{{ $box }}">
        <summary class="cursor-pointer text-sm font-medium">{{ __('sales::order_status.lower_title') }}</summary>

        <form method="POST" action="{{ route('sales.order.quantities', $order) }}" class="mt-3 grid gap-3">
            @csrf
            <p class="text-sm text-(--color-ink-muted)">{{ __('sales::order_status.lower_hint') }}</p>

            @foreach ($order->lines as $line)
                <x-ui.field type="number" step="any" min="0" :numeric="true" :name="'qty['.$line->id.']'" :error-key="'lines.'.$line->id"
                            :value="rtrim(rtrim((string) $line->ordered_qty, '0'), '.')"
                            :label="__('sales::order_status.lower_line', [
                                'product' => $line->product?->name() ?? '—',
                                'asked' => Money::quantity((string) ($line->requested_qty ?? $line->ordered_qty)),
                            ])" />
            @endforeach

            <x-ui.button type="submit" tone="primary">{{ __('sales::order_status.lower_save') }}</x-ui.button>
        </form>
    </details>
@endif

@can('sales.order.close')
    @if ($openLines->isNotEmpty())
        <details data-reject-rest class="{{ $box }}">
            <summary class="cursor-pointer text-sm font-medium">{{ __('sales::order_status.reject_title') }}</summary>

            <form method="POST" action="{{ route('sales.order.reject_rest', $order) }}" class="mt-3 grid gap-3">
                @csrf
                <p class="text-sm text-(--color-ink-muted)">{{ __('sales::order_status.reject_hint') }}</p>

                <x-ui.select name="line_id" :label="__('sales::order_status.field_reject_line')" required
                             :options="$openLines->mapWithKeys(fn ($l) => [$l->id => __('sales::order_status.reject_option', [
                                 'product' => $l->product?->name() ?? '—',
                                 'open' => Money::quantity($l->pendingQty()),
                             ])])->all()" />
                <x-ui.field type="number" step="any" min="0" :numeric="true" name="reject_qty" :label="__('sales::order_status.field_reject_qty')" required />
                <x-ui.field name="reject_reason" :label="__('sales::order_status.field_reject_reason')" required />

                <x-ui.button type="submit" tone="secondary">{{ __('sales::order_status.reject_save') }}</x-ui.button>
            </form>
        </details>
    @endif
@endcan
