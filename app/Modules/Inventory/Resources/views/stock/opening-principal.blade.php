{{--
    আগে বসানো খোলা মজুদে প্রিন্সিপাল বসানো — মালিক, ৬ অক্টোবর ২০২৬ ([[OpeningPrincipalController]])।
    ⓘ শাখা (হেডারে) আর পণ্য ধরে তালিকা; অনেকগুলো বেছে একজন সরবরাহকারী। কেবল স্তরের "মালটা কার" ঘর বদলায় — মজুদ বা খাতা নয়।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('inventory::message.opening_principal_title') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('inventory::message.opening_principal_title')" />
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

    <p class="mb-3 max-w-(--spacing-prose-max) text-sm text-(--color-ink-muted)">
        {{ __('inventory::message.opening_principal_note') }}
    </p>

    <form method="GET" action="{{ route('inventory.stock.opening.principal') }}" class="mb-4 grid gap-3 sm:grid-cols-3">
        <x-ui.select name="product_id" :label="__('inventory::field.product')" :options="$products"
                     :selected="$productId ?: null" placeholder="—" />
        <x-ui.select name="supplier" :label="__('inventory::field.opening_principal')"
                     :options="['none' => __('inventory::message.opening_principal_only_unset')]"
                     :selected="$supplierFilter ?: null" placeholder="—" />
        <div class="flex items-end">
            <x-ui.button type="submit">{{ __('core.action.apply') }}</x-ui.button>
        </div>
    </form>

    <form method="POST" action="{{ route('inventory.stock.opening.principal.update') }}" data-opening-principal>
        @csrf

        <div class="mb-3 flex flex-wrap items-end gap-3">
            <x-ui.select name="supplier_id" :label="__('inventory::message.opening_principal_set_for')"
                         :options="$suppliers" placeholder="—" />
            <x-ui.button type="submit" tone="primary">{{ __('core.action.save') }}</x-ui.button>
        </div>

        @if ($rows->isEmpty())
            <p class="p-8 text-center text-(--color-ink-muted)">{{ __('inventory::message.opening_principal_empty') }}</p>
        @else
            <div class="overflow-x-auto rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
                <table class="ui-list w-full text-sm">
                    <thead>
                        <tr class="border-b border-(--color-border) text-left text-(--color-ink-muted)">
                            <th class="w-8"></th>
                            <th>{{ __('inventory::field.product') }}</th>
                            <th>{{ __('inventory::field.warehouse') }}</th>
                            <th>{{ __('inventory::field.batch_no') }}</th>
                            <th class="text-right">{{ __('core.print.qty') }}</th>
                            <th>{{ __('inventory::field.opening_principal') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr class="border-b border-(--color-border)/60">
                                <td><input type="checkbox" name="layer_ids[]" value="{{ $row->id }}"
                                           aria-label="{{ $row->product_code }}"></td>
                                <td>
                                    {{ app()->getLocale() === 'bn' && $row->name_bn ? $row->name_bn : $row->name_en }}
                                    <span class="block text-2xs text-(--color-ink-muted)">{{ $row->product_code }}</span>
                                </td>
                                <td>{{ $row->warehouse_name }}</td>
                                <td>{{ $row->batch_no ?? '—' }}</td>
                                <td class="num text-right">{{ rtrim(rtrim((string) $row->qty_in, '0'), '.') }}</td>
                                <td>{{ $row->supplier_id ? ($suppliers[(int) $row->supplier_id] ?? '—') : __('inventory::message.opening_principal_none') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </form>

    <x-ui.pager :rows="$rows" />
    <x-ui.list-totals :rows="$rows" :totals="[['label' => __('inventory::message.opening_principal_unset', ['count' => $unset]), 'value' => '']]" />
</x-layouts.app>
