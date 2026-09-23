{{--
    লট বসানো — যে মাল তাকে আছে অথচ বেচা যায় না।

    ── ⓘ কেন এই পর্দায় কোনো টাকার ঘর নেই ──────────────────────────────
    এখানে মাল আনা হচ্ছে না, নাম বসানো হচ্ছে। পরিমাণ এক থাকে, দাম এক
    থাকে, খতিয়ান অস্পর্শিত। ⚠️ একটা দরের ঘর রাখলে সেটাই ইঙ্গিত দিত যে
    কিছু একটা মূল্যায়ন বদলাচ্ছে — আর একদিন কেউ ওটা বদলেও ফেলত।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('inventory::menu.lot_assign') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('inventory::menu.lot_assign')" />
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

        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="mb-1 font-semibold">{{ __('inventory::menu.lot_assign') }}</h2>
            <p class="mb-3 max-w-(--spacing-prose-max) text-sm text-(--color-ink-muted)">
                {{ __('inventory::message.lot_assign_note') }}
            </p>

            <form method="POST" action="{{ route('inventory.stock.lot.store') }}" class="space-y-3">
                @csrf

                <x-ui.select name="product_id" :label="__('inventory::field.product')"
                             :options="$products->mapWithKeys(fn ($p) => [$p->id => $p->code . ' - ' . $p->name()])"
                             placeholder="-" required />

                <x-ui.select name="warehouse_id" :label="__('inventory::field.warehouse')"
                             :options="$warehouses->mapWithKeys(fn ($w) => [$w->id => $w->name()])"
                             placeholder="-" required />

                {{--
                    লট নম্বরটা লেখা, বাছা নয়।

                    ⓘ যে মালের লট বসানো হচ্ছে তার লট ব্যবস্থায় এখনো নেই —
                    কার্টনের গায়ে আছে। ⚠️ ড্রপডাউন দিলে তালিকাটা প্রথম
                    দিন খালি থাকত, আর কাজটা শুরুই করা যেত না। নম্বরটা
                    আগে থেকে থাকলে সেই লটেই বসে, নতুন সারি হয় না।
                --}}
                <x-ui.field name="batch_no" :label="__('inventory::field.batch_no')" required />

                <x-ui.field name="expiry_date" type="date" :label="__('inventory::field.expiry_date')" />

                <x-ui.field name="qty" type="number" step="0.01" min="0" inputmode="decimal"
                            :label="__('inventory::field.quantity')" numeric required />

                {{-- ⓘ ফ্রি ভাণ্ডারও আলাদা করে আটকায়, তাই আলাদা ঘর --}}
                <x-ui.field name="free_qty" type="number" step="0.01" min="0" inputmode="decimal"
                            :label="__('inventory::field.free')" numeric value="0" />

                <x-ui.field name="trx_date" type="date" :label="__('inventory::field.date')"
                            :value="now()->toDateString()" />

                <x-ui.field name="narration" :label="__('inventory::field.narration')" />

                <x-ui.button type="submit" tone="primary">{{ __('core.action.save') }}</x-ui.button>
            </form>
        </section>

        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
            <div class="border-b border-(--color-border) px-4 py-3">
                <h2 class="font-semibold">{{ __('inventory::message.lot_waiting_title') }}</h2>
            </div>

            <x-ui.table
                :empty="__('inventory::message.lot_waiting_none')"
                :rows="$waiting"
                compact
                :columns="[
                    ['key' => 'product', 'label' => __('inventory::field.product'),
                     'render' => fn ($r) => $r->product_code . ' - '
                         . (app()->getLocale() === 'bn' && $r->name_bn ? $r->name_bn : $r->name_en)],
                    ['key' => 'warehouse', 'label' => __('inventory::field.warehouse'), 'width' => '10rem',
                     'render' => fn ($r) => app()->getLocale() === 'bn' && $r->warehouse_bn
                         ? $r->warehouse_bn : $r->warehouse_en],
                    ['key' => 'qty', 'label' => __('inventory::field.quantity'), 'numeric' => true, 'width' => '8rem',
                     'render' => fn ($r) => \App\Core\Support\Money::format($r->qty)],
                    ['key' => 'free_qty', 'label' => __('inventory::field.free'),
                     'numeric' => true, 'width' => '8rem',
                     'render' => fn ($r) => \App\Core\Support\Money::format($r->free_qty)],
                ]" />

            <x-ui.pager :rows="$waiting" />
        </section>
    </div>
</x-layouts.app>
