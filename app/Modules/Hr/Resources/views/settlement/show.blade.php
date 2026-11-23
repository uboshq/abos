{{--
    ⭐ চূড়ান্ত পাওনার কাগজ — অঙ্ক, অবস্থা, আর মালিকের "অনুমোদন দিন" / "প্রত্যাখ্যান করুন" ([[FinalSettlementController::show()]])।
    ⓘ বোতাম কেবল তাঁর, যাঁকে ইঞ্জিন অনুমোদন দিতে দেয় ([[FinalSettlements::canDecide()]]) — ইনবক্সের একই প্রশ্ন।
--}}
@php
    $f = [
        'wages' => $settlement->wages, 'leave_encashment' => $settlement->leave_encashment, 'gratuity' => $settlement->gratuity,
        'provident_fund' => $settlement->provident_fund, 'advance_recovered' => $settlement->advance_recovered,
        'earnings' => $settlement->earnings(), 'net' => $settlement->net, 'details' => $settlement->details,
    ];
@endphp
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('hr::settlement.title') }} · {{ $settlement->employee?->label() }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('hr::settlement.title').' · '.($settlement->employee?->label() ?? '—')" :subtitle="__('hr::settlement.info')" />
    </x-slot:header>

    @if (session('saved'))
        <div role="status" class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    @if ($errors->any())
        <div role="alert" class="mb-4 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm text-(--color-badge-danger-ink)">
            <ul class="list-inside list-disc">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <dl class="mb-4 grid gap-x-6 gap-y-1 text-sm sm:grid-cols-4" data-settlement-head>
        <div><dt class="text-(--color-ink-muted)">{{ __('hr::settlement.last_day') }}</dt><dd>{{ $settlement->last_day->format('d M Y') }}</dd></div>
        <div><dt class="text-(--color-ink-muted)">{{ __('hr::settlement.separation') }}</dt><dd>{{ __('hr::settlement.separations.'.$settlement->separation) }}</dd></div>
        <div><dt class="text-(--color-ink-muted)">{{ __('hr::settlement.requested_by') }}</dt><dd>{{ $settlement->requester?->name ?? '—' }}</dd></div>
        <div><dt class="text-(--color-ink-muted)">{{ __('hr::settlement.status') }}</dt><dd data-settlement-status="{{ $settlement->status }}">{{ __('hr::settlement.statuses.'.$settlement->status) }}</dd></div>
        @if (filled($settlement->note))
            <div class="sm:col-span-4"><dt class="text-(--color-ink-muted)">{{ __('hr::settlement.note') }}</dt><dd>{{ $settlement->note }}</dd></div>
        @endif
    </dl>

    @include('hr::settlement.partials.figures', ['f' => $f])

    @if ($canDecide)
        <div class="flex flex-wrap items-end gap-3" data-settlement-decide>
            <form method="POST" action="{{ route('hr.final_settlement.approve', $settlement) }}">
                @csrf
                <x-ui.button type="submit" tone="primary">{{ __('hr::settlement.approve') }}</x-ui.button>
            </form>
            <form method="POST" action="{{ route('hr.final_settlement.reject', $settlement) }}" class="flex flex-wrap items-end gap-2">
                @csrf
                <x-ui.field name="reason" :label="__('hr::settlement.reject_reason')" required />
                <x-ui.button type="submit" tone="danger">{{ __('hr::settlement.reject') }}</x-ui.button>
            </form>
        </div>
    @endif
</x-layouts.app>
