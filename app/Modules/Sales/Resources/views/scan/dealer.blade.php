{{--
    QR স্ক্যান, ডিলারের চোখে ([[DeliveryScanController::dealer()]])।

    ⓘ মালিকের কথা: *"ekoi code dilar scane kore tar hisab r invoice dekte pare r confm korte pare"*।
    তাই তিনটা জিনিস, এই ক্রমে: নিজের মোট বকেয়া (আর পুরো হিসাবের লিংক), এই চালানের মাল আর বিল,
    আর "মাল বুঝে পেয়েছি"। ⛔ বোতাম কেবল তখন, যখন সার্ভিস "পৌঁছেছে" নিতে রাজি — আর চাপলেও
    আসল পাহারা সার্ভিসেই ([[DeliveryStageService::move()]])।
--}}
<x-sales::portal.layout :customer="$customer">
    @if (session('saved'))
        <div role="status" data-received
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    @if ($errors->any())
        <div role="alert"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm
                    text-(--color-badge-danger-ink)">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <div data-boxed class="mb-5 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) px-4 py-4">
        <p class="text-2xs uppercase tracking-wide text-(--color-ink-muted)">{{ __('sales::scan.your_due') }}</p>
        <p class="num text-3xl font-semibold" data-due>{{ \App\Core\Support\Money::format($due) }}</p>
        <a href="{{ route('sales.portal.ledger') }}" class="mt-2 inline-block text-sm underline">
            {{ __('sales::scan.open_ledger') }}
        </a>
    </div>

    <h1 class="mb-1 text-lg font-semibold">{{ __('sales::scan.dealer_title', ['no' => $challan->document_no]) }}</h1>
    <p class="mb-4 text-sm text-(--color-ink-muted)">
        {{ \App\Core\Support\DateFormat::format($challan->trx_date) }} ·
        {{ __('sales::scan.stage_now') }}: <strong data-stage>{{ \App\Modules\Sales\Services\DeliveryStage::label($stage) }}</strong>
    </p>

    <h2 class="mb-2 text-sm font-semibold">{{ __('sales::scan.goods') }}</h2>
    <ul class="mb-6 divide-y divide-(--color-border) rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        @foreach ($challan->lines as $line)
            <li class="flex items-center justify-between gap-3 px-4 py-2.5 text-sm">
                <span class="min-w-0">{{ $line->product?->name() }}</span>
                <span class="num shrink-0">{{ $line->packedQty('delivered_qty') }} {{ $line->packedUnitName() }}</span>
            </li>
        @endforeach
    </ul>

    <h2 class="mb-2 text-sm font-semibold">{{ __('sales::scan.bills') }}</h2>
    @if ($invoices->isEmpty())
        <p class="mb-6 text-sm text-(--color-ink-muted)">{{ __('sales::scan.no_bill') }}</p>
    @else
        <ul class="mb-6 divide-y divide-(--color-border) rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
            @foreach ($invoices as $invoice)
                <li class="px-4 py-3 text-sm" data-bill>
                    <p class="font-medium">{{ $invoice->document_no }} · {{ \App\Core\Support\DateFormat::format($invoice->trx_date) }}</p>
                    <dl class="mt-1 grid grid-cols-3 gap-2">
                        <div><dt class="text-2xs text-(--color-ink-muted)">{{ __('sales::scan.bill_total') }}</dt><dd class="num">{{ \App\Core\Support\Money::format($invoice->total) }}</dd></div>
                        <div><dt class="text-2xs text-(--color-ink-muted)">{{ __('sales::scan.bill_paid') }}</dt><dd class="num">{{ \App\Core\Support\Money::format($invoice->collectedAmount()) }}</dd></div>
                        <div><dt class="text-2xs text-(--color-ink-muted)">{{ __('sales::scan.bill_due') }}</dt><dd class="num">{{ \App\Core\Support\Money::format($invoice->dueAmount()) }}</dd></div>
                    </dl>
                </li>
            @endforeach
        </ul>
    @endif

    <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
        <h2 class="mb-1 font-semibold">{{ __('sales::scan.confirm_title') }}</h2>

        @if ($canConfirm)
            <p class="mb-3 text-sm text-(--color-ink-muted)">{{ __('sales::scan.confirm_hint') }}</p>
            <form method="POST" action="{{ route('sales.portal.scan.received', $challan->public_id) }}" data-confirm-form>
                @csrf
                <button type="submit"
                        class="flex min-h-(--spacing-touch) w-full items-center justify-center rounded-(--radius-field)
                               bg-(--color-brand-600) px-4 font-medium text-(--color-brand-ink)">
                    {{ __('sales::scan.confirm') }}
                </button>
            </form>
        @else
            <p class="text-sm text-(--color-ink-muted)" data-cannot-confirm>{{ __('sales::scan.cannot_confirm') }}</p>
        @endif
    </section>
</x-sales::portal.layout>
