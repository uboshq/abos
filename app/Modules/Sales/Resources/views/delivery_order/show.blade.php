{{--
    ⭐ একটা DO — মাথা, লাইন, আর যার হাতে যে কাজ ([[DeliveryOrderDeskController::show()]])।
    ⓘ লেখক খসড়া জমা দেন; সুপারভাইজার (সই তাঁর হাতে থাকলে) পরিমাণ বদলান, তারপর অনুমোদন-বাক্সের একই দরজায় সই বা ফেরত।
    এক লাইনে এক জিনিস — মালিকের পর্দা ডান দিক কাটে, তাই টেবিল নয়।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $order->document_no }}</x-slot:title>

    <x-ui.errors />
    @if (session('saved'))
        <p class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm text-(--color-badge-success-ink)" role="status">{{ session('saved') }}</p>
    @endif

    <section data-boxed class="mb-4 grid gap-1 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4 text-sm" data-do-head>
        <p class="text-lg font-semibold">{{ $order->document_no }}</p>
        <p data-do-status>{{ __('sales::field.state') }}: <strong>{{ \App\Modules\Sales\Support\DeliveryOrderStatus::label((string) $order->status) }}</strong></p>
        <p>{{ __('sales::field.customer') }}: {{ $order->customer?->name() }}</p>
        <p>{{ __('sales::field.date') }}: {{ \App\Core\Support\DateFormat::format($order->trx_date) }}</p>
        @if ($order->deliver_on)
            <p>{{ __('sales::delivery_order.deliver_on') }}: {{ \App\Core\Support\DateFormat::format($order->deliver_on) }}</p>
        @endif
        <p>{{ __('sales::delivery_order.written_by') }}: {{ $order->dealerWriter?->name() ?? $order->creator?->name }}</p>
        <p>{{ __('sales::field.total') }}: <span class="num">{{ \App\Core\Support\Money::format($order->total) }}</span></p>
        @if ($order->narration)
            <p>{{ __('sales::field.narration') }}: {{ $order->narration }}</p>
        @endif
    </section>

    @if ($awaitingMe)
        {{-- ⭐ সুপারভাইজার — মজুদ দেখে পরিমাণ বদলান (০ থেকে চাওয়া পর্যন্ত), তারপর সই --}}
        <form method="POST" action="{{ route('sales.delivery_order.quantities', $order) }}" class="mb-4 grid gap-2" data-do-quantities>
            @csrf
            @foreach ($order->lines as $line)
                <div class="grid gap-1 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-3 text-sm">
                    <p class="font-medium">{{ $line->product?->name() }}</p>
                    <p>{{ __('sales::delivery_order.qty') }}: <span class="num">{{ \App\Core\Support\Money::quantity($line->qty) }}</span></p>
                    <label class="grid gap-1">
                        <span>{{ __('sales::delivery_order.approved_qty') }}</span>
                        <input type="number" step="1" min="0" max="{{ bcadd((string) $line->qty, '0', 4) }}" name="lines[{{ $line->id }}]"
                               value="{{ old('lines.'.$line->id, bcadd($line->finalQty(), '0', 4)) }}"
                               class="num h-(--spacing-field) w-full rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-3 text-end">
                    </label>
                </div>
            @endforeach
            <x-ui.button type="submit">{{ __('sales::delivery_order.save_quantities') }}</x-ui.button>
        </form>

        <div class="mb-4 grid gap-2" data-do-decide>
            <form method="POST" action="{{ route('approval.inbox.approve', $approval->id) }}">
                @csrf
                <x-ui.button type="submit" tone="success" class="w-full">{{ __('sales::delivery_order.approve') }}</x-ui.button>
            </form>
            <form method="POST" action="{{ route('approval.inbox.reject', $approval->id) }}" class="grid gap-2">
                @csrf
                <x-ui.field name="remarks" :label="__('sales::delivery_order.reject_reason')" required />
                <x-ui.button type="submit" tone="danger">{{ __('sales::delivery_order.reject') }}</x-ui.button>
            </form>
        </div>
    @else
        <section class="mb-4 grid gap-2" data-do-lines>
            @foreach ($order->lines as $line)
                <div class="grid gap-1 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-3 text-sm">
                    <p class="font-medium">{{ $line->product?->name() }}</p>
                    <p>{{ __('sales::delivery_order.qty') }}: <span class="num">{{ \App\Core\Support\Money::quantity($line->qty) }}</span></p>
                    @if ($line->approved_qty !== null)
                        <p>{{ __('sales::delivery_order.approved_qty') }}: <span class="num">{{ \App\Core\Support\Money::quantity($line->approved_qty) }}</span></p>
                    @endif
                    <p>{{ __('sales::field.total') }}: <span class="num">{{ \App\Core\Support\Money::format($line->line_total) }}</span></p>
                </div>
            @endforeach
        </section>
    @endif

    @if ($mayTouch)
        <form method="POST" action="{{ route('sales.delivery_order.submit', $order) }}" data-do-submit>
            @csrf
            <x-ui.button type="submit" tone="primary">{{ __('sales::delivery_order.submit') }}</x-ui.button>
        </form>
    @endif
</x-layouts.app>
