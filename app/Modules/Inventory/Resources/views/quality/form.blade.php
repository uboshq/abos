{{--
    একটা পরিদর্শনের কাগজ খোলা।

    ⚠️ এখানে কোনো রায় নেই, ইচ্ছাকৃতভাবে। ⓘ কাগজ খোলা মানে *"এই মালটা
    দেখা দরকার"* — মজুদে কিছুই বদলায় না। ⛔ একই ফর্মে রায়ের ঘর রাখলে
    যিনি মাল বুঝে নিয়েছেন তিনিই তখনই পাশ করিয়ে দিতেন, আর পরিদর্শন বলে
    কিছু থাকত না।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('inventory::action.new_inspection') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('inventory::action.new_inspection')"
                          :subtitle="__('inventory::message.qc_note')" />
    </x-slot:header>

    @if ($errors->any())
        <div role="alert"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm
                    text-(--color-badge-danger-ink)">
            <ul class="list-inside list-disc">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- ⓘ তালিকায় কেবল যে পণ্যে পরিদর্শন লাগে বলে বলা আছে। ⚠️ একটাও না
         থাকলে এটাই সঠিক বার্তা: আগে পণ্যের পাতায় টিক দিতে হবে। --}}
    @if ($products->isEmpty())
        <x-ui.empty-state :message="__('inventory::message.qc_no_products')" />
    @else
        <form method="POST" action="{{ route('inventory.qc.store') }}" class="space-y-4">
            @csrf

            <section data-boxed
                     class="rounded-(--radius-card) border border-(--color-border)
                            bg-(--color-surface-card) p-4">
                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    <x-ui.select name="product_id" :label="__('inventory::field.product')"
                                 :options="$products->mapWithKeys(fn ($p) => [$p->id => $p->name()])"
                                 :selected="old('product_id')" placeholder="-" required />

                    <x-ui.select name="warehouse_id" :label="__('inventory::field.warehouse')"
                                 :options="$warehouses->mapWithKeys(fn ($w) => [$w->id => $w->name()])"
                                 :selected="old('warehouse_id')" placeholder="-" />

                    <x-ui.field name="inspected_on" type="date" :label="__('inventory::field.date')"
                                :value="old('inspected_on', now()->toDateString())" required />

                    <x-ui.field name="inspected_qty" type="number" step="0.01"
                                :label="__('inventory::field.qc_inspected')"
                                :value="old('inspected_qty')" required />

                    {{-- ⓘ লট ধরা পণ্যে লাগে, বাকিতে খালি — সেবাই ঠিক করে
                         ([[QualityInspectionService::lotFor()]])। --}}
                    <x-ui.field name="batch_no" :label="__('inventory::field.batch_no')"
                                :value="old('batch_no')" />

                    <x-ui.field name="expiry_date" type="date" :label="__('inventory::field.expiry_date')"
                                :value="old('expiry_date')" />
                </div>

                <div class="mt-3 grid gap-3 sm:grid-cols-2">
                    {{-- ⭐ কী দেখে সিদ্ধান্ত — লেখা থাকলে তর্কের দিন কাজে
                         লাগে। ⚠️ ঘরটা ঐচ্ছিক, কারণ বাধ্যতামূলক করলে মানুষ
                         একটা অক্ষর বসিয়ে পার পেত, আর ঘরটা মিথ্যা হত। --}}
                    <x-ui.field name="criteria" :label="__('inventory::field.qc_criteria')"
                                :value="old('criteria')" />

                    <x-ui.field name="remarks" :label="__('inventory::field.narration')"
                                :value="old('remarks')" />
                </div>
            </section>

            <div class="flex flex-wrap gap-2">
                <x-ui.button type="submit" tone="primary">{{ __('core.action.save') }}</x-ui.button>
                <x-ui.button tone="secondary" :href="route('inventory.qc.index')">
                    {{ __('core.action.cancel') }}
                </x-ui.button>
            </div>
        </form>
    @endif
</x-layouts.app>
