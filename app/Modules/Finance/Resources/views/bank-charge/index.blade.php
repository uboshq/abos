{{--
    ব্যাংক চার্জ — মানচিত্র §৯। শিরোনাম · বর্ণনা → সময়ের ট্যাব → ব্যাংক ধরে
    মোট → প্রতিটা কাটা, তার কাগজের লিংকসহ।

    ⓘ প্রশ্নটা মালিকের পুরনো: *"mfs e charge kate"* — বিকাশে বছরে কত গেল।
    উত্তর এখন এক পাতায়, আর সংখ্যাগুলো খতিয়ান থেকে ([[BankCharges]])।
--}}
@php
    use App\Core\Support\Money;

    $tabs = collect(\App\Modules\Finance\Http\Controllers\BankChargeController::PERIODS)
        ->mapWithKeys(fn ($p) => [$p => __('finance::bank_charge.'.$p)]);

    /* ⓘ খাতের আইডি → নাম, উপরের "ব্যাংক ধরে" তালিকা থেকেই — সারি প্রতি
       আলাদা প্রশ্ন করলে পঞ্চাশ সারিতে পঞ্চাশটা হত। */
    $banks = $byBank->mapWithKeys(fn ($b) => [$b['bank']?->id ?? 0 => $b['bank']?->label()])->filter();

    /* ⚠️ সময়ের ঘরগুলো প্রতিটা লিংকে সাথে যায় — নাহলে "৭ বার"-এ চেপে
       অন্য মাসের সারি আসত, আর সংখ্যাটা মিলত না। */
    $keep = array_filter([
        'period' => $period,
        'from' => request()->query('from'),
        'to' => request()->query('to'),
    ], fn ($v) => filled($v));
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('finance::bank_charge.title') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('finance::bank_charge.title')"
                          :subtitle="__('finance::bank_charge.subtitle')" />
    </x-slot:header>

    <nav class="mb-3 flex flex-wrap items-end gap-1 border-b border-(--color-border) text-sm"
         aria-label="{{ __('finance::bank_charge.title') }}">
        @foreach ($tabs as $key => $label)
            @continue($key === 'custom')
            @php $on = $period === $key; @endphp
            <a href="{{ route('finance.bank_charge.index', $key === 'this_month' ? [] : ['period' => $key]) }}"
               @if ($on) aria-current="page" @endif
               class="-mb-px flex min-h-(--spacing-touch) items-center border-b-2 px-3
                      {{ $on
                          ? 'border-(--color-brand-500) font-semibold text-(--color-ink)'
                          : 'border-transparent text-(--color-ink-muted) hover:text-(--color-ink)' }}">
                {{ $label }}
            </a>
        @endforeach

        {{-- ⓘ তারিখ বেছে — ট্যাবের পাশে, আলাদা পাতায় নয় --}}
        <form method="GET" action="{{ route('finance.bank_charge.index') }}"
              class="ms-auto flex flex-wrap items-end gap-2 pb-1 {{ $period === 'custom' ? 'font-semibold' : '' }}">
            <input type="hidden" name="period" value="custom">
            <label class="text-2xs text-(--color-ink-muted)">
                {{ __('finance::bank_charge.from') }}
                <input type="date" name="from" value="{{ $from }}"
                       class="block h-8 rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
            </label>
            <label class="text-2xs text-(--color-ink-muted)">
                {{ __('finance::bank_charge.to') }}
                <input type="date" name="to" value="{{ $to }}"
                       class="block h-8 rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
            </label>
            <x-ui.button type="submit" tone="secondary">{{ __('finance::bank_charge.show') }}</x-ui.button>
        </form>
    </nav>

    <section data-boxed
             class="mb-4 overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <header class="flex flex-wrap items-baseline gap-x-3 border-b border-(--color-border) px-4 py-2">
            <h2 class="text-sm font-semibold">{{ __('finance::bank_charge.by_bank') }}</h2>
            <span class="text-2xs text-(--color-ink-muted)">{{ $from }} → {{ $to }}</span>
            <span class="ms-auto text-sm tabular-nums">
                {{ __('finance::bank_charge.total') }}: <strong>{{ Money::format($total) }}</strong>
            </span>
        </header>

        {{-- ⭐ চারটা ঘরই এখন কোথাও নিয়ে যায় — ২০ সেপ্টেম্বর ২০২৬।

             মালিকের কথা: *"সব জায়গায় হাইপার লিংক দেওয়ার কথা"*। ⓘ এই টেবিলের
             প্রতিটা ঘর একটা প্রশ্ন: খাতটা কী (→ খতিয়ান), ব্যাংকটা কে (→
             প্রতিষ্ঠান), আর "৭ বার" মানে কোন সাতটা (→ নিচের তালিকা, ঐ
             ব্যাংকে ছাঁকা)। --}}
        <x-ui.table :rows="$byBank" :empty="__('finance::bank_charge.none')" :columns="[
            ['key' => 'bank', 'label' => __('finance::bank_charge.bank'),
             'render' => fn ($b) => view('finance::bank-charge.partials.bank-link', [
                 'id' => $b['bank']?->id, 'label' => $b['bank']?->label() ?? '',
             ])],
            ['key' => 'institution', 'label' => __('finance::bank_charge.institution'), 'width' => '14rem',
             'render' => fn ($b) => view('finance::institution.partials.link', [
                 'id' => $b['institution_id'], 'label' => $b['institution'] ?? '—',
             ])],
            ['key' => 'count', 'label' => __('finance::bank_charge.times'), 'numeric' => true, 'width' => '6rem',
             'render' => fn ($b) => view('finance::bank-charge.partials.count-link', [
                 'bank' => $b['bank'], 'keep' => $keep, 'text' => $b['count'],
             ])],
            ['key' => 'amount', 'label' => __('finance::bank_charge.amount'), 'numeric' => true, 'width' => '10rem',
             'render' => fn ($b) => view('finance::bank-charge.partials.count-link', [
                 'bank' => $b['bank'], 'keep' => $keep, 'text' => Money::format($b['amount']),
             ])],
        ]" />
    </section>

    {{-- ⚠️ ছাঁকনি চালু থাকলে সেটা লেখা থাকে, আর ফেরার পথও — নাহলে কেউ
         ভাবতেন এই মাসে এতগুলোই কাটা হয়েছে (২০ সেপ্টেম্বর ২০২৬)। --}}
    @if ($bank_id && isset($banks[$bank_id]))
        <p class="mb-2 flex flex-wrap items-center gap-2 text-sm">
            <span>{{ __('finance::bank_charge.only_this_bank', ['name' => $banks[$bank_id]]) }}</span>
            <a href="{{ route('finance.bank_charge.index', $keep) }}"
               class="text-(--color-brand-600) underline-offset-2 hover:underline">
                {{ __('finance::bank_charge.show_all_banks') }}
            </a>
        </p>
    @endif

    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <x-ui.table :rows="$rows" :empty="__('finance::bank_charge.none')" :columns="[
            ['key' => 'date', 'label' => __('finance::bank_charge.date'), 'width' => '7rem',
             'render' => fn ($e) => $e->trx_date?->format('d M Y')],
            ['key' => 'document', 'label' => __('finance::bank_charge.document'), 'width' => '9rem',
             'render' => fn ($e) => view('finance::bank-charge.partials.drill', ['entry' => $e])],
            ['key' => 'bank', 'label' => __('finance::bank_charge.bank'), 'width' => '14rem',
             'render' => fn ($e) => view('finance::bank-charge.partials.bank-link', [
                 'id' => $e->bank_id, 'label' => $banks[$e->bank_id] ?? '',
             ])],
            ['key' => 'narration', 'label' => __('finance::bank_charge.narration'),
             'render' => fn ($e) => $e->narration ?: '—'],
            ['key' => 'amount', 'label' => __('finance::bank_charge.amount'), 'numeric' => true, 'width' => '9rem',
             'render' => fn ($e) => Money::format(bcsub((string) $e->debit, (string) $e->credit, 4))],
        ]" />

        <x-ui.pager :rows="$rows" />
    </div>
</x-layouts.app>
