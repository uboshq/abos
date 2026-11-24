{{--
    পণ্যের মাসিক বিক্রির লক্ষ্য — মালিক, ২৩ নভেম্বর ২০২৬ ([[ProductTargetService]])।

    ⓘ পুরো ছকটা একটা ফর্ম — মাসের শুরুতে সব পণ্যের লক্ষ্য একবারে বসিয়ে একবার সংরক্ষণ (ডিলারের লক্ষ্যের পাতার একই ছাঁচ)। অর্জন বিলের
    লাইন থেকে গোনা, হাতে লেখা নয়। দুই ঘরই ফাঁকা বা ০ মানে ঐ মাসে ঐ পণ্যের লক্ষ্য নেই।
    ⛔ পাতার মাথায় বিবরণের লাইন নেই (মালিক, ১৯ নভেম্বর ২০২৬) — শিরোনাম আর মাস বাছাই এক সারিতে।
--}}
@php
    use App\Core\Support\Money;

    $canManage = auth()->user()?->can('sales.target.manage') ?? false;
    $input = 'h-(--spacing-field-compact) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2';
    $rate = fn (string $got, ?string $aim): ?string => $aim !== null && bccomp($aim, '0', 4) > 0 ? bcdiv(bcmul($got, '100', 6), $aim, 1) : null;
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('sales::product_target.title') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('sales::product_target.title')">
            <x-slot:actions>
                <form method="GET" class="flex items-end gap-2">
                    <label class="text-sm">
                        <span class="sr-only">{{ __('sales::product_target.month') }}</span>
                        <x-ui.month name="month" :value="$month->format('Y-m')" :class="$input" />
                    </label>
                    <x-ui.button type="submit" tone="secondary">{{ __('core.action.show') }}</x-ui.button>
                </form>
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>

    @if (session('saved'))
        <div role="status" class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    @if ($errors->any())
        <div role="alert" class="mb-4 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm text-(--color-badge-danger-ink)">
            {{ $errors->first() }}
        </div>
    @endif

    <form method="POST" action="{{ route('sales.product_target.store') }}">
        @csrf
        <input type="hidden" name="month" value="{{ $month->toDateString() }}">

        <div data-boxed class="overflow-x-auto rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
            <table class="ui-grid w-full text-sm" data-product-targets>
                <thead>
                    <tr class="border-b border-(--color-border) text-start text-(--color-ink-muted)">
                        <th class="text-start">{{ __('sales::product_target.product') }}</th>
                        <th class="text-end">{{ __('sales::product_target.target_qty') }}</th>
                        <th class="text-end">{{ __('sales::product_target.sold_qty') }}</th>
                        <th class="text-end">{{ __('sales::product_target.qty_achieved') }}</th>
                        <th class="text-end">{{ __('sales::product_target.target_amount') }}</th>
                        <th class="text-end">{{ __('sales::product_target.sold_amount') }}</th>
                        <th class="text-end">{{ __('sales::product_target.amount_achieved') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        @php
                            $p = $row['product'];
                            $qtyRate = $rate($row['sold_qty'], $row['qty']);
                            $amountRate = $rate($row['sold_amount'], $row['amount']);
                            $shown = fn (?string $v) => $v !== null && bccomp($v, '0', 4) !== 0 ? rtrim(rtrim($v, '0'), '.') : '';
                        @endphp
                        <tr class="border-b border-(--color-border)" data-target-row="{{ $p->code }}">
                            <td>{{ $p->code }} · {{ $p->name() }}</td>
                            <td class="text-end">
                                @if ($canManage)
                                    <input type="number" step="0.0001" min="0" inputmode="decimal" name="target[{{ $p->id }}][qty]"
                                           value="{{ $shown($row['qty']) }}"
                                           aria-label="{{ __('sales::product_target.target_qty') }} — {{ $p->name() }}"
                                           class="num w-28 text-end {{ $input }}">
                                @else
                                    <span class="tabular">{{ $shown($row['qty']) === '' ? '—' : Money::quantity($row['qty']) }}</span>
                                @endif
                            </td>
                            <td class="text-end tabular" data-sold-qty>{{ Money::quantity($row['sold_qty']) }}</td>
                            <td class="text-end tabular">{{ $qtyRate === null ? '—' : $qtyRate.'%' }}</td>
                            <td class="text-end">
                                @if ($canManage)
                                    <input type="number" step="0.01" min="0" inputmode="decimal" name="target[{{ $p->id }}][amount]"
                                           value="{{ $shown($row['amount']) }}"
                                           aria-label="{{ __('sales::product_target.target_amount') }} — {{ $p->name() }}"
                                           class="num w-32 text-end {{ $input }}">
                                @else
                                    <span class="tabular">{{ $shown($row['amount']) === '' ? '—' : Money::format($row['amount']) }}</span>
                                @endif
                            </td>
                            <td class="text-end tabular" data-sold-amount>{{ Money::format($row['sold_amount']) }}</td>
                            <td class="text-end tabular">{{ $amountRate === null ? '—' : $amountRate.'%' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-3 py-6 text-center text-(--color-ink-muted)">{{ __('sales::product_target.nothing') }}</td></tr>
                    @endforelse
                </tbody>

                {{-- ⭐ সর্বমোট — টাকার দুই ঘর আর তার হার; পরিমাণ যোগ হয় না (ভিন্ন পণ্যের ভিন্ন একক) --}}
                @if ($rows !== [])
                    @php
                        $sum = ['amount' => '0', 'sold' => '0'];
                        foreach ($rows as $r) {
                            $sum['sold'] = bcadd($sum['sold'], $r['sold_amount'], 4);
                            $sum['amount'] = bcadd($sum['amount'], (string) ($r['amount'] ?? '0'), 4);
                        }
                        $sumRate = $rate($sum['sold'], $sum['amount']);
                    @endphp
                    <tfoot data-grand-total>
                        <tr class="font-semibold">
                            <td>{{ __('core.table.grand_total') }}</td>
                            <td></td>
                            <td></td>
                            <td></td>
                            <td class="text-end tabular">{{ Money::format($sum['amount']) }}</td>
                            <td class="text-end tabular">{{ Money::format($sum['sold']) }}</td>
                            <td class="text-end tabular">{{ $sumRate === null ? '—' : $sumRate.'%' }}</td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>

        @if ($canManage && $rows !== [])
            <div class="mt-3">
                <x-ui.button type="submit" tone="primary">{{ __('core.action.save') }}</x-ui.button>
            </div>
        @endif
    </form>
</x-layouts.app>
