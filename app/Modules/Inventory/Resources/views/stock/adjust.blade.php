{{--
    গণনা, আটকানো ও ছাড়া — এক পাতায় তিনটা কাজ।

    তিনটাই একই জিনিস নিয়ে (পণ্য, গুদাম, পরিমাণ, কারণ), তাই তিনটা আলাদা
    পাতা করলে ব্যবহারকারীকে একই তথ্য তিনবার শিখতে হত।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('inventory::menu.adjust') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('inventory::menu.adjust')" />
    </x-slot:header>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

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

    <div class="grid gap-4 lg:grid-cols-2">

        {{-- গণনা ও সমন্বয় --}}
        <section class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="mb-1 font-semibold">{{ __('inventory::menu.adjust') }}</h2>
            <p class="mb-3 max-w-(--spacing-prose-max) text-sm text-(--color-ink-muted)">
                {{ __('inventory::message.adjust_note') }}
            </p>

            <form method="POST" action="{{ route('inventory.stock.adjust.store') }}" class="space-y-3">
                @csrf

                <x-ui.select name="product_id" :label="__('inventory::field.product')"
                             :options="$products->mapWithKeys(fn ($p) => [$p->id => $p->code . ' - ' . $p->name()])"
                             placeholder="-" required />

                <x-ui.select name="warehouse_id" :label="__('inventory::field.warehouse')"
                             :options="$warehouses->mapWithKeys(fn ($w) => [$w->id => $w->name()])"
                             placeholder="-" required />

                <x-ui.field name="counted" type="number" step="0.01" inputmode="decimal"
                            :label="__('inventory::field.counted')" numeric required />

                <x-ui.select name="reason_code_id" :label="__('inventory::field.reason')"
                             :options="$reasons->mapWithKeys(fn ($r) => [$r->id => $r->name()])"
                             placeholder="-" required />

                <x-ui.field name="trx_date" type="date" :label="__('inventory::field.date')"
                            :value="now()->toDateString()" />

                <x-ui.button type="submit" tone="primary">{{ __('inventory::action.adjust') }}</x-ui.button>
            </form>
        </section>

        {{-- আটকানো ও ছাড়া --}}
        <section class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="mb-1 font-semibold">{{ __('inventory::action.hold') }}</h2>
            <p class="mb-3 max-w-(--spacing-prose-max) text-sm text-(--color-ink-muted)">
                {{ __('inventory::message.hold_note') }}
            </p>

            <form method="POST" action="{{ route('inventory.stock.hold') }}" class="space-y-3">
                @csrf

                <x-ui.select name="product_id" :label="__('inventory::field.product')"
                             :options="$products->mapWithKeys(fn ($p) => [$p->id => $p->code . ' - ' . $p->name()])"
                             placeholder="-" required />

                <x-ui.select name="warehouse_id" :label="__('inventory::field.warehouse')"
                             :options="$warehouses->mapWithKeys(fn ($w) => [$w->id => $w->name()])"
                             placeholder="-" required />

                <x-ui.field name="qty" type="number" step="0.01" inputmode="decimal"
                            :label="__('inventory::field.quantity')" numeric required />

                <x-ui.select name="reason_code_id" :label="__('inventory::field.reason')"
                             :options="$holdReasons->mapWithKeys(fn ($r) => [$r->id => $r->name()])"
                             placeholder="-" required />

                <x-ui.field name="trx_date" type="date" :label="__('inventory::field.date')"
                            :value="now()->toDateString()" />

                <div class="flex flex-wrap gap-2">
                    <x-ui.button type="submit" tone="primary">{{ __('inventory::action.hold') }}</x-ui.button>

                    {{-- ছাড়াটা একই ফর্মে, শুধু action আলাদা: একই চারটা ঘর
                         দুইবার লিখলে একটায় ফিল্ড যোগ করে অন্যটায় ভুলে
                         যাওয়া নিশ্চিত --}}
                    <x-ui.button type="submit" tone="secondary"
                                 formaction="{{ route('inventory.stock.release') }}">
                        {{ __('inventory::action.release') }}
                    </x-ui.button>
                </div>
            </form>
        </section>
    </div>
</x-layouts.app>
