{{--
    ডেলিভারি ট্র্যাকিং — প্রতিটা বিক্রি এখন কোথায় (মালিক, ২ অক্টোবর ২০২৬: *"Delivery Traking"*, ফোন আর ওয়েব দুই জায়গায়)।

    ⓘ ফোনের সাথে একই হিসাব ([[SaleTracking]]) — দুই জায়গায় দুই রকম ধাপ কখনো নয়।
    ⓘ মালিকের নিয়ম: টেবিল নয়, এক লাইনে এক জিনিস; নম্বরে চাপলে পপ-আপে সময়রেখা ([[shell/peek]])।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('sales::tracking.title') }}</x-slot:title>

    <div class="mx-auto grid max-w-3xl gap-3">
        <form method="GET" action="{{ route('sales.tracking.index') }}" class="flex flex-wrap gap-2">
            @if ($step)
                <input type="hidden" name="step" value="{{ $step }}">
            @endif
            <input type="search" name="q" value="{{ $q }}" placeholder="{{ __('sales::tracking.search') }}"
                   class="h-(--spacing-field) min-w-0 flex-1 rounded-(--radius-field) border border-(--color-border)
                          bg-(--color-surface-app) px-3">
            <x-ui.button type="submit">{{ __('sales::tracking.find') }}</x-ui.button>
        </form>

        <div class="flex flex-wrap gap-2" data-tracking-steps>
            @foreach (['all', ...\App\Modules\Sales\Services\SaleTracking::STEPS] as $key)
                @php $on = ($step ?? 'all') === $key; @endphp
                <a href="{{ route('sales.tracking.index', array_filter(['step' => $key === 'all' ? null : $key, 'q' => $q])) }}"
                   data-no-peek
                   @class([
                       'rounded-(--radius-field) border px-3 py-1.5 text-sm',
                       'border-(--color-brand-500) text-(--color-brand-500)' => $on,
                       'border-(--color-border)' => ! $on,
                   ])>
                    {{ $key === 'all' ? __('sales::tracking.all') : __('sales::tracking.step.'.$key) }}
                    ({{ $list['counts'][$key] ?? 0 }})
                </a>
            @endforeach
        </div>

        @forelse ($list['rows'] as $row)
            <div data-tracking-row class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-3">
                <a href="{{ route('sales.tracking.show', [$row['kind'], $row['id']]) }}"
                   class="font-semibold text-(--color-brand-500) hover:underline">{{ $row['no'] }}</a>
                <div class="text-sm">{{ $row['customer'] }}</div>
                <div class="text-sm text-(--color-ink-muted)">{{ $row['date'] }}</div>
                <div class="num text-sm">{{ \App\Core\Support\Money::format($row['total']) }}</div>
                <div class="mt-1 flex flex-wrap gap-2 text-sm">
                    <span class="font-medium">{{ __('sales::tracking.step.'.$row['step']) }}</span>
                    @if ($row['billed'])
                        <span class="text-(--color-ink-muted)">· {{ __('sales::tracking.step.billed') }}</span>
                    @endif
                </div>
            </div>
        @empty
            <p class="text-sm text-(--color-ink-muted)">{{ __('sales::tracking.empty') }}</p>
        @endforelse
    </div>
</x-layouts.app>
