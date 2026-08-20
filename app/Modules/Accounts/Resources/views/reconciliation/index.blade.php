{{--
    মিলকরণের তালিকা।

    মাস শেষে প্রথম প্রশ্নটা "কোন হিসাবের কোন মাস মেলানো হয়েছে, আর কোনটা
    বাকি" — তাই তালিকাটাই প্রধান পর্দা, আর নতুন মিলকরণ শুরু করার ফর্মটা
    উপরে, চেকের খাতার মতোই।

    ── কলামগুলো এখানে, স্লটে নয় ─────────────────────────────────────────
    প্রথম লেখায় `<x-ui.table>`-এর ভেতরে হাতে `<tr>` বসানো ছিল। কম্পোনেন্ট
    স্লট পড়েই না — সে `:rows` আর `:columns` থেকে নিজে সারি আঁকে, আর
    প্রতিটা কলামে `key` ও `label` দুইটাই চায়।

    ফলে পর্দাটা **খালি অবস্থায় ঠিক চলত** (তখন `@if` টেবিলটা এড়িয়ে যায়),
    আর প্রথম মিলকরণটা তৈরি হওয়ামাত্র ৫০০ দিত। ওরকম ভুল সবচেয়ে খারাপ:
    ডেমোতে ধরা পড়ে না, ধরা পড়ে প্রথম আসল ব্যবহারকারীর হাতে।
--}}
@php
    $columns = [
        [
            'key' => 'bank',
            'label' => __('accounts::recon.bank_account'),
            'render' => fn ($r) => view('accounts::reconciliation.partials.bank', ['recon' => $r]),
        ],
        [
            'key' => 'statement_date',
            'label' => __('accounts::recon.statement_date'),
            'width' => '9rem',
            'render' => fn ($r) => $r->statement_date?->format('d M Y'),
        ],
        [
            'key' => 'statement_balance',
            'label' => __('accounts::recon.statement_balance'),
            'numeric' => true,
            'width' => '11rem',
            'render' => fn ($r) => view('accounts::reconciliation.partials.amount', ['value' => $r->statement_balance]),
        ],
        [
            'key' => 'status',
            'label' => __('accounts::recon.status'),
            'width' => '9rem',
            'render' => fn ($r) => view('accounts::reconciliation.partials.status', ['recon' => $r]),
        ],
        [
            'key' => 'confirmed_by',
            'label' => __('accounts::recon.confirmed_by'),
            'render' => fn ($r) => $r->confirmer?->name ?? '—',
        ],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('accounts::recon.title') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('accounts::recon.title')"
                          :subtitle="__('accounts::recon.subtitle')" />
    </x-slot:header>

    @if (session('status'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('status') }}
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

    @can('accounts.reconciliation.manage')
        <form method="POST" action="{{ route('accounts.reconciliation.store') }}"
              class="mb-5 grid gap-3 rounded-(--radius-card) border border-(--color-border)
                     bg-(--color-surface-card) p-4 md:grid-cols-2 lg:grid-cols-5">
            @csrf

            <label class="flex flex-col gap-1">
                <span class="text-sm font-medium">{{ __('accounts::recon.bank_account') }}</span>
                <select name="bank_account_id" required
                        class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border)
                               bg-(--color-surface-app) px-2">
                    @foreach ($banks as $bank)
                        <option value="{{ $bank->id }}">{{ $bank->label() }}</option>
                    @endforeach
                </select>
            </label>

            <label class="flex flex-col gap-1">
                <span class="text-sm font-medium">{{ __('accounts::recon.statement_date') }}</span>
                <x-ui.date name="statement_date" :required="true"
                           :value="old('statement_date', now()->toDateString())" />
            </label>

            <label class="flex flex-col gap-1">
                <span class="text-sm font-medium">{{ __('accounts::recon.statement_balance') }}</span>
                <input type="number" step="0.01" name="statement_balance" required
                       value="{{ old('statement_balance') }}"
                       class="num h-(--spacing-field) rounded-(--radius-field) border border-(--color-border)
                              bg-(--color-surface-app) px-2 text-end">
            </label>

            <label class="flex flex-col gap-1">
                <span class="text-sm font-medium">{{ __('accounts::recon.narration') }}</span>
                <input type="text" name="narration" value="{{ old('narration') }}"
                       class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border)
                              bg-(--color-surface-app) px-2">
            </label>

            <div class="flex items-end">
                <x-ui.button type="submit" tone="primary" class="w-full">
                    {{ __('accounts::recon.open_action') }}
                </x-ui.button>
            </div>
        </form>
    @endcan

    <x-ui.table :rows="$reconciliations"
                :columns="$columns"
                :empty="__('accounts::recon.empty')" />

    {{ $reconciliations->links() }}
</x-layouts.app>
