{{--
    ডিলারের মাসিক আদায়ের লক্ষ্য — মালিক, ৩ অক্টোবর ২০২৬ ([[CustomerTargetService]])।

    ⓘ পুরো ছকটা একটা ফর্ম — মাসের শুরুতে সবার লক্ষ্য একবারে বসিয়ে একবার সংরক্ষণ। অর্জন খাতা থেকে গোনা
    (আদায়, রসিদ, পাশ হওয়া চেক), হাতে লেখা নয়। ফাঁকা বা ০ মানে ঐ মাসে ঐ ডিলারের লক্ষ্য নেই — বিলে বাক্সও নেই।
--}}
@php
    use App\Core\Support\Money;

    $canManage = auth()->user()?->can('sales.customer_target.manage') ?? false;
    $input = 'h-(--spacing-field-compact) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2';
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('sales::customer_target.title') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('sales::customer_target.title')" :subtitle="__('sales::customer_target.subtitle')">
            <x-slot:actions>
                <form method="GET" class="flex items-end gap-2">
                    <label class="text-sm">
                        <span class="mb-1 block text-(--color-ink-muted)">{{ __('sales::customer_target.month') }}</span>
                        <x-ui.month name="month" :value="$month->format('Y-m')" :class="$input" />
                    </label>
                    <x-ui.button type="submit" tone="secondary">{{ __('core.action.apply') }}</x-ui.button>
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

    <form method="POST" action="{{ route('sales.customer_target.store') }}">
        @csrf
        <input type="hidden" name="month" value="{{ $month->toDateString() }}">

        <div data-boxed class="overflow-x-auto rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
            <table class="ui-grid w-full text-sm" data-customer-targets>
                <thead>
                    <tr class="border-b border-(--color-border) text-start text-(--color-ink-muted)">
                        <th class="text-start">{{ __('sales::customer_target.dealer') }}</th>
                        <th class="text-end">{{ __('sales::customer_target.target') }}</th>
                        <th class="text-start">{{ __('sales::customer_target.closes_on') }}</th>
                        <th class="text-end">{{ __('sales::customer_target.achieved') }}</th>
                        <th class="text-end">{{ __('sales::customer_target.remaining') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        @php
                            $c = $row['customer'];
                            $left = $row['target'] === null ? null : (bccomp($row['achieved'], $row['target'], 4) >= 0 ? '0' : bcsub($row['target'], $row['achieved'], 4));
                        @endphp
                        <tr class="border-b border-(--color-border)" data-target-row="{{ $c->code }}">
                            <td>{{ $c->code }} · {{ $c->name() }}</td>
                            <td class="text-end">
                                @if ($canManage)
                                    <input type="number" step="0.01" min="0" inputmode="decimal" name="target[{{ $c->id }}][amount]"
                                           value="{{ $row['target'] !== null ? rtrim(rtrim($row['target'], '0'), '.') : '' }}"
                                           aria-label="{{ __('sales::customer_target.target') }} — {{ $c->name() }}"
                                           class="num w-32 text-end {{ $input }}">
                                @else
                                    <span class="tabular">{{ $row['target'] === null ? '—' : Money::format($row['target']) }}</span>
                                @endif
                            </td>
                            <td>
                                @if ($canManage)
                                    <x-ui.date dense :name="'target['.$c->id.'][closes_on]'" :id="'closes-on-'.$c->id" :value="$row['closes_on']"
                                               :min="$month->toDateString()" :max="$month->copy()->endOfMonth()->toDateString()"
                                               aria-label="{{ __('sales::customer_target.closes_on') }} — {{ $c->name() }}" />
                                @else
                                    {{ $row['closes_on'] ?? '—' }}
                                @endif
                            </td>
                            <td class="text-end tabular" data-achieved>{{ Money::format($row['achieved']) }}</td>
                            <td class="text-end tabular">{{ $left === null ? '—' : Money::format((string) $left) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-3 py-6 text-center text-(--color-ink-muted)">{{ __('sales::customer_target.nobody') }}</td></tr>
                    @endforelse
                </tbody>

                {{-- ⭐ সর্বমোট — ওয়েব-মান ধাপ ২, ২০ অক্টোবর ২০২৬; লক্ষ্য না বসানো ডিলার লক্ষ্য আর বাকির যোগে নেই --}}
                @if ($rows !== [])
                    @php
                        $sum = ['target' => '0', 'achieved' => '0', 'left' => '0'];
                        foreach ($rows as $r) {
                            $sum['achieved'] = bcadd($sum['achieved'], (string) $r['achieved'], 4);
                            if ($r['target'] !== null) {
                                $sum['target'] = bcadd($sum['target'], (string) $r['target'], 4);
                                $sum['left'] = bcadd($sum['left'], bccomp($r['achieved'], $r['target'], 4) >= 0 ? '0' : bcsub($r['target'], $r['achieved'], 4), 4);
                            }
                        }
                    @endphp
                    <tfoot data-grand-total>
                        <tr class="font-semibold">
                            <td>{{ __('core.table.grand_total') }}</td>
                            <td class="text-end tabular">{{ Money::format($sum['target']) }}</td>
                            <td></td>
                            <td class="text-end tabular">{{ Money::format($sum['achieved']) }}</td>
                            <td class="text-end tabular">{{ Money::format($sum['left']) }}</td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>

        @if ($canManage && $rows !== [])
            <p class="mt-2 text-xs text-(--color-ink-muted)">{{ __('sales::customer_target.empty_means_none') }}</p>
            <div class="mt-3">
                <x-ui.button type="submit" tone="primary">{{ __('core.action.save') }}</x-ui.button>
            </div>
        @endif
    </form>
</x-layouts.app>
