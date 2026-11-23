{{--
    ⭐ জমা মেলানো — অফিসের দিক (জমার খবর ধাপ গ; মালিক, ১৬ নভেম্বর ২০২৬: "শেষ কথা ব্যাংক স্টেটমেন্ট"; [[DepositMatchController]])।

    ⓘ উপরে থেকে নিচে কাজের ক্রমে: স্টেটমেন্ট তোলা → নিজে থেকে মেলানোর প্রস্তাব → ব্যাংকে মিলেছে, "অনুমোদন দিন" বাকি →
    দুই দিকের না-মেলা (খবর, ব্যাংকের সারি)। সিদ্ধান্ত সারি থেকেই — দিনে বিশটা খবরে পাতা বদলালে কেউ পাতাটা খুলত না।
--}}
@php
    $money = fn ($v) => \App\Core\Support\Money::format($v);
    $date = fn ($d) => $d === null ? '—' : \App\Core\Support\DateFormat::format($d);
    $action = __('core.table.action_menu');

    $suggestColumns = [
        ['key' => 'customer', 'label' => __('sales::portal.customer'),
            'render' => fn ($p) => view('sales::claim.partials.customer', ['claim' => $p['claim']])],
        ['key' => 'claimed_on', 'label' => __('sales::portal.claimed_on'), 'width' => '8rem', 'render' => fn ($p) => $date($p['claim']->claimed_on)],
        ['key' => 'reference', 'label' => __('sales::portal.reference'), 'render' => fn ($p) => $p['claim']->reference ?? '—'],
        ['key' => 'bank', 'label' => __('sales::deposit.bank_line'),
            'render' => fn ($p) => view('sales::claim.partials.bank-line', ['line' => $p['line']])],
        ['key' => 'amount', 'total' => 'money', 'label' => __('sales::portal.claimed'), 'numeric' => true, 'width' => '9rem', 'render' => fn ($p) => $money($p['claim']->amount)],
        ['key' => 'act', 'label' => $action, 'width' => '8rem',
            'render' => fn ($p) => view('sales::claim.partials.match-button', ['claim' => $p['claim'], 'line' => $p['line']])],
    ];

    $matchedColumns = [
        ['key' => 'customer', 'label' => __('sales::portal.customer'),
            'render' => fn ($c) => view('sales::claim.partials.customer', ['claim' => $c])],
        ['key' => 'claimed_on', 'label' => __('sales::portal.claimed_on'), 'width' => '8rem', 'render' => fn ($c) => $date($c->claimed_on)],
        ['key' => 'bank', 'label' => __('sales::deposit.bank_line'),
            'render' => fn ($c) => view('sales::claim.partials.bank-line', ['line' => $c->statementLine, 'shared' => $sharedCounts[$c->statement_line_id] ?? 0])],
        ['key' => 'amount', 'total' => 'money', 'label' => __('sales::portal.claimed'), 'numeric' => true, 'width' => '9rem', 'render' => fn ($c) => $money($c->amount)],
        ['key' => 'stage', 'label' => __('sales::portal.status'), 'width' => '9rem',
            'render' => fn ($c) => view('sales::claim.partials.stage', ['claim' => $c])],
        ['key' => 'act', 'label' => $action,
            'render' => fn ($c) => view('sales::claim.partials.confirm', ['claim' => $c, 'sharedCounts' => $sharedCounts])],
    ];

    $waitingColumns = [
        ['key' => 'customer', 'label' => __('sales::portal.customer'),
            'render' => fn ($c) => view('sales::claim.partials.customer', ['claim' => $c])],
        ['key' => 'claimed_on', 'label' => __('sales::portal.claimed_on'), 'width' => '8rem', 'render' => fn ($c) => $date($c->claimed_on)],
        ['key' => 'bank', 'label' => __('sales::portal.bank_account'), 'render' => fn ($c) => $c->bankAccount?->label() ?? '—'],
        ['key' => 'reference', 'label' => __('sales::portal.reference'), 'render' => fn ($c) => $c->reference ?? '—'],
        ['key' => 'amount', 'total' => 'money', 'label' => __('sales::portal.claimed'), 'numeric' => true, 'width' => '9rem', 'render' => fn ($c) => $money($c->amount)],
        ['key' => 'act', 'label' => $action,
            'render' => fn ($c) => view('sales::claim.partials.match-by-hand', ['claim' => $c, 'lines' => $lines])],
    ];

    $lineColumns = [
        ['key' => 'trx_date', 'label' => __('sales::deposit.bank_date'), 'width' => '8rem', 'render' => fn ($l) => $date($l->trx_date)],
        ['key' => 'account', 'label' => __('sales::portal.bank_account'), 'render' => fn ($l) => $l->bankAccount?->label() ?? '—'],
        ['key' => 'reference', 'label' => __('sales::portal.reference'), 'render' => fn ($l) => $l->reference ?? '—'],
        ['key' => 'description', 'label' => __('sales::deposit.description'), 'render' => fn ($l) => $l->description ?? '—'],
        ['key' => 'credit', 'total' => 'money', 'label' => __('sales::deposit.came_in'), 'numeric' => true, 'width' => '9rem', 'render' => fn ($l) => $money($l->credit)],
        // ⭐ এক ব্যাংক-সারি, কয়েকটা খবর — টিক আর চলতি মোট (মালিক, ২১ নভেম্বর ২০২৬)
        ['key' => 'act', 'label' => $action, 'width' => '22rem',
            'render' => fn ($l) => view('sales::claim.partials.match-many', ['line' => $l, 'waiting' => $waiting])],
    ];

    // ⭐ দলের প্রস্তাব — এক ব্যাংক-সারি, একই রেফারেন্সের কয়েকটা খবর, মোট হুবহু
    $groupColumns = [
        ['key' => 'bank', 'label' => __('sales::deposit.bank_line'),
            'render' => fn ($g) => view('sales::claim.partials.bank-line', ['line' => $g['line']])],
        ['key' => 'reports', 'label' => __('sales::deposit.reports'),
            'render' => fn ($g) => $g['claims']->map(fn ($c) => $c->customer?->nameWithPoint().' · '.$money($c->amount))->implode(' | ')],
        ['key' => 'total', 'total' => 'money', 'label' => __('sales::portal.claimed'), 'numeric' => true, 'width' => '9rem', 'render' => fn ($g) => $money($g['total'])],
        ['key' => 'act', 'label' => $action, 'width' => '8rem',
            'render' => fn ($g) => view('sales::claim.partials.match-group', ['group' => $g])],
    ];

    // ⓘ প্রতিটা ভাগের নিজের যোগ — খোলা কাজের অঙ্ক, খাতার মোট নয় (খাতার মোট কেবল পাকা জমায়: জমার খাতা)
    $sum = fn (iterable $rows, \Closure $of) => collect($rows)->reduce(fn (string $s, $r) => bcadd($s, (string) $of($r), 4), '0');
    $suggestedGrand = ['amount' => $sum($suggested, fn ($p) => $p['claim']->amount)];
    $groupGrand = ['total' => $sum($groups, fn ($g) => $g['total'])];
    $matchedGrand = ['amount' => $sum($matched, fn ($c) => $c->amount)];
    $waitingGrand = ['amount' => $sum($waiting, fn ($c) => $c->amount)];
    $linesGrand = ['credit' => $sum($lines, fn ($l) => $l->credit)];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('sales::deposit.title') }}</x-slot:title>

    <x-ui.errors />
    @if (session('status'))
        <div role="status" class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm text-(--color-badge-success-ink)">{{ session('status') }}</div>
    @endif

    <div class="mb-4">
        <h1 class="text-lg font-semibold">{{ __('sales::deposit.title') }}<x-ui.page-info :text="__('sales::deposit.subtitle', ['days' => $days])" /></h1>
        {{-- ⭐ ব্যাখ্যা নামের পাশে ⓘ-এ, লাইনটা "ABOS"/"Dynamic"-এ লুকায় ([[ui/page-info]]; মালিক, ১৯ নভেম্বর ২০২৬ রাত) --}}
        <p data-page-subtitle class="text-sm text-(--color-ink-muted)">{{ __('sales::deposit.subtitle', ['days' => $days]) }}</p>
    </div>

    {{-- ⓘ গোনা — খবর এসেছে (ব্যাংকে নেই) · মিলেছে · আজ খাতায় --}}
    <div class="mb-4 flex flex-wrap gap-2 text-sm" data-deposit-counts>
        <span class="rounded-(--radius-field) bg-(--color-badge-pending-bg) px-3 py-1.5 text-(--color-badge-pending-ink)">{{ __('sales::deposit.stage.reported') }}: <b class="num">{{ $waiting->count() }}</b></span>
        <span class="rounded-(--radius-field) bg-(--color-badge-info-bg) px-3 py-1.5 text-(--color-badge-info-ink)">{{ __('sales::deposit.stage.matched') }}: <b class="num">{{ $matched->count() }}</b></span>
        <span class="rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-1.5 text-(--color-badge-success-ink)">{{ __('sales::deposit.confirmed_today') }}: <b class="num">{{ $confirmedToday }}</b></span>
        <a href="{{ route('sales.report.show', 'deposit-register') }}" class="px-3 py-1.5 text-(--color-brand-500) hover:underline">{{ __('sales::deposit.register_title') }}</a>
    </div>

    {{-- ১. স্টেটমেন্ট তোলা — ফাইল, বা হাতে এক সারি --}}
    <div class="mb-4 grid gap-4 md:grid-cols-2">
        <form method="POST" action="{{ route('sales.claim.match.load') }}" enctype="multipart/form-data" data-statement-upload
              class="grid gap-2 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            @csrf
            <h2 class="font-semibold">{{ __('sales::deposit.upload_title') }}</h2>
            <p class="text-2xs text-(--color-ink-muted)">{{ __('sales::deposit.upload_hint') }}</p>
            <x-ui.select name="account_id" id="statement_account_id" :label="__('sales::portal.bank_account')"
                         :options="$banks->mapWithKeys(fn ($b) => [$b->id => $b->label()])" placeholder="-" required />
            <x-ui.file-input id="deposit-statement-file" name="file" accept=".csv,.xlsx,text/csv,text/plain" required
                             aria-label="{{ __('sales::deposit.upload_title') }}" />
            <x-ui.button type="submit" tone="primary">{{ __('sales::deposit.upload') }}</x-ui.button>
        </form>

        <form method="POST" action="{{ route('sales.claim.match.line') }}" data-statement-line
              class="grid gap-2 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            @csrf
            <h2 class="font-semibold">{{ __('sales::deposit.hand_title') }}</h2>
            <x-ui.select name="account_id" id="line_account_id" :label="__('sales::portal.bank_account')"
                         :options="$banks->mapWithKeys(fn ($b) => [$b->id => $b->label()])" placeholder="-" required />
            <div class="grid grid-cols-2 gap-2">
                <x-ui.field name="trx_date" type="date" :label="__('sales::deposit.bank_date')" :value="old('trx_date', now()->toDateString())" required />
                <x-ui.field name="amount" type="number" step="0.01" inputmode="decimal" numeric required :label="__('sales::deposit.came_in')" :value="old('amount')" />
            </div>
            <x-ui.field name="reference" :label="__('sales::portal.reference')" :value="old('reference')" maxlength="60" />
            <x-ui.field name="description" :label="__('sales::deposit.description')" :value="old('description')" maxlength="255" />
            <x-ui.button type="submit">{{ __('sales::deposit.hand_add') }}</x-ui.button>
        </form>
    </div>

    {{-- ২. নিজে থেকে মেলানোর প্রস্তাব --}}
    <section class="mb-4 overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)" data-suggestions>
        <h2 class="border-b border-(--color-border) px-3 py-2 font-semibold">{{ __('sales::deposit.suggested_title') }}</h2>
        @if ($groups !== [])
            <div data-group-suggestions>
                <h3 class="border-b border-(--color-border) px-3 py-1.5 text-sm font-semibold">{{ __('sales::deposit.group_title') }}</h3>
                <x-ui.table :rows="$groups" :grand="$groupGrand" :columns="$groupColumns" :compact="\App\Core\Support\Ui::compact()" :empty="'—'" />
            </div>
        @endif
        <x-ui.table :rows="$suggested" :grand="$suggestedGrand" :columns="$suggestColumns" :compact="\App\Core\Support\Ui::compact()"
                    :empty="__('sales::deposit.suggested_empty')" />
    </section>

    {{-- ৩. ব্যাংকে মিলেছে — অনুমোদন দিন --}}
    <section class="mb-4 overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)" data-matched>
        <h2 class="border-b border-(--color-border) px-3 py-2 font-semibold">{{ __('sales::deposit.matched_title') }}</h2>
        <x-ui.table :rows="$matched" :grand="$matchedGrand" :columns="$matchedColumns" :compact="\App\Core\Support\Ui::compact()"
                    :empty="__('sales::deposit.matched_empty')" />
    </section>

    {{-- ৪. না-মেলা খবর — ব্যাংকে এখনো পাওয়া যায়নি --}}
    <section class="mb-4 overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)" data-waiting>
        <h2 class="border-b border-(--color-border) px-3 py-2 font-semibold">{{ __('sales::deposit.waiting_title') }}</h2>
        <x-ui.table :rows="$waiting" :grand="$waitingGrand" :columns="$waitingColumns" :compact="\App\Core\Support\Ui::compact()"
                    :empty="__('sales::deposit.waiting_empty')" />
    </section>

    {{-- ৫. না-মেলা ব্যাংকের সারি — টাকা এসেছে, কোনো খবরে বসেনি --}}
    <section class="mb-4 overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)" data-free-lines>
        <h2 class="border-b border-(--color-border) px-3 py-2 font-semibold">{{ __('sales::deposit.lines_title') }}</h2>
        <x-ui.table :rows="$lines" :grand="$linesGrand" :columns="$lineColumns" :compact="\App\Core\Support\Ui::compact()"
                    :empty="__('sales::deposit.lines_empty')" />
    </section>
</x-layouts.app>
