{{--
    নগদ গণনা — নোট ধরে ধরে।

    খাতার সংখ্যাটা এই পর্দায় কোথাও নেই, ইচ্ছাকৃতভাবে। দেখালে ক্যাশিয়ার
    ওই সংখ্যাটাই টাইপ করে দিত, আর গণনার পুরো উদ্দেশ্যটাই হারাত। গোনা
    শেষে সেভ করার পর দুইটা সংখ্যা পাশাপাশি আসে।

    নোটগুলো বড় থেকে ছোট — হাতে যেভাবে গোনা হয়। ফর্মের ক্রম হাতের
    ক্রমের সাথে না মিললে প্রতিটা গণনায় চোখ এদিক-ওদিক করতে হয়।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('accounts::menu.cash_count') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('accounts::menu.cash_count')"
                          :subtitle="__('accounts::message.count_note')" />
    </x-slot:header>

    <form method="POST" action="{{ route('accounts.count.store') }}"
          x-data="cashCount()"
          @submit="busy ? $event.preventDefault() : (busy = true)"
          class="max-w-3xl space-y-4">
        @csrf

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
            <div class="grid gap-3 sm:grid-cols-2">
                <label class="block">
                    <span class="mb-1 block text-sm font-medium">
                        {{ __('accounts::menu.cash_tills') }}
                        <span class="text-(--color-danger)" aria-hidden="true">*</span>
                    </span>
                    <select name="cash_till_id" required
                            class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                   border-(--color-border) bg-(--color-surface-card) px-3">
                        <option value="">—</option>
                        @foreach ($tills as $till)
                            <option value="{{ $till->id }}" @selected(old('cash_till_id') == $till->id)>
                                {{ $till->code }} — {{ $till->name() }}
                            </option>
                        @endforeach
                    </select>
                </label>

                <x-ui.field name="trx_date" type="date" :label="__('accounts::field.date')"
                            :value="old('trx_date', now()->format('Y-m-d'))" required />
            </div>
        </section>

        <section class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card)">
            <h2 class="border-b border-(--color-border) px-4 py-3 font-semibold">
                {{ __('accounts::section.notes') }}
            </h2>

            <div class="overflow-x-auto">
                <table class="w-full border-collapse text-sm">
                    <thead>
                        <tr class="border-b border-(--color-border) bg-(--color-surface-app)">
                            <th scope="col" style="width: 8rem"
                                class="num px-3 py-2 text-start font-medium text-(--color-ink-muted)">
                                {{ __('accounts::field.note') }}
                            </th>
                            <th scope="col" style="width: 9rem"
                                class="num px-3 py-2 text-start font-medium text-(--color-ink-muted)">
                                {{ __('accounts::field.pieces') }}
                            </th>
                            <th scope="col" class="num px-3 py-2 text-start font-medium text-(--color-ink-muted)">
                                {{ __('accounts::field.amount') }}
                            </th>
                        </tr>
                    </thead>

                    <tbody>
                        @foreach ($notes as $note)
                            <tr class="border-b border-(--color-border)">
                                <td class="num px-3 py-1.5 font-medium">{{ number_format($note) }}</td>

                                <td class="px-3 py-1.5">
                                    <input type="number" min="0" step="1" inputmode="numeric"
                                           name="counts[{{ $note }}]"
                                           value="{{ old('counts.' . $note) }}"
                                           data-note="{{ $note }}"
                                           @input="recount()"
                                           class="num h-(--spacing-field) w-full rounded-(--radius-field) border
                                                  border-(--color-border) bg-(--color-surface-card) px-2 text-end">
                                </td>

                                <td class="num px-3 py-1.5 text-(--color-ink-muted)"
                                    x-text="format(lineOf({{ $note }}))">0.00</td>
                            </tr>
                        @endforeach
                    </tbody>

                    <tfoot>
                        <tr class="bg-(--color-surface-app) font-semibold">
                            <td class="px-3 py-2">{{ __('core.print.total') }}</td>
                            <td class="num px-3 py-2" x-text="pieces">0</td>
                            <td class="num px-3 py-2" x-text="format(total)">0.00</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </section>

        <section class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <label class="block">
                <span class="mb-1 block text-sm font-medium">{{ __('core.table.narration') }}</span>
                <input type="text" name="narration" value="{{ old('narration') }}"
                       class="h-(--spacing-field) w-full rounded-(--radius-field) border
                              border-(--color-border) bg-(--color-surface-card) px-3">
            </label>
        </section>

        <div class="flex flex-wrap gap-2">
            <x-ui.button type="submit" tone="primary"
                         ::class="(busy || total <= 0) && 'pointer-events-none opacity-50'">
                {{ __('accounts::action.save_count') }}
            </x-ui.button>

            <x-ui.button tone="secondary" :href="route('accounts.count.index')">
                {{ __('core.action.cancel') }}
            </x-ui.button>
        </div>
    </form>

    @push('scripts')
        <script>
            function cashCount() {
                return {
                    busy: false,
                    total: 0,
                    pieces: 0,
                    counts: {},
                    lineOf(note) {
                        return note * (this.counts[note] || 0);
                    },
                    format(n) {
                        return (n || 0).toLocaleString('en-US', {
                            minimumFractionDigits: 2, maximumFractionDigits: 2,
                        });
                    },
                    recount() {
                        const next = {};
                        let total = 0, pieces = 0;

                        this.$el.querySelectorAll('input[data-note]').forEach((input) => {
                            const note = parseInt(input.dataset.note, 10);
                            const qty = parseInt(input.value, 10) || 0;

                            next[note] = qty;
                            total += note * qty;
                            pieces += qty;
                        });

                        this.counts = next;
                        this.total = total;
                        this.pieces = pieces;
                    },
                    init() { this.recount(); },
                };
            }
        </script>
    @endpush
</x-layouts.app>
