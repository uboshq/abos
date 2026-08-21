{{--
    একটা গণনা — গোনা কত, খাতায় কত, আর পার্থক্যটা কোথায় বসল।

    পার্থক্য থাকলে সেটাই সবচেয়ে বড় করে দেখানো হয়, আর অনুমোদনের পর
    যে জাবেদাটা বসেছে সেটাও ক্লিকযোগ্য (নিয়ম ১) — নাহলে "টাকাটা কোথায়
    গেল" প্রশ্নের উত্তর পর্দায় থাকত না।
--}}
@php
    /*
        কলাম ধরে — `x-ui.table` স্লট পড়ে না, সারি আসে :rows থেকে।

        `$notes` একটা মানচিত্র (নোটের মান => কয়টা), আর কম্পোনেন্ট
        সারিগুলো মান হিসেবে ঘোরে — চাবিটা হারিয়ে যেত। তাই আগে
        সারিগুলোকে অ্যারের তালিকা করে নেওয়া হয়।
    */
    /*
        নোটগুলো মডেল থেকে, কন্ট্রোলার থেকে নয়।

        ── কী ভেঙেছিল ─────────────────────────────────────────────
        কলামগুলো উপরে তোলার সময় এখানে `$notes` লেখা হয়েছিল, ধরে
        নিয়ে যে ভেরিয়েবলটা আসে। কিন্তু ওটা আসত না — `count.show`
        কন্ট্রোলার থেকে কেবল `$count` পায়, আর আগের লেখায় নোটগুলো
        টেবিলের ঠিক উপরে `$count->countedNotes()` থেকে নেওয়া হত।

        পাতাটা তাই ৫০০ দিত — অথচ কেবল **অনুমোদনের পরে**, যখন
        গণনাটা সত্যিই খোলা হয়। খালি অবস্থায় নয়।
    */
    $notes = $count->countedNotes();

    $noteRows = collect($notes)
        ->map(fn ($qty, $note) => ['note' => $note, 'qty' => $qty])
        ->values();

    $columns = [
        ['key' => 'note', 'label' => __('accounts::field.note'), 'numeric' => true,
         'render' => fn ($r) => number_format($r['note'])],
        ['key' => 'qty', 'label' => __('accounts::field.pieces'), 'numeric' => true,
         'render' => fn ($r) => number_format($r['qty'])],
        // সারির অঙ্কটা নিচের মোটে যায়, তাই গুণটাও bcmath-এ
        ['key' => 'amount', 'label' => __('accounts::field.amount'), 'numeric' => true,
         'render' => fn ($r) => \App\Core\Support\Money::format(
             bcmul((string) $r['note'], (string) $r['qty'], 4))],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $count->document_no }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$count->document_no"
                          :subtitle="$count->till?->name()">
            <x-slot:actions>
                @unless ($count->isApproved())
                    @can('accounts.count.approve')
                        <form method="POST" action="{{ route('accounts.count.approve', $count) }}">
                            @csrf
                            <x-ui.button type="submit" tone="primary">
                                {{ $count->matches()
                                    ? __('accounts::action.approve_count')
                                    : __('accounts::action.approve_and_adjust') }}
                            </x-ui.button>
                        </form>
                    @endcan
                @endunless
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    @if ($errors->any())
        <div role="alert"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm
                    text-(--color-badge-danger-ink)">
            <ul class="list-inside list-disc">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="grid gap-4 sm:grid-cols-3">
        <section class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="text-sm font-medium text-(--color-ink-muted)">{{ __('accounts::field.counted') }}</h2>
            <p class="num mt-1 text-2xl font-semibold">{{ \App\Core\Support\Money::format($count->counted_amount) }}</p>
        </section>

        <section class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="text-sm font-medium text-(--color-ink-muted)">{{ __('accounts::field.expected') }}</h2>
            <p class="num mt-1 text-2xl font-semibold">{{ \App\Core\Support\Money::format($count->expected_amount) }}</p>
        </section>

        <section @class([
            'rounded-(--radius-card) border p-4',
            'border-(--color-border) bg-(--color-surface-card)' => $count->matches(),
            'border-(--color-danger) bg-(--color-badge-danger-bg)' => ! $count->matches(),
        ])>
            <h2 class="text-sm font-medium text-(--color-ink-muted)">{{ __('accounts::field.difference') }}</h2>

            @if ($count->matches())
                <p class="mt-1 text-2xl font-semibold text-(--color-success)">
                    ✓ <span class="text-base font-normal">{{ __('accounts::message.count_matches') }}</span>
                </p>
            @else
                <p class="num mt-1 text-2xl font-semibold text-(--color-badge-danger-ink)">
                    {{ $count->isSurplus() ? '+' : '' }}{{ \App\Core\Support\Money::format($count->difference) }}
                </p>
                <p class="mt-1 text-2xs text-(--color-badge-danger-ink)">
                    {{ $count->isSurplus()
                        ? __('accounts::message.surplus_note')
                        : __('accounts::message.shortage_note') }}
                </p>
            @endif
        </section>
    </div>

    <div class="mt-4 grid gap-4 lg:grid-cols-3">
        <section class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card) lg:col-span-2">
            <h2 class="border-b border-(--color-border) px-4 py-3 font-semibold">
                {{ __('accounts::section.notes') }}
            </h2>

            @if ($notes === [])
                <x-ui.empty-state :message="__('accounts::message.no_notes_recorded')" />
            @else
                <x-ui.table :rows="$noteRows"
                            :columns="$columns"
                            :totals="[
                                'qty' => number_format(array_sum($notes)),
                                'amount' => \App\Core\Support\Money::format($count->counted_amount),
                            ]"
                            :totalsLabel="__('core.print.total')"
                            :empty="__('core.empty.no_results')" />
            @endif
        </section>

        <section class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <div class="mb-3 flex items-center justify-between gap-2">
                <h2 class="font-semibold">{{ __('accounts::section.details') }}</h2>
                @include('accounts::count.partials.status', ['count' => $count])
            </div>

            <dl class="space-y-2">
                @foreach ([
                    'accounts::field.date' => \App\Core\Support\DateFormat::format($count->trx_date),
                    'accounts::field.counted_by' => $count->counter?->name,
                    'core.print.approved_by' => $count->approver?->name,
                    'core.table.narration' => $count->narration,
                ] as $label => $value)
                    @if (filled($value))
                        <div>
                            <dt class="text-2xs text-(--color-ink-muted)">{{ __($label) }}</dt>
                            <dd class="text-sm">{{ $value }}</dd>
                        </div>
                    @endif
                @endforeach
            </dl>

            @if ($count->adjustment)
                {{-- সমন্বয়ের জাবেদাটা ক্লিকযোগ্য — নিয়ম ১। না থাকলে
                     "পার্থক্যের টাকাটা কোথায় বসল" প্রশ্নের উত্তর
                     পর্দায় থাকত না। --}}
                <p class="mt-3 border-t border-(--color-border) pt-3 text-sm">
                    <span class="block text-2xs text-(--color-ink-muted)">
                        {{ __('accounts::field.adjustment') }}
                    </span>
                    <a href="{{ route('accounts.voucher.show', $count->adjustment) }}"
                       class="num text-(--color-brand-500) underline-offset-2 hover:underline">
                        {{ $count->adjustment->document_no }}
                    </a>
                </p>
            @endif
        </section>
    </div>
</x-layouts.app>
