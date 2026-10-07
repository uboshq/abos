{{--
    একটা চালানের ডেলিভারি।

    ⓘ গুদাম ও ডেলিভারির লোকের পাতা — তাঁদের কাছে চালানের চাবি না-ও থাকতে
    পারে (দামের কাগজ তাঁদের কাজ নয়), তাই এখানে কেবল যা লাগে: কার কাছে,
    কোন গুদাম থেকে, আর ধাপের সময়রেখা। চালানের চাবি থাকলে পুরো চালানে
    যাওয়ার বোতাম।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $challan->document_no }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$challan->document_no" :subtitle="$challan->customer?->name()">
            <x-slot:actions>
                <x-ui.button tone="secondary" :href="route('sales.delivery.index')">
                    {{ __('sales::delivery.title') }}
                </x-ui.button>

                @can('sales.challan.view')
                    <x-ui.button tone="primary" :href="route('sales.challan.show', $challan)">
                        {{ __('sales::delivery.open_challan') }}
                    </x-ui.button>
                @endcan
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
