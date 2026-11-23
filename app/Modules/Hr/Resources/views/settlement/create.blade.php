{{--
    ⭐ নতুন চূড়ান্ত পাওনা — শেষ দিনের হিসাব আগে দেখা, তারপর মালিকের অনুমোদনে পাঠানো ([[FinalSettlementController::create()]])।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('hr::settlement.new') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('hr::settlement.new').' · '.$employee->label()" :subtitle="__('hr::settlement.info')" />
    </x-slot:header>

    @if ($errors->any())
        <div role="alert" class="mb-4 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm text-(--color-badge-danger-ink)">
            <ul class="list-inside list-disc">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <p class="mb-3 text-sm">{{ __('hr::settlement.last_day') }}: <strong>{{ $employee->leaving_date->format('d M Y') }}</strong></p>

    @include('hr::settlement.partials.figures', ['f' => $figures])

    <form method="POST" action="{{ route('hr.final_settlement.store') }}"
          class="grid gap-3 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4 lg:grid-cols-[14rem_1fr_auto] lg:items-end" data-boxed>
        @csrf
        <input type="hidden" name="employee_id" value="{{ $employee->id }}">
        <x-ui.select name="separation" :label="__('hr::settlement.separation')" required
                     :options="collect(\App\Modules\Hr\Models\FinalSettlement::SEPARATIONS)->mapWithKeys(fn ($s) => [$s => __('hr::settlement.separations.'.$s)])" />
        <x-ui.field name="note" :label="__('hr::settlement.note')" />
        <x-ui.button type="submit" tone="primary">{{ __('hr::settlement.prepare') }}</x-ui.button>
    </form>
</x-layouts.app>
