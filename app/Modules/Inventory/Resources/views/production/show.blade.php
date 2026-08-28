@php
    use App\Core\Support\DateFormat;
    use App\Core\Support\DocumentStatus;
    use App\Core\Support\Money;

    $confirmed = $production->status === DocumentStatus::CONFIRMED;

    /*
     * উপকরণের সারি — কেবল নিশ্চিত হওয়ার পর।
     *
     * খসড়া অবস্থায় কিছুই বেরোয়নি, তাই দেখানোর মতো কোনো সারিও নেই।
     * রেসিপি ধরে "যা লাগবে" দেখানো যেত, কিন্তু তখন পর্দায় দুই রকম
     * সংখ্যা থাকত — একটা অনুমান, একটা সত্যি — আর কোনটা কোনটা সেটা
     * পরে কেউ মনে রাখতেন না।
     */
    $columns = [
        ['key' => 'product_id', 'label' => __('inventory::field.ingredient'), 'width' => '18rem',
         'render' => fn ($line) => $line->product?->name()],

        ['key' => 'qty', 'label' => __('inventory::field.qty_from_store'), 'width' => '10rem',
         'align' => 'end',
         'render' => fn ($line) => rtrim(rtrim((string) $line->qty, '0'), '.')],

        ['key' => 'cost', 'label' => __('inventory::field.cost'), 'width' => '10rem', 'align' => 'end',
         'render' => fn ($line) => Money::format($line->cost)],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:header>
        <x-ui.page-header :title="$production->document_no"
                          :subtitle="$production->product?->name()">
            @can('confirm', $production)
                <form method="POST" action="{{ route('inventory.production.confirm', $production) }}">
                    @csrf
                    <x-ui.button type="submit" tone="primary">
                        {{ __('inventory::action.confirm_production') }}
                    </x-ui.button>
                </form>
            @endcan
        </x-ui.page-header>
    </x-slot:header>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    <section data-boxed
             class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
        <h2 class="mb-3 font-semibold">{{ __('inventory::section.production_head') }}</h2>

        <dl class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <dt class="text-2xs text-(--color-ink-muted)">{{ __('inventory::field.date') }}</dt>
                <dd class="text-sm">{{ DateFormat::format($production->trx_date) }}</dd>
            </div>

            <div>
                <dt class="text-2xs text-(--color-ink-muted)">{{ __('inventory::field.warehouse') }}</dt>
                <dd class="text-sm">{{ $production->warehouse?->name() }}</dd>
            </div>

            <div>
                <dt class="text-2xs text-(--color-ink-muted)">{{ __('inventory::field.made') }}</dt>
                <dd class="num text-sm">{{ rtrim(rtrim((string) $production->qty, '0'), '.') }}</dd>
            </div>

            <div>
                <dt class="text-2xs text-(--color-ink-muted)">{{ __('inventory::field.state') }}</dt>
                <dd class="text-sm"><x-ui.status-badge :status="$production->status" /></dd>
            </div>

            {{-- খরচ কেবল নিশ্চিত হওয়ার পর।

                 আগে দেখালে ওটা অনুমান হত, আর পর্দায় বসা একটা সংখ্যা
                 দেখতে সবসময় খাতার সংখ্যার মতোই লাগে। --}}
            @if ($confirmed)
                <div>
                    <dt class="text-2xs text-(--color-ink-muted)">{{ __('inventory::field.cost_total') }}</dt>
                    <dd class="num text-sm">{{ Money::format($production->cost_total) }}</dd>
                </div>

                <div>
                    <dt class="text-2xs text-(--color-ink-muted)">{{ __('inventory::field.cost_per_unit') }}</dt>
                    <dd class="num text-sm font-semibold">{{ Money::format($production->unitCost()) }}</dd>
                </div>
            @endif
        </dl>
    </section>

    @if ($confirmed)
        <section data-boxed
                 class="mt-4 overflow-hidden rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card)">
            <h2 class="p-4 pb-0 font-semibold">{{ __('inventory::section.production_used') }}</h2>

            <x-ui.table :empty="__('inventory::message.no_productions')"
                        :rows="$production->lines"
                        :columns="$columns" />
        </section>
    @endif
</x-layouts.app>
