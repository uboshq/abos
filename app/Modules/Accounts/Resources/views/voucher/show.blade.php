{{--
    একটা ভাউচার — যা লেখা হয়েছে, আর যা লেজারে বসেছে।

    সারিগুলো ডেবিট-ক্রেডিট আকারেই দেখানো হয়, এমনকি সহজ ফর্মে লেখা
    ভাউচারেও। কারণ এটাই আসল রেকর্ড: পর্দায় "কার কাছ থেকে" লেখা হলেও
    হিসাবের খাতায় সেটা দুইটা সারি, আর ছাপা কাগজেও তাই থাকবে।
--}}
@php
    $totals = $voucher->totals();
    $canEdit = $voucher->isEditable();

    /*
     * ব্যাংকে গেলে লেনদেনের নম্বরটা এখানেই চাওয়া হয়, লেখার সময় নয়।
     *
     * লেখার মুহূর্তে বিকাশের TrxID জন্মায়ইনি — তখন চাইলে মানুষ `0`
     * বসিয়ে এগিয়ে যেতেন। লেজারে বসানোর মুহূর্তেই টাকাটা সত্যিই নড়ে,
     * তাই নম্বরটাও তখন হাতে থাকে।
     */
    $bankAccount = $voucher->lines->map(fn ($line) => $line->account)->first(fn ($a) => $a?->is_bank);
    $needsReference = $bankAccount !== null && blank($voucher->instrument_no);
@endphp

