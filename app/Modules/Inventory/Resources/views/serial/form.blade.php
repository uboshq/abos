{{--
    নম্বরগুলো বসানো — এক ঘরে, প্রতি লাইনে একটা।

    ── ⚠️ কেন একটা বড় ঘর, একশোটা ছোট ঘর নয় ─────────────────────────────
    ⓘ একশো পিসের চালানে একশোটা আলাদা ঘর আঁকলে পাতাটাই খুলত না। ⭐ আর
    স্ক্যানার সাধারণত প্রতিটা স্ক্যানের পরে একটা নতুন লাইন পাঠায় —
    অর্থাৎ ঘরটা স্ক্যানারের ভাষাতেই কথা বলে: স্ক্যান, স্ক্যান, স্ক্যান।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('inventory::action.add_serials') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('inventory::action.add_serials')"
                          :subtitle="__('inventory::message.serial_note')" />
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

    {{-- ⓘ কেবল যে পণ্যে সিরিয়াল ধরা হয়। ⚠️ একটাও না থাকলে এটাই সঠিক
         বার্তা: আগে পণ্যের পাতায় টিক দিতে হবে। --}}
    @if ($products->isEmpty())
        <x-ui.empty-state :message="__('inventory::message.serial_no_products')" />
    @else
        <form method="POST" action="{{ route('inventory.serial.store') }}" class="space-y-4">
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

                    <x-ui.field name="received_on" type="date" :label="__('inventory::field.date')"
                                :value="old('received_on', now()->toDateString())" required />
                </div>
            </section>

            <section data-boxed
                     class="rounded-(--radius-card) border border-(--color-border)
                            bg-(--color-surface-card) p-4">
                <label class="block">
                    <span class="mb-1 block text-sm font-medium">
                        {{ __('inventory::field.serial_numbers') }}
                    </span>
                    <span class="mb-2 block text-xs text-(--color-ink-muted)">
                        {{ __('inventory::message.serial_one_per_line') }}
                    </span>

                    {{-- ⓘ `rows="10"` — ⚠️ ছোট ঘর দিলে মানুষ ভাবতেন কয়েকটার
                         বেশি নেওয়া যায় না, আর একশোটা নম্বর দশ বারে বসাতেন। --}}
                    <textarea name="serials" rows="10" required
                              class="w-full rounded-(--radius-field) border border-(--color-border)
                                     bg-(--color-surface-card) px-3 py-2 font-mono text-sm"
                    >{{ old('serials') }}</textarea>
                </label>
            </section>

            <div class="flex flex-wrap gap-2">
                <x-ui.button type="submit" tone="primary">{{ __('core.action.save') }}</x-ui.button>
                <x-ui.button tone="secondary" :href="route('inventory.serial.index')">
                    {{ __('core.action.cancel') }}
                </x-ui.button>
            </div>
        </form>
    @endif
</x-layouts.app>
