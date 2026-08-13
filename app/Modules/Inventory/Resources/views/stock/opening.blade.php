{{--
    খোলা মজুদ — পুরনো খাতা থেকে আসার দিনের একবারের কাজ।

    ── কেন ফর্মের পাশে বসানো তালিকাটা ─────────────────────────────────
    খোলা মজুদ বসানো হয় একদিনে নয়, কয়েকদিন ধরে — পণ্য ধরে ধরে, গুদাম ধরে
    ধরে। মাঝপথে "কোনটা করা হয়ে গেছে" প্রশ্নটা বারবার আসে, আর উত্তরটা
    হাতের কাছে না থাকলে একই পণ্য দুইবার বসানোর চেষ্টা হয়।

    সার্ভার সেটা আটকায় ঠিকই, কিন্তু আটকানো আর জানানো এক জিনিস নয়।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('inventory::menu.opening') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('inventory::menu.opening')" />
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

    <div class="grid gap-4 lg:grid-cols-[minmax(0,22rem)_minmax(0,1fr)]">

        <section class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="mb-1 font-semibold">{{ __('inventory::menu.opening') }}</h2>
            <p class="mb-3 max-w-(--spacing-prose-max) text-sm text-(--color-ink-muted)">
                {{ __('inventory::message.opening_note') }}
            </p>

            {{--
                পরিমাণ × দর = মূল্য, চোখের সামনেই।

                সংখ্যাটা সার্ভারই হিসাব করে বসায় — এখানকার অঙ্কটা শুধু
                দেখার জন্য, যাতে দর লেখার সময়েই বোঝা যায় ব্যালেন্স শিটে
                কত টাকা বসতে যাচ্ছে। ৫০ আর ৫০০ পাশাপাশি দেখতে প্রায় এক,
                কিন্তু মূল্যের ঘরে পার্থক্যটা দশগুণ হয়ে চোখে পড়ে।
            --}}
            <form method="POST" action="{{ route('inventory.stock.opening.store') }}" class="space-y-3"
                  x-data="{ qty: '', rate: '',
                            get value() {
                                const v = (parseFloat(this.qty) || 0) * (parseFloat(this.rate) || 0);
                                return v ? v.toLocaleString(undefined, { minimumFractionDigits: 2,
                                                                        maximumFractionDigits: 2 }) : '—';
                            } }">
                @csrf

                <x-ui.select name="product_id" :label="__('inventory::field.product')"
                             :options="$products->mapWithKeys(fn ($p) => [$p->id => $p->code . ' - ' . $p->name()])"
                             placeholder="-" required />

                <x-ui.select name="warehouse_id" :label="__('inventory::field.warehouse')"
                             :options="$warehouses->mapWithKeys(fn ($w) => [$w->id => $w->name()])"
                             placeholder="-" required />

                <x-ui.field name="qty" type="number" step="0.01" min="0" inputmode="decimal"
                            :label="__('inventory::field.quantity')" numeric required
                            x-model="qty" />

                <x-ui.field name="unit_cost" type="number" step="0.01" min="0" inputmode="decimal"
                            :label="__('inventory::field.opening_rate')" numeric required
                            x-model="rate" />

                <div class="flex items-baseline justify-between rounded-(--radius-field)
                            bg-(--color-surface-app) px-3 py-2">
                    <span class="text-sm text-(--color-ink-muted)">
                        {{ __('inventory::field.opening_value') }}
                    </span>
                    <span class="num font-semibold" x-text="value">—</span>
                </div>

                <x-ui.field name="trx_date" type="date" :label="__('inventory::field.date')"
                            :value="now()->toDateString()" />

                <x-ui.field name="narration" :label="__('inventory::field.narration')" />

                <x-ui.button type="submit" tone="primary">{{ __('core.action.save') }}</x-ui.button>
            </form>
        </section>

        <section class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
            <div class="flex items-baseline justify-between border-b border-(--color-border) px-4 py-3">
                <h2 class="font-semibold">{{ __('inventory::message.opening_total') }}</h2>
                <span class="num font-semibold">{{ \App\Core\Support\Money::format($total) }}</span>
            </div>

            <x-ui.table
                :empty="__('inventory::message.opening_none')"
                :rows="$entered"
                compact
                :columns="[
                    ['key' => 'trx_date', 'label' => __('inventory::field.date'), 'width' => '7rem',
                     'render' => fn ($r) => \App\Core\Support\DateFormat::format($r->trx_date)],
                    ['key' => 'product', 'label' => __('inventory::field.product'),
                     'render' => fn ($r) => $r->product_code . ' - '
                         . (app()->getLocale() === 'bn' && $r->name_bn ? $r->name_bn : $r->name_en)],
                    ['key' => 'warehouse', 'label' => __('inventory::field.warehouse'), 'width' => '10rem',
                     'render' => fn ($r) => app()->getLocale() === 'bn' && $r->warehouse_bn
                         ? $r->warehouse_bn : $r->warehouse_en],
                    ['key' => 'qty', 'label' => __('inventory::field.quantity'), 'numeric' => true, 'width' => '7rem',
                     'render' => fn ($r) => \App\Core\Support\Money::format($r->qty)],
                    ['key' => 'unit_cost', 'label' => __('inventory::field.opening_rate'),
                     'numeric' => true, 'width' => '8rem',
                     'render' => fn ($r) => \App\Core\Support\Money::format($r->unit_cost)],
                    ['key' => 'value', 'label' => __('inventory::field.opening_value'),
                     'numeric' => true, 'width' => '9rem',
                     'render' => fn ($r) => \App\Core\Support\Money::format($r->value)],
                ]" />
        </section>
    </div>
</x-layouts.app>