@php
    /* কলাম ধরে — `x-ui.table` স্লট পড়ে না, সারি আসে :rows থেকে। */
    $columns = [
        ['key' => 'account', 'label' => __('core.print.account'),
         'render' => fn ($l) => view('accounts::voucher.partials.account', ['line' => $l])],
        ['key' => 'narration', 'label' => __('core.table.narration'),
         'render' => fn ($l) => $l->narration],
        ['key' => 'debit', 'label' => __('core.table.debit'), 'numeric' => true, 'width' => '11rem',
         'render' => fn ($l) => bccomp((string) $l->debit, '0', 4) > 0
             ? \App\Core\Support\Money::format($l->debit) : ''],
        ['key' => 'credit', 'label' => __('core.table.credit'), 'numeric' => true, 'width' => '11rem',
         'render' => fn ($l) => bccomp((string) $l->credit, '0', 4) > 0
             ? \App\Core\Support\Money::format($l->credit) : ''],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $voucher->document_no }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$voucher->document_no" :subtitle="$voucher->typeLabel()">
            <x-slot:actions>
                @if ($voucher->isDraft())
                    @can('accounts.voucher.update')
                        <x-ui.button tone="secondary" :href="route('accounts.voucher.edit', $voucher)">
                            {{ __('core.action.edit') }}
                        </x-ui.button>

                        <form method="POST" action="{{ route('accounts.voucher.post', $voucher) }}"
                              class="flex items-end gap-2">
                            @csrf
                            @if ($needsReference)
                                <x-ui.field name="instrument_no"
                                            :label="__('accounts::field.bank_reference')"
                                            :hint="$bankAccount->label()"
                                            :value="old('instrument_no')"
                                            required
                                            class="w-56" />
                            @endif
                            <x-ui.button type="submit" tone="primary">
                                {{ __('accounts::action.post_now') }}
                            </x-ui.button>
                        </form>
                    @endcan
                @endif

                @unless ($voucher->isCancelled())
                    @can('accounts.voucher.delete')
                        {{-- বাতিলের কারণ বাধ্যতামূলক — কারণ ছাড়া বাতিল করা
                             ভাউচার পরে কেউ ব্যাখ্যা করতে পারে না --}}
                        <form method="POST" action="{{ route('accounts.voucher.cancel', $voucher) }}"
                              x-data="{ ask() {
                                  const r = prompt('{{ __('accounts::message.cancel_reason_prompt') }}');
                                  if (! r) return false;
                                  this.$refs.reason.value = r;
                                  return true;
                              } }"
                              @submit="if (! ask()) $event.preventDefault()">
                            @csrf
                            <input type="hidden" name="cancel_reason" x-ref="reason">
                            <x-ui.button type="submit" tone="secondary">
                                {{ __('accounts::action.cancel_voucher') }}
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

    @if ($voucher->isCancelled())
        {{-- বাতিল হলে সেটাই প্রথম যা চোখে পড়া উচিত, নাহলে কেউ এই
             কাগজটা দেখে ভাবত হিসাবটা এখনো চালু আছে --}}
        <div role="status"
             class="mb-4 rounded-(--radius-card) border border-(--color-danger) bg-(--color-badge-danger-bg)
                    px-4 py-3 text-sm text-(--color-badge-danger-ink)">
            <p class="font-semibold">{{ __('accounts::message.this_is_cancelled') }}</p>
            <p class="mt-1">{{ $voucher->cancel_reason }}</p>
            <p class="mt-1 text-2xs">
                {{ $voucher->canceller?->name }} ·
                {{ \App\Core\Support\DateFormat::formatWithTime($voucher->cancelled_at) }}
            </p>
        </div>
    @endif

    <div class="grid gap-4 lg:grid-cols-3">
        <section class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="text-sm font-medium text-(--color-ink-muted)">{{ __('accounts::field.amount') }}</h2>
            <p class="num mt-1 text-2xl font-semibold">{{ \App\Core\Support\Money::format($voucher->amount) }}</p>

            <div class="mt-3">
                @include('accounts::voucher.partials.status', ['voucher' => $voucher])
            </div>
        </section>

        <section class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4
                        lg:col-span-2">
            <h2 class="mb-3 font-semibold">{{ __('accounts::section.details') }}</h2>

            <dl class="grid gap-x-4 gap-y-2 sm:grid-cols-3">
                @foreach ([
                    'accounts::field.date' => \App\Core\Support\DateFormat::format($voucher->trx_date),
                    'core.company.branch' => $voucher->branch?->name(),
                    'accounts::field.instrument' => $voucher->instrument
                        ? __('accounts::instrument.' . $voucher->instrument) : null,
                    'accounts::field.instrument_no' => $voucher->instrument_no,
                    'accounts::field.instrument_date' => \App\Core\Support\DateFormat::format($voucher->instrument_date),
                    'core.table.narration' => $voucher->narration,
                ] as $label => $value)
                    @if (filled($value))
                        <div>
                            <dt class="text-2xs text-(--color-ink-muted)">{{ __($label) }}</dt>
                            <dd class="text-sm">{{ $value }}</dd>
                        </div>
                    @endif
                @endforeach
            </dl>

            {{-- কে লিখল ও কে পোস্ট করল — নিয়ম ২। কাগজের ভাউচারে দুইটা
                 সই থাকে; পর্দাতেও দুইটা নাম থাকা দরকার। --}}
            <p class="mt-3 border-t border-(--color-border) pt-3 text-2xs text-(--color-ink-muted)">
                {{ __('core.print.prepared_by') }}: {{ $voucher->creator?->name ?? '—' }}
                @if ($voucher->approver)
                    · {{ __('core.print.approved_by') }}: {{ $voucher->approver->name }}
                    ({{ \App\Core\Support\DateFormat::formatWithTime($voucher->approved_at) }})
                @endif
            </p>
        </section>
    </div>

    <section class="mt-4 overflow-hidden rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card)">
        <h2 class="border-b border-(--color-border) px-4 py-3 font-semibold">
            {{ __('accounts::section.entries') }}
        </h2>

        <div class="overflow-x-auto">
        <x-ui.table :rows="$voucher->lines"
                    :columns="$columns"
                    :totals="[
                        'debit' => \App\Core\Support\Money::format($totals['debit']),
                        'credit' => \App\Core\Support\Money::format($totals['credit']),
                    ]"
                    :totalsLabel="__('core.print.total')"
                    :empty="__('core.empty.no_results')" />
        </div>
    </section>
</x-layouts.app>
