{{--
    চেকের খাতা।

    উপরে দুইটা সংখ্যা: কত টাকার চেক এখনো ঝুলছে, আর তার মধ্যে কয়টার
    তারিখ পেরিয়ে গেছে। দ্বিতীয়টাই রোজ সকালে দেখার জিনিস — আগাম
    তারিখের চেক ফেলে রাখা স্বাভাবিক, তারিখ পেরোনোর পরেও ফেলে রাখা নয়।
--}}
@php
    /* কলাম ধরে — `x-ui.table` স্লট পড়ে না, সারি আসে :rows থেকে। */
    $columns = [
        ['key' => 'cheque_no', 'label' => __('accounts::field.cheque_no'),
         'render' => fn ($c) => view('accounts::cheque.partials.no', ['cheque' => $c])],
        ['key' => 'cheque_date', 'label' => __('accounts::field.cheque_date'), 'width' => '9rem',
         'render' => fn ($c) => view('accounts::cheque.partials.date', ['cheque' => $c])],
        ['key' => 'bank_name', 'label' => __('accounts::field.bank_name'),
         'render' => fn ($c) => $c->bank_name ?: '—'],
        ['key' => 'amount', 'label' => __('accounts::field.amount'),
         'numeric' => true, 'width' => '11rem',
         'render' => fn ($c) => \App\Core\Support\Money::format($c->amount)],
        ['key' => 'state', 'label' => __('accounts::field.state'), 'width' => '11rem',
         'render' => fn ($c) => view('accounts::cheque.partials.state', ['cheque' => $c])],
        ['key' => 'actions', 'label' => __('core.table.actions'),
         'render' => fn ($c) => view('accounts::cheque.partials.actions',
             ['cheque' => $c, 'banks' => $banks])],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('accounts::menu.cheques') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('accounts::menu.cheques')"
                          :subtitle="__('accounts::message.cheque_note')" />
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

    <div class="mb-4 grid gap-3 sm:grid-cols-2">
        <div class="rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card) px-4 py-3">
            <p class="text-2xs uppercase tracking-wide text-(--color-ink-muted)">
                {{ __('accounts::field.cheques_open_total') }}
            </p>
            <p class="num text-2xl font-semibold">{{ \App\Core\Support\Money::format($openTotal) }}</p>
        </div>

        <div @class([
            'rounded-(--radius-card) border px-4 py-3',
            'border-(--color-border) bg-(--color-surface-card)' => $ripe === 0,
            'border-(--color-badge-danger-ink)/30 bg-(--color-badge-danger-bg)' => $ripe > 0,
        ])>
            <p class="text-2xs uppercase tracking-wide text-(--color-ink-muted)">
                {{ __('accounts::field.cheques_ripe') }}
            </p>
            <p class="num text-2xl font-semibold">{{ $ripe }}</p>
        </div>
    </div>

    @can('accounts.cheque.manage')
        <form method="POST" action="{{ route('accounts.cheque.store') }}"
              class="mb-5 grid gap-3 rounded-(--radius-card) border border-(--color-border)
                     bg-(--color-surface-card) p-4 md:grid-cols-3 lg:grid-cols-6">
            @csrf

            <label class="flex flex-col gap-1">
                <span class="text-sm font-medium">{{ __('accounts::field.cheque_direction') }}</span>
                <select name="direction"
                        class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border)
                               bg-(--color-surface-app) px-2">
                    <option value="received">{{ __('accounts::field.cheque_received') }}</option>
                    <option value="issued">{{ __('accounts::field.cheque_issued') }}</option>
                </select>
            </label>

            <label class="flex flex-col gap-1">
                <span class="text-sm font-medium">{{ __('accounts::field.cheque_no') }}</span>
                <input type="text" name="cheque_no" required value="{{ old('cheque_no') }}"
                       class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border)
                              bg-(--color-surface-app) px-2">
            </label>

            <label class="flex flex-col gap-1">
                <span class="text-sm font-medium">{{ __('accounts::field.bank_name') }}</span>
                <input type="text" name="bank_name" value="{{ old('bank_name') }}"
                       class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border)
                              bg-(--color-surface-app) px-2">
            </label>

            {{--
                চেকের নিজের তারিখ — এন্ট্রির তারিখ নয়।

                আগাম তারিখের চেক (PDC) বাংলাদেশে রোজকার, আর ওটাই এই
                খাতার প্রাণ: "আগামী সপ্তাহে কত টাকার চেক পাশ হবে"।
            --}}
            <label class="flex flex-col gap-1">
                <span class="text-sm font-medium">{{ __('accounts::field.cheque_date') }}</span>
                <x-ui.date name="cheque_date" :required="true"
                           :value="old('cheque_date', now()->toDateString())" />
                           </label>

            <label class="flex flex-col gap-1">
                <span class="text-sm font-medium">{{ __('accounts::field.amount') }}</span>
                <input type="number" step="0.01" min="0" name="amount" required value="{{ old('amount') }}"
                       class="num h-(--spacing-field) rounded-(--radius-field) border border-(--color-border)
                              bg-(--color-surface-app) px-2 text-end">
            </label>

            <label class="flex flex-col gap-1">
                <span class="text-sm font-medium">{{ __('accounts::field.party') }}</span>
                <select name="party"
                        class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border)
                               bg-(--color-surface-app) px-2">
                    <option value="">—</option>
                    @foreach ($parties as $group)
                        <optgroup label="{{ $group['label'] }}">
                            @foreach ($group['options'] as $party)
                                <option value="{{ $group['type'] }}:{{ $party['id'] }}">{{ $party['label'] }}</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
            </label>

            {{--
                আমাদের কোন ব্যাংক হিসাবে টাকাটা বসবে।

                উপরের "ব্যাংকের নাম" ঘরটা আলাদা জিনিস — ওটা চেকটা **কোন
                ব্যাংকের**, অর্থাৎ যিনি চেক দিয়েছেন তাঁর ব্যাংক। দুইটাতেই
                একই লেবেল বসানো ছিল, আর পর্দায় পাশাপাশি দুইটা "ব্যাংকের
                নাম" দেখে বোঝার উপায় ছিল না কোনটা কী।
            --}}
            <label class="flex flex-col gap-1 md:col-span-2">
                <span class="text-sm font-medium">{{ __('accounts::field.deposit_into') }}</span>
                <select name="bank_account_id"
                        class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border)
                               bg-(--color-surface-app) px-2">
                    <option value="">—</option>
                    @foreach ($banks as $bank)
                        <option value="{{ $bank->id }}">{{ $bank->label() }}</option>
                    @endforeach
                </select>
            </label>

            <label class="flex flex-col gap-1 md:col-span-2 lg:col-span-3">
                <span class="text-sm font-medium">{{ __('core.table.narration') }}</span>
                <input type="text" name="narration" value="{{ old('narration') }}"
                       class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border)
                              bg-(--color-surface-app) px-2">
            </label>

            <div class="flex items-end">
                <x-ui.button type="submit" tone="primary" class="w-full">
                    {{ __('core.action.save') }}
                </x-ui.button>
            </div>
        </form>
    @endcan

    @if ($cheques->isEmpty())
        <x-ui.empty-state :message="__('accounts::message.no_cheques')" />
    @else
        <div class="overflow-x-auto rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card)">
        <x-ui.table :rows="$cheques"
                    :columns="$columns"
                    :empty="__('accounts::message.no_cheques')" />
        </div>

        <div class="mt-3">{{ $cheques->links() }}</div>
    @endif
</x-layouts.app>
