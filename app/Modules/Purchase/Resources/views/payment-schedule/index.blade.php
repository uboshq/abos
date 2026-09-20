{{--
    পরিশোধের সময়সূচি — অর্থের মানচিত্র §৬। শিরোনাম · বর্ণনা → ভাগের ট্যাব
    (প্রতিটায় কয়টা আর কত) → বিলের তালিকা, শেষ তারিখ ধরে।
    ⓘ কেন ক্রয়ে: [[PaymentScheduleController]]-এর মাথায়।
--}}
@php
    use App\Core\Support\Money;
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('purchase::schedule.title') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('purchase::schedule.title')"
                          :subtitle="__('purchase::schedule.subtitle')" />
    </x-slot:header>

    <nav class="mb-3 flex flex-wrap gap-1 border-b border-(--color-border) text-sm"
         aria-label="{{ __('purchase::schedule.title') }}">
        @foreach (\App\Modules\Purchase\Http\Controllers\PaymentScheduleController::TABS as $key)
            @php $on = $tab === $key; @endphp
            <a href="{{ route('purchase.payment_schedule.index', $key === 'all' ? [] : ['tab' => $key]) }}"
               @if ($on) aria-current="page" @endif
               class="-mb-px flex min-h-(--spacing-touch) flex-col justify-center border-b-2 px-3 py-1
                      {{ $on
                          ? 'border-(--color-brand-500) font-semibold text-(--color-ink)'
                          : 'border-transparent text-(--color-ink-muted) hover:text-(--color-ink)' }}
                      {{ $key === 'overdue' && $buckets[$key]['count'] > 0 ? 'text-(--color-badge-danger-ink)' : '' }}">
                <span>{{ __('purchase::schedule.'.$key) }} · {{ $buckets[$key]['count'] }}</span>
                <span class="text-2xs tabular-nums">{{ Money::format($buckets[$key]['amount']) }}</span>
            </a>
        @endforeach
    </nav>

    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <x-ui.table :rows="$rows" :empty="__('purchase::schedule.none')" :columns="[
            ['key' => 'due', 'label' => __('purchase::schedule.due_on'), 'width' => '9rem',
             'render' => fn ($bill) => view('purchase::payment-schedule.partials.when',
                 ['bill' => $bill, 'today' => $today])],
            ['key' => 'bill', 'label' => __('purchase::schedule.bill'), 'width' => '10rem',
             'render' => fn ($bill) => view('purchase::payment-schedule.partials.bill', ['bill' => $bill])],
            ['key' => 'supplier', 'label' => __('purchase::schedule.supplier'),
             'render' => fn ($bill) => view('purchase::payment-schedule.partials.supplier', ['bill' => $bill])],
            ['key' => 'total', 'label' => __('purchase::schedule.total'), 'numeric' => true, 'width' => '9rem',
             'render' => fn ($bill) => Money::format($bill->total)],
            ['key' => 'amount', 'label' => __('purchase::schedule.due'), 'numeric' => true, 'width' => '9rem',
             'render' => fn ($bill) => Money::format($bill->dueAmount())],
            ['key' => 'pay', 'label' => '', 'width' => '7rem',
             'render' => fn ($bill) => view('purchase::payment-schedule.partials.pay', ['bill' => $bill])],
        ]" />

        <x-ui.pager :rows="$rows" />
    </div>
</x-layouts.app>
