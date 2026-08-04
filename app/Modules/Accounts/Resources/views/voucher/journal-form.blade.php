{{--
    জাবেদা ভাউচার — যত খুশি সারি।

    এখানে "ডেবিট" ও "ক্রেডিট" শব্দ দুটো আছে, আর থাকা উচিত: জাবেদা
    লেখেন হিসাবরক্ষক, আর তাঁর ভাষা ওটাই। সহজ ফর্মে নেই, কারণ ওটা
    ক্যাশিয়ারের পর্দা।

    যোগফল দুটো পর্দাতেই সবসময় দেখা যায়, আর না মিললে সেভ বোতামটা
    ধূসর হয়ে যায় — সার্ভারে পাঠিয়ে ভুলের বার্তা ফেরত আনার চেয়ে
    টাইপ করতে করতেই জেনে ফেলা ভালো। সার্ভারও একই যাচাই করে; এটা
    শুধু আগে জানানো, বদলে নয়।
--}}
@php
    $isNew = ! $voucher->exists;

    // অন্তত পাঁচটা সারি — কম দিলে প্রতিটা জাবেদায় প্রথমেই "সারি যোগ
    // করুন" চাপতে হত, আর সেটা রোজকার কাজে বিরক্তিকর
    $existing = old('lines', $voucher->lines->map(fn ($l) => [
        'account_id' => $l->account_id,
        'debit' => bccomp((string) $l->debit, '0', 4) > 0 ? $l->debit : '',
        'credit' => bccomp((string) $l->credit, '0', 4) > 0 ? $l->credit : '',
        'narration' => $l->narration,
    ])->all());

    $rows = max(5, count($existing) + 1);
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('accounts::voucher.journal') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header
            :title="__('accounts::voucher.journal')"
            :subtitle="$isNew ? __('accounts::message.number_on_save') : $voucher->document_no" />
    </x-slot:header>

    <form method="POST"
          action="{{ $isNew ? route('accounts.voucher.store', 'journal') : route('accounts.voucher.update', $voucher) }}"
          x-data="journalForm()"
          @submit="busy ? $event.preventDefault() : (busy = true)"
          class="space-y-4">
        @csrf
        @unless ($isNew) @method('PUT') @endunless
        <input type="hidden" name="type" value="journal">

        @if ($errors->any())
            <div role="alert"
                 class="rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm
                        text-(--color-badge-danger-ink)">
                <ul class="list-inside list-disc">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <section class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <div class="grid gap-3 sm:grid-cols-3">
                <x-ui.field name="trx_date" type="date" :label="__('accounts::field.date')"
                            :value="old('trx_date', $voucher->trx_date?->format('Y-m-d') ?? now()->format('Y-m-d'))"
                            required />

                <label class="block sm:col-span-2">
                    <span class="mb-1 block text-sm font-medium">{{ __('core.table.narration') }}</span>
                    <input type="text" name="narration" value="{{ old('narration', $voucher->narration) }}"
                           class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                  border-(--color-border) bg-(--color-surface-card) px-3">
                </label>
            </div>
        </section>

        <section class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card)">
            <div class="overflow-x-auto">
                <table class="w-full border-collapse text-sm">
                    <thead>
                        <tr class="border-b border-(--color-border) bg-(--color-surface-app)">
                            <th scope="col" class="px-3 py-2 text-start font-medium text-(--color-ink-muted)">
                                {{ __('core.print.account') }}
                            </th>
                            <th scope="col" style="width: 10rem"
                                class="num px-3 py-2 text-start font-medium text-(--color-ink-muted)">
                                {{ __('core.table.debit') }}
                            </th>
                            <th scope="col" style="width: 10rem"
                                class="num px-3 py-2 text-start font-medium text-(--color-ink-muted)">
                                {{ __('core.table.credit') }}
                            </th>
                            <th scope="col" class="hidden px-3 py-2 text-start font-medium
                                                   text-(--color-ink-muted) lg:table-cell">
                                {{ __('core.table.narration') }}
                            </th>
                        </tr>
                    </thead>

                    <tbody>
                        @for ($i = 0; $i < $rows; $i++)
                            @php $line = $existing[$i] ?? [] @endphp
                            <tr class="border-b border-(--color-border)">
                                <td class="px-3 py-1.5">
                                    <select name="lines[{{ $i }}][account_id]"
                                            class="h-(--spacing-field) w-full min-w-48 rounded-(--radius-field)
                                                   border border-(--color-border) bg-(--color-surface-card) px-2">
                                        <option value="">—</option>
                                        @foreach ($allAccounts as $account)
                                            <option value="{{ $account->id }}"
                                                    @selected(($line['account_id'] ?? null) == $account->id)>
                                                {{ $account->label() }}
                                            </option>
                                        @endforeach
                                    </select>
                                </td>

                                <td class="px-3 py-1.5">
                                    <input type="number" step="0.01" inputmode="decimal"
                                           name="lines[{{ $i }}][debit]" value="{{ $line['debit'] ?? '' }}"
                                           @input="recount()"
                                           class="num h-(--spacing-field) w-full rounded-(--radius-field) border
                                                  border-(--color-border) bg-(--color-surface-card) px-2 text-end">
                                </td>

                                <td class="px-3 py-1.5">
                                    <input type="number" step="0.01" inputmode="decimal"
                                           name="lines[{{ $i }}][credit]" value="{{ $line['credit'] ?? '' }}"
                                           @input="recount()"
                                           class="num h-(--spacing-field) w-full rounded-(--radius-field) border
                                                  border-(--color-border) bg-(--color-surface-card) px-2 text-end">
                                </td>

                                <td class="hidden px-3 py-1.5 lg:table-cell">
                                    <input type="text" name="lines[{{ $i }}][narration]"
                                           value="{{ $line['narration'] ?? '' }}"
                                           class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                                  border-(--color-border) bg-(--color-surface-card) px-2">
                                </td>
                            </tr>
                        @endfor
                    </tbody>

                    <tfoot>
                        <tr class="bg-(--color-surface-app) font-semibold">
                            <td class="px-3 py-2 text-end">{{ __('core.print.total') }}</td>
                            <td class="num px-3 py-2 text-end" x-text="format(debit)">0.00</td>
                            <td class="num px-3 py-2 text-end" x-text="format(credit)">0.00</td>
                            <td class="hidden px-3 py-2 lg:table-cell">
                                {{-- পার্থক্যটা দেখানো হয়, লুকানো হয় না: কত টাকা
                                     কম পড়ছে সেটা জানলে ভুলটা খুঁজে পাওয়া সহজ।

                                     কিন্তু খালি ফর্মে নয় — কিছু টাইপ করার
                                     আগেই লাল "পার্থক্য ০.০০" দেখালে সেটা
                                     ভুলের বার্তা হয়ে দাঁড়ায়, অথচ কেউ এখনো
                                     কিছু করেনি। --}}
                                <span x-show="touched && ! balanced" x-cloak
                                      class="text-2xs font-normal text-(--color-danger)"
                                      x-text="'{{ __('accounts::message.difference') }} ' + format(Math.abs(debit - credit))">
                                </span>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </section>

        <div class="flex flex-wrap items-center gap-2">
            <x-ui.button type="submit" tone="primary"
                         ::class="(busy || ! balanced) && 'pointer-events-none opacity-50'">
                {{ __('accounts::action.save_and_post') }}
            </x-ui.button>

            <button type="submit" name="save_as_draft" value="1"
                    class="inline-flex min-h-(--spacing-touch) items-center rounded-(--radius-field) border
                           border-(--color-border) px-4 text-sm font-medium transition-colors
                           hover:bg-(--color-surface-hover)"
                    :class="busy && 'pointer-events-none opacity-70'">
                {{ __('accounts::action.save_draft') }}
            </button>

            <x-ui.button tone="secondary"
                         :href="$isNew
                             ? route('accounts.voucher.index', 'journal')
                             : route('accounts.voucher.show', $voucher)">
                {{ __('core.action.cancel') }}
            </x-ui.button>

            <span x-show="touched && ! balanced" x-cloak class="text-sm text-(--color-danger)">
                {{ __('accounts::message.must_balance') }}
            </span>
        </div>
    </form>

    @push('scripts')
        <script>
            function journalForm() {
                return {
                    busy: false,
                    debit: 0,
                    credit: 0,
                    // শূন্য-শূন্যও "মিলছে" নয়: একটা খালি জাবেদা সেভ করতে
                    // দিলে লেজারে কিছুই বসত না অথচ নম্বরটা খরচ হয়ে যেত
                    get balanced() {
                        return this.debit > 0 && Math.abs(this.debit - this.credit) < 0.005;
                    },
                    // কিছু টাইপ হয়েছে কি না — ভুলের বার্তা তার আগে নয়
                    get touched() {
                        return this.debit > 0 || this.credit > 0;
                    },
                    format(n) {
                        return (n || 0).toLocaleString('en-US', {
                            minimumFractionDigits: 2, maximumFractionDigits: 2,
                        });
                    },
                    recount() {
                        const sum = (sel) => [...this.$el.querySelectorAll(sel)]
                            .reduce((t, i) => t + (parseFloat(i.value) || 0), 0);
                        this.debit = sum('input[name$="[debit]"]');
                        this.credit = sum('input[name$="[credit]"]');
                    },
                    init() { this.recount(); },
                };
            }
        </script>
    @endpush
</x-layouts.app>
