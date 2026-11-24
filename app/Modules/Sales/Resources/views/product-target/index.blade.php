{{--
    পণ্যের মাসিক বিক্রির লক্ষ্য — মালিক, ২৩ নভেম্বর ২০২৬ ([[ProductTargetService]])।

    ⓘ পুরো ছকটা একটা ফর্ম — মাসের শুরুতে সব পণ্যের লক্ষ্য একবারে বসিয়ে একবার সংরক্ষণ (ডিলারের লক্ষ্যের পাতার একই ছাঁচ)। অর্জন বিলের
    লাইন থেকে গোনা, হাতে লেখা নয়। দুই ঘরই ফাঁকা বা ০ মানে ঐ মাসে ঐ পণ্যের লক্ষ্য নেই।
    ⛔ পাতার মাথায় বিবরণের লাইন নেই (মালিক, ১৯ নভেম্বর ২০২৬) — শিরোনাম আর মাস বাছাই এক সারিতে।
--}}
@php
    use App\Core\Support\Money;

    // ⓘ সংরক্ষণ থামলে যা লেখা হয়েছিল তাই ফেরে — খালি করা ঘরও
    $typed = is_array(old('target')) ? old('target') : null;
    $canManage = auth()->user()?->can('sales.target.manage') ?? false;
    $input = 'h-(--spacing-field-compact) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2';
    $rate = fn (string $got, ?string $aim): ?string => $aim !== null && bccomp($aim, '0', 4) > 0 ? bcdiv(bcmul($got, '100', 6), $aim, 1) : null;
@endphp

@php
    // ⭐ এক সারির মাথা কেবল তিন ABOS রূপে; মাস ছাঁকনির প্যানেলে, সংরক্ষণ বারে (মালিক, ২৪ নভেম্বর ২০২৬)
    // ⓘ পাতায় পাতায়; খোঁজা সব পণ্যে, Enter-এ (লিখতে লিখতে নয়)। না-সংরক্ষিত লেখা থাকলে খোঁজা, পাতা বা মাস বদলের আগে ব্রাউজার জিজ্ঞেস করে
    //   ([[day-sheet.js]]); সংরক্ষণ কেবল এই পাতার সারি ছোঁয় ([[ProductTargetService::setForMonth()]])
    $abosHead = \App\Core\Support\AbosHead::on();
    $facetPanel = \App\Core\ListFilter\Facets::panel($abosHead ? [\App\Core\ListFilter\Facets::month('month', __('sales::product_target.month'), $month->format('Y-m'))] : []);
@endphp
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('sales::product_target.title') }}</x-slot:title>

    @unless ($abosHead)
    <x-slot:header>
        <x-ui.page-header :title="__('sales::product_target.title')">
            <x-slot:actions>
                <form method="GET" class="flex items-end gap-2">
                    <label class="text-sm">
                        <span class="sr-only">{{ __('sales::product_target.month') }}</span>
                        <x-ui.month name="month" :value="$month->format('Y-m')" :class="$input" />
                    </label>
                    <label class="text-sm">
                        <span class="sr-only">{{ __('sales::product_target.product') }}</span>
                        <input type="search" name="q" maxlength="60" value="{{ $q }}" placeholder="{{ __('sales::product_target.product') }}" class="{{ $input }}">
                    </label>
                    <x-ui.button type="submit" tone="secondary">{{ __('core.action.show') }}</x-ui.button>
                </form>
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>
    @endunless

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

    <div @if ($abosHead) x-data="daySheet" data-sheet-form="target-form" data-sheet-rows="target[" @endif>
    <x-ui.filter-layout :filters="$facetPanel" form="list-form">
    @if ($abosHead)
        <form id="list-form" method="GET" class="contents">
            <x-ui.toolbar :title="__('sales::product_target.title')" :search="false" :export="false" :print="false" :share="false" :refresh="false" :facets="$facetPanel">
                <x-slot:find>
                    <input type="search" name="q" maxlength="60" value="{{ $q }}" autocomplete="off" enterkeyhint="search"
                           placeholder="{{ __('sales::product_target.product') }}" aria-label="{{ __('sales::product_target.product') }}" data-target-find
                           class="h-(--spacing-field-compact) w-full rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-2 text-sm">
                </x-slot:find>
                @if ($canManage && $rows->isNotEmpty())
                    <x-slot:actions>
                        <x-ui.button type="submit" form="target-form" tone="primary">{{ __('core.action.save') }}</x-ui.button>
                    </x-slot:actions>
                @endif
            </x-ui.toolbar>
        </form>
    @endif
    <form id="target-form" @if ($errors->any()) data-unsaved @endif @if ($abosHead) x-on:submit="saved()" @endif method="POST" action="{{ route('sales.product_target.store') }}">
        @csrf
        <input type="hidden" name="month" value="{{ $month->toDateString() }}">
        <input type="hidden" name="q" value="{{ $q }}">
        <input type="hidden" name="page" value="{{ $rows->currentPage() }}">

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
                        <tr class="border-b border-(--color-border)" data-target-row="{{ $p->code }}" @if ($abosHead) data-day-row @endif>
                            <td>{{ $p->code }} · {{ $p->name() }}</td>
                            <td class="text-end">
                                @if ($canManage)
                                    <input type="number" step="0.0001" min="0" inputmode="decimal" name="target[{{ $p->id }}][qty]"
                                           value="{{ $typed !== null ? ($typed[$p->id]['qty'] ?? '') : $shown($row['qty']) }}"
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
                                           value="{{ $typed !== null ? ($typed[$p->id]['amount'] ?? '') : $shown($row['amount']) }}"
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

                {{-- ⭐ সর্বমোট — গোটা মাসের, পাতা বা খোঁজা যা-ই হোক ([[ProductTargetService::monthTotals()]]); টাকার দুই ঘর আর তার হার; পরিমাণ যোগ হয় না (ভিন্ন পণ্যের ভিন্ন একক) --}}
                @if ($rows->isNotEmpty())
                    @php
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

        @if ($canManage && $rows->isNotEmpty())
            @unless ($abosHead)
            <div class="mt-3">
                <x-ui.button type="submit" tone="primary">{{ __('core.action.save') }}</x-ui.button>
            </div>
            @endunless
        @endif
    </form>
    <x-ui.pager :rows="$rows" />
    <x-ui.list-totals :rows="$rows" />
    </x-ui.filter-layout>
    </div>
</x-layouts.app>
