{{--
    একটা স্থানান্তর।

    বোতামটা অবস্থা অনুযায়ী বদলায়: খসড়ায় "রওনা দিন", রাস্তায় থাকলে
    "বুঝে নিন"। দুইটা একসাথে দেখালে কেউ ভুল ক্রমে চাপতেন, আর সেবা স্তর
    আটকাত — কিন্তু ততক্ষণে তিনি ভাবতেন কিছু একটা ভেঙেছে।
--}}
@php
    $isDraft = $transfer->status === \App\Core\Support\DocumentStatus::DRAFT;
    $onTheWay = $transfer->status === \App\Core\Support\DocumentStatus::CONFIRMED;
    $arrived = $transfer->status === \App\Core\Support\DocumentStatus::CLOSED;
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $transfer->document_no }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header
            :title="$transfer->document_no"
            :subtitle="($transfer->fromWarehouse?->name() ?? '') . ' → ' . ($transfer->toWarehouse?->name() ?? '')">
            <x-slot:actions>
                @if ($isDraft)
                    @can('update', $transfer)
                        <x-ui.button tone="secondary" :href="route('inventory.transfer.edit', $transfer)">
                            {{ __('core.action.edit') }}
                        </x-ui.button>

                        <form method="POST" action="{{ route('inventory.transfer.dispatch', $transfer) }}">
                            @csrf
                            <x-ui.button type="submit" tone="primary">
                                {{ __('inventory::action.dispatch') }}
                            </x-ui.button>
                        </form>
                    @endcan
                @elseif ($onTheWay)
                    @can('inventory.transfer.receive')
                        <form method="POST" action="{{ route('inventory.transfer.receive', $transfer) }}">
                            @csrf
                            <x-ui.button type="submit" tone="primary">
                                {{ __('inventory::action.receive') }}
                            </x-ui.button>
                        </form>
                    @endcan
                @endif
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    <div class="space-y-4">
        <section class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <dl class="grid gap-3 text-sm sm:grid-cols-2 xl:grid-cols-4">
                @foreach ([
                    'inventory::field.date' => \App\Core\Support\DateFormat::format($transfer->trx_date),
                    'inventory::field.dispatched_at' => \App\Core\Support\DateFormat::formatWithTime($transfer->dispatched_at) ?: '-',
                    'inventory::field.received_at' => \App\Core\Support\DateFormat::formatWithTime($transfer->received_at) ?: '-',
                    'inventory::field.narration' => $transfer->narration ?: '-',
                ] as $label => $value)
                    <div>
                        <dt class="text-(--color-ink-muted)">{{ __($label) }}</dt>
                        <dd class="mt-0.5">{{ $value }}</dd>
                    </div>
                @endforeach

                <div>
                    <dt class="text-(--color-ink-muted)">{{ __('inventory::field.state') }}</dt>
                    <dd class="mt-0.5">
                        @include('inventory::transfer.partials.status', ['transfer' => $transfer])
                    </dd>
                </div>
            </dl>

            @if ($onTheWay)
                {{-- রাস্তায় থাকা মালটা কোথায় আছে, সেটা লেখা থাকে —
                     নাহলে উৎস গুদামের লোক দেখতেন মাল আছে অথচ বেচা
                     যাচ্ছে না, আর কারণটা কোথাও লেখা থাকত না। --}}
                <p class="mt-3 rounded-(--radius-field) bg-(--color-badge-pending-bg) px-3 py-2 text-sm
                          text-(--color-badge-pending-ink)">
                    {{ __('inventory::message.transfer_holding', [
                        'warehouse' => $transfer->fromWarehouse?->name() ?? '',
                    ]) }}
                </p>
            @endif
        </section>

        <section class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card)">
            <h2 class="border-b border-(--color-border) bg-(--color-section-head) px-4 py-3 font-semibold">
                {{ __('inventory::message.lines') }}
            </h2>

            <x-ui.table
                :empty="__('inventory::validation.no_lines')"
                :rows="$transfer->lines"
                :columns="[
                    ['key' => 'line_no', 'label' => __('inventory::field.line_no'), 'width' => '4rem'],
                    ['key' => 'product', 'label' => __('inventory::field.product'),
                     'render' => fn ($l) => $l->product?->name()],
                    ['key' => 'qty', 'label' => __('inventory::field.quantity'), 'numeric' => true, 'width' => '10rem',
                     'render' => fn ($l) => rtrim(rtrim((string) $l->qty, '0'), '.').' '.($l->product?->unit?->code ?? '')],
                ]" />
        </section>

        @can('delete', $transfer)
            @unless ($arrived || $transfer->status === \App\Core\Support\DocumentStatus::CANCELLED)
                <details class="rounded-(--radius-card) border border-(--color-border)
                                bg-(--color-surface-card) p-4">
                    <summary class="cursor-pointer text-sm font-medium">
                        {{ __('inventory::action.cancel_transfer') }}
                    </summary>

                    <form method="POST" action="{{ route('inventory.transfer.cancel', $transfer) }}"
                          class="mt-3 space-y-3">
                        @csrf
                        <x-ui.field name="reason" :label="__('inventory::message.cancel_reason')" required />
                        <x-ui.button type="submit" tone="danger">
                            {{ __('inventory::action.cancel_transfer') }}
                        </x-ui.button>
                    </form>
                </details>
            @endunless
        @endcan
    </div>
</x-layouts.app>
