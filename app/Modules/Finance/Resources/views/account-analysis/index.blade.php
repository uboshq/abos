{{--
    খাত বিশ্লেষণ — মানচিত্র §৪। খাত আর সময় বাছা → মাস ধরে · কাগজের ধরন
    ধরে · পক্ষ ধরে। ⓘ প্রতিটা মাস খতিয়ানে নামে (নিয়ম ১), ঐ খাত আর ঐ
    মাসের তারিখ নিয়ে।
--}}
@php
    use App\Core\Support\Money;
    use Illuminate\Support\Facades\Lang;

    $tabs = collect(\App\Modules\Finance\Http\Controllers\AccountAnalysisController::PERIODS)
        ->reject(fn ($p) => $p === 'custom')
        ->mapWithKeys(fn ($p) => [$p => __('finance::account_analysis.'.$p)]);

    $keep = $account ? ['account_id' => $account->id] : [];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('finance::account_analysis.title') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('finance::account_analysis.title')"
                          :subtitle="__('finance::account_analysis.subtitle')" />
    </x-slot:header>

    <form method="GET" action="{{ route('finance.account_analysis.index') }}"
          class="mb-3 flex flex-wrap items-end gap-2">
        <div class="min-w-72">
            <x-ui.select name="account_id" :label="__('finance::account_analysis.account')"
                         :options="$accounts" :selected="$account?->id" placeholder="—" />
        </div>
        <input type="hidden" name="period" value="custom">
        <label class="text-2xs text-(--color-ink-muted)">
            {{ __('finance::account_analysis.from') }}
            <input type="date" name="from" value="{{ $from }}"
                   class="block h-(--spacing-field) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
        </label>
        <label class="text-2xs text-(--color-ink-muted)">
            {{ __('finance::account_analysis.to') }}
            <input type="date" name="to" value="{{ $to }}"
                   class="block h-(--spacing-field) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
        </label>
        <x-ui.button type="submit" tone="primary">{{ __('finance::account_analysis.show') }}</x-ui.button>
    </form>

    <nav class="mb-3 flex flex-wrap gap-1 border-b border-(--color-border) text-sm"
         aria-label="{{ __('finance::account_analysis.title') }}">
        @foreach ($tabs as $key => $label)
            @php $on = $period === $key; @endphp
            <a href="{{ route('finance.account_analysis.index', $keep + ($key === 'this_year' ? [] : ['period' => $key])) }}"
               @if ($on) aria-current="page" @endif
               class="-mb-px flex min-h-(--spacing-touch) items-center border-b-2 px-3
                      {{ $on
                          ? 'border-(--color-brand-500) font-semibold text-(--color-ink)'
                          : 'border-transparent text-(--color-ink-muted) hover:text-(--color-ink)' }}">
                {{ $label }}
            </a>
        @endforeach
    </nav>

    @if ($report === null)
        <p class="rounded-(--radius-card) border border-dashed border-(--color-border) px-4 py-6 text-sm text-(--color-ink-muted)">
            {{ __('finance::account_analysis.pick_account') }}
        </p>
    @else
        <section data-boxed
                 class="mb-4 overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
            <header class="flex flex-wrap items-baseline gap-x-4 gap-y-1 border-b border-(--color-border) px-4 py-2 text-sm">
                {{-- ⭐ খাতের নামটাও খতিয়ানে নামে, গোটা সময়টা ধরে। --}}
                <h2 class="font-semibold">
                    <a href="{{ route('accounts.report.show', ['slug' => 'ledger', 'account_id' => $account->id, 'from' => $from, 'to' => $to]) }}"
                       class="text-(--color-brand-600) underline-offset-2 hover:underline">{{ $account->label() }}</a>
                    — {{ __('finance::account_analysis.by_month') }}
                </h2>
                <span class="ms-auto tabular-nums">{{ __('finance::account_analysis.opening') }}: <strong>{{ Money::format($report['opening']) }}</strong></span>
                <span class="tabular-nums">{{ __('finance::account_analysis.closing') }}: <strong>{{ Money::format($report['closing']) }}</strong></span>
            </header>

            <x-ui.table :rows="$report['months']" :empty="__('finance::account_analysis.none')" :columns="[
                ['key' => 'month', 'label' => __('finance::account_analysis.month'), 'width' => '8rem',
                 'render' => fn ($m) => view('finance::account-analysis.partials.month-link', ['m' => $m, 'account' => $account])],
                ['key' => 'opening', 'label' => __('finance::account_analysis.opening'), 'numeric' => true,
                 'render' => fn ($m) => Money::format($m['opening'])],

                /* ⭐ যোগফল আর গোনা — তিনটাই ঐ মাসের খতিয়ানে নামে (২০ সেপ্টেম্বর
                   ২০২৬)। ⓘ “জুলাইয়ে ১২টা সারি, ৳ ৮,৪০০ ডেবিট” পড়ার পরের
                   প্রশ্নটা সবসময়ই “কোন বারোটা” — আগে উত্তরটা বাঁ দিকের এক
                   ঘরে লুকানো ছিল, আর কেউ জানত না ওটা ক্লিক করা যায়।

                   ⚠️ খোলা ও বন্ধের জেরে লিংক নেই: ওগুলো কোনো সারির যোগ নয়,
                   ঐ মুহূর্তের অবস্থা — ক্লিক করলে ভুল সারিগুলো দেখাত। */
                ['key' => 'debit', 'label' => __('finance::account_analysis.debit'), 'numeric' => true,
                 'render' => fn ($m) => view('finance::account-analysis.partials.month-link', [
                     'm' => $m, 'account' => $account, 'text' => Money::format($m['debit']),
                 ])],
                ['key' => 'credit', 'label' => __('finance::account_analysis.credit'), 'numeric' => true,
                 'render' => fn ($m) => view('finance::account-analysis.partials.month-link', [
                     'm' => $m, 'account' => $account, 'text' => Money::format($m['credit']),
                 ])],
                ['key' => 'closing', 'label' => __('finance::account_analysis.closing'), 'numeric' => true,
                 'render' => fn ($m) => Money::format($m['closing'])],
                ['key' => 'count', 'label' => __('finance::account_analysis.entries'), 'numeric' => true, 'width' => '6rem',
                 'render' => fn ($m) => view('finance::account-analysis.partials.month-link', [
                     'm' => $m, 'account' => $account, 'text' => $m['count'],
                 ])],
            ]" />
        </section>

        <div class="grid gap-4 lg:grid-cols-2">
            <section data-boxed
                     class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
                <h2 class="border-b border-(--color-border) px-4 py-2 text-sm font-semibold">
                    {{ __('finance::account_analysis.by_source') }}
                </h2>
                <x-ui.table :rows="$report['sources']" :empty="__('finance::account_analysis.none')" :columns="[
                    ['key' => 'type', 'label' => __('finance::account_analysis.source'),
                     'render' => fn ($s) => Lang::has('core.source.'.$s['type']) ? __('core.source.'.$s['type']) : $s['type']],
                    ['key' => 'debit', 'label' => __('finance::account_analysis.debit'), 'numeric' => true,
                     'render' => fn ($s) => Money::format($s['debit'])],
                    ['key' => 'credit', 'label' => __('finance::account_analysis.credit'), 'numeric' => true,
                     'render' => fn ($s) => Money::format($s['credit'])],
                    ['key' => 'count', 'label' => __('finance::account_analysis.entries'), 'numeric' => true, 'width' => '5rem',
                     'render' => fn ($s) => $s['count']],
                ]" />
            </section>

            <section data-boxed
                     class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
                <h2 class="border-b border-(--color-border) px-4 py-2 text-sm font-semibold">
                    {{ __('finance::account_analysis.by_party') }}
                </h2>
                <x-ui.table :rows="$report['parties']" :empty="'—'" :columns="[
                    ['key' => 'label', 'label' => __('finance::account_analysis.party'),
                     'render' => fn ($p) => view('finance::account-analysis.partials.party-link', ['p' => $p])],
                    ['key' => 'debit', 'label' => __('finance::account_analysis.debit'), 'numeric' => true,
                     'render' => fn ($p) => Money::format($p['debit'])],
                    ['key' => 'credit', 'label' => __('finance::account_analysis.credit'), 'numeric' => true,
                     'render' => fn ($p) => Money::format($p['credit'])],
                    ['key' => 'count', 'label' => __('finance::account_analysis.entries'), 'numeric' => true, 'width' => '5rem',
                     'render' => fn ($p) => $p['count']],
                ]" />
            </section>
        </div>
    @endif
</x-layouts.app>
