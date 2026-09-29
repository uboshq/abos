{{--
    QR স্ক্যান, কর্মীর চোখে — চালানের সারাংশ আর ডেলিভারির সময়রেখা ([[DeliveryScanController::staff()]])।

    ⓘ ধাপ বসানোর ফর্ম পুরনোটাই ([[delivery.partials.timeline]] → [[delivery.partials.actions]] →
    `sales.delivery.move`)। ⛔ এই পাতা নিজে কিছু লেখে না — রওনায় বিল আর গেট পাস সেই একই পথে
    বানানো হয়, আর চাপার পরে `back()` এই পাতাতেই ফেরায়।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('sales::scan.staff_title', ['no' => $challan->document_no]) }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$challan->document_no" :subtitle="$challan->customer?->name()">
            <x-slot:actions>
                <x-ui.button tone="secondary" :href="route('sales.delivery.show', $challan)">
                    {{ __('sales::delivery.title') }}
                </x-ui.button>
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

    <x-ui.errors />

    <div class="space-y-4">
        <p class="text-sm text-(--color-ink-muted)">{{ __('sales::scan.next_hint') }}</p>

        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <dl class="grid gap-3 text-sm sm:grid-cols-2 xl:grid-cols-4">
                @foreach ([
                    'sales::field.date' => \App\Core\Support\DateFormat::format($challan->trx_date),
                    'sales::field.customer' => $challan->customer?->name() ?: '-',
                    'sales::field.warehouse' => $challan->warehouse?->name() ?: '-',
                    'sales::field.vehicle_no' => $challan->vehiclePlate() ?: '-',
                ] as $label => $value)
                    <div>
                        <dt class="text-(--color-ink-muted)">{{ __($label) }}</dt>
                        <dd class="mt-0.5">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
        </section>

        @include('sales::delivery.partials.timeline', ['challan' => $challan])
    </div>
</x-layouts.app>
