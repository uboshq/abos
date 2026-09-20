{{--
    ডেবিট ও ক্রেডিট নোট — মানচিত্র §৭।

    ── ⚠️ একটা পর্দা, দুইটা ট্যাব ──────────────────────────────────────
    কাজটা এক — "টাকার অঙ্কটা ভুল ছিল, শোধরাও" — কেবল কাকে দেওয়া হচ্ছে
    সেটা আলাদা। ⓘ দুইটা আলাদা পর্দা বানালে একদিন একটায় ভ্যাটের ঘর যোগ
    হত, অন্যটায় না।
--}}
@php
    use App\Core\Support\Money;
    use App\Modules\Accounts\Models\Note;

    $tabs = [
        Note::CREDIT => __('accounts::note.credit_note'),
        Note::DEBIT => __('accounts::note.debit_note'),
    ];

    $columns = [
        ['key' => 'document_no', 'label' => __('core.print.document_no'), 'width' => '9rem',
         'render' => fn ($n) => view('accounts::note.partials.number', ['note' => $n])],
        ['key' => 'trx_date', 'label' => __('accounts::note.reason'), 'width' => '7rem',
         'render' => fn ($n) => $n->trx_date?->format('d M Y')],

        /* ⭐ পক্ষের নাম তাঁর নিজের পাতায় যায় — মালিকের নিয়ম, ২০ সেপ্টেম্বর ২০২৬ */
        ['key' => 'party', 'label' => __('accounts::note.party'),
         'render' => fn ($n) => view('accounts::note.partials.party', [
             'note' => $n, 'names' => $names, 'routes' => $routes,
         ])],
        ['key' => 'against_no', 'label' => __('accounts::note.against_no'), 'width' => '10rem',
         'render' => fn ($n) => $n->against_no ?: '—'],
        ['key' => 'reason', 'label' => __('accounts::note.reason'), 'width' => '12rem',
         'render' => fn ($n) => __('accounts::note.reason_'.$n->reason)],
        ['key' => 'total', 'label' => __('accounts::note.total'), 'numeric' => true, 'width' => '10rem',
         'render' => fn ($n) => Money::format($n->total)],
        ['key' => 'status', 'label' => __('core.table.status'), 'width' => '8rem',
         'render' => fn ($n) => view('accounts::note.partials.status', ['note' => $n])],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('accounts::note.title') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('accounts::note.title')"
                          :subtitle="__('accounts::note.subtitle')">
            <x-slot:actions>
                @can('accounts.note.manage')
                    <x-ui.button tone="primary" icon="plus"
                                 :href="route('accounts.note.create', ['direction' => $direction])">
                        {{ $direction === Note::CREDIT
                            ? __('accounts::note.new_credit')
                            : __('accounts::note.new_debit') }}
                    </x-ui.button>
                @endcan
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>

    @if (session('saved'))
        <p role="status" class="mb-3 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2
                                text-sm text-(--color-badge-success-ink)">{{ session('saved') }}</p>
    @endif

    <nav class="mb-3 flex flex-wrap gap-1 border-b border-(--color-border) text-sm"
         aria-label="{{ __('accounts::note.title') }}">
        @foreach ($tabs as $key => $label)
            @php $on = $direction === $key; @endphp
            <a href="{{ route('accounts.note.index', ['direction' => $key]) }}"
               @if ($on) aria-current="page" @endif
               class="-mb-px flex min-h-(--spacing-touch) items-center gap-2 border-b-2 px-3
                      {{ $on
                          ? 'border-(--color-brand-500) font-semibold text-(--color-ink)'
                          : 'border-transparent text-(--color-ink-muted) hover:text-(--color-ink)' }}">
                {{ $label }}
                <span class="rounded-full bg-(--color-surface-sunken) px-2 text-2xs text-(--color-ink-muted)">
                    {{ $counts[$key] ?? 0 }}
                </span>
            </a>
        @endforeach
    </nav>

    {{-- ⓘ ট্যাবটা কী জিনিস, সেটা এক লাইনে — দুইটার নাম কাছাকাছি, আর মানুষ
         নিয়মিত উল্টে ফেলেন --}}
    <p class="mb-3 text-sm text-(--color-ink-muted)">
        {{ $direction === Note::CREDIT
            ? __('accounts::note.credit_hint')
            : __('accounts::note.debit_hint') }}
    </p>

    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <x-ui.table :rows="$rows" :columns="$columns" :empty="__('accounts::note.none_yet')" />

        <x-ui.pager :rows="$rows" />
    </div>
</x-layouts.app>
