{{--
    ⭐ খোলা মজুদের এক সারি সংশোধন — মালিক, ৬ অক্টোবর ২০২৬: "ভুলে লট ছাড়া সেভ করে ফেলেছি, এডিটের ব্যবস্থা কী?"
    ⓘ নিয়ম [[OpeningStockService::correct()]]-এ: এই পণ্যের মাল এই গুদাম থেকে নড়ে থাকলে সংরক্ষণে বার্তা আসে, কিছুই বদলায় না।
    ⓘ দর লুকানো থাকলে দরের ঘর খালি — খালি মানে পুরনো দর।
--}}
<x-layouts.app :menu="$menu">
    {{-- ⓘ দর দেখা যায় কি না — তালিকার পাতার একই প্রশ্ন (অডিট ম১২) --}}
    @php $showCost = \App\Core\Security\FieldSecurity::visible(\App\Modules\Inventory\Models\StockMovement::class, 'unit_cost'); @endphp

    <x-slot:title>{{ __('inventory::message.opening_edit_title') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('inventory::message.opening_edit_title')" />
    </x-slot:header>

    @if ($errors->any())
        <div role="alert" data-opening-edit-error
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm text-(--color-badge-danger-ink)">
            <ul class="list-inside list-disc">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <section data-boxed class="max-w-3xl rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
        <p class="mb-1 font-semibold">{{ $product->code }} — {{ $product->name() }}</p>
        <p class="mb-3 text-sm text-(--color-ink-muted)">
            {{ $warehouse->name() }} · {{ \App\Core\Support\DateFormat::format($movement->trx_date) }}
        </p>
        <p class="mb-4 max-w-(--spacing-prose-max) text-sm text-(--color-ink-muted)">{{ __('inventory::message.opening_edit_note') }}</p>

        <form method="POST" action="{{ route('inventory.stock.opening.update', $movement->id) }}" data-opening-edit-form>
            @csrf
            @method('PUT')

            <div class="grid gap-3 sm:grid-cols-2">
                @if ($product->track_batch)
                    <x-ui.field name="batch_no" :label="__('inventory::field.batch_no')"
                                :value="old('batch_no', $batch?->batch_no)" :hint="__('inventory::message.opening_cart_lot_auto')" />
                    <x-ui.field name="expiry_date" type="date" :label="__('inventory::field.expiry_date')"
                                :value="old('expiry_date', $batch?->expiry_date?->toDateString())" />
                @endif
                <x-ui.field name="qty" type="number" step="any" numeric required :label="__('inventory::field.quantity')"
                            :value="old('qty', rtrim(rtrim((string) $movement->floor_change, '0'), '.'))" />
                <x-ui.field name="free_qty" type="number" step="any" numeric :label="__('inventory::field.free')"
                            :value="old('free_qty', rtrim(rtrim((string) ($movement->free_change ?? '0'), '0'), '.'))" />
                <x-ui.field name="unit_cost" type="number" step="any" numeric :label="__('inventory::field.opening_rate')"
                            :value="old('unit_cost', $showCost && $layer !== null ? rtrim(rtrim((string) $layer->unit_cost, '0'), '.') : '')" />
            </div>

            <div class="mt-4 flex flex-wrap items-center gap-3">
                <x-ui.button type="submit" tone="primary">{{ __('core.action.save') }}</x-ui.button>
                <a href="{{ route('inventory.stock.opening') }}" class="text-sm text-(--color-brand-600) hover:underline">{{ __('core.action.cancel') }}</a>
            </div>
        </form>

        <form method="POST" action="{{ route('inventory.stock.opening.destroy', $movement->id) }}" class="mt-6 border-t border-(--color-border) pt-4"
              data-opening-remove-form data-confirm="{{ __('inventory::message.opening_remove_confirm') }}">
            @csrf
            @method('DELETE')
            <x-ui.button type="submit" tone="danger">{{ __('core.action.delete') }}</x-ui.button>
        </form>
    </section>
</x-layouts.app>
