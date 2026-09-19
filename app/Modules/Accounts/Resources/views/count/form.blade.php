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
          x-data="cashCount({ zeroConfirm: @js(__('accounts::message.zero_count_confirm')) })"
          @submit="guard($event)"
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

        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
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

        <section data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card)">
            <h2 class="border-b border-(--color-border) bg-(--color-section-head) px-4 py-3 font-semibold">
                {{ __('accounts::section.notes') }}
            </h2>

            <div class="overflow-x-auto">
                <table class="ui-grid">
                    <thead>
                        <tr>
                            <th scope="col" style="width: 8rem"
                                class="num">
                                {{ __('accounts::field.note') }}
                            </th>
                            <th scope="col" style="width: 9rem"
                                class="num">
                                {{ __('accounts::field.pieces') }}
                            </th>
                            <th scope="col" class="num">
                                {{ __('accounts::field.amount') }}
                            </th>
                        </tr>
                    </thead>

                    <tbody>
                        @foreach ($notes as $note)
                            <tr>
                                <td class="num tight font-medium">{{ number_format($note) }}</td>

                                <td class="tight">
                                    {{-- x-model, DOM ঘেঁটে নয় — কারণটা নিচের স্ক্রিপ্টে লেখা --}}
                                    <input type="number" min="0" step="1" inputmode="numeric"
                                           name="counts[{{ $note }}]"
                                           value="{{ old('counts.' . $note) }}"
                                           data-note="{{ $note }}"
                                           x-model.number="counts[{{ $note }}]"
                                           class="num h-(--spacing-field) w-full rounded-(--radius-field) border
                                                  border-(--color-border) bg-(--color-surface-card) px-2 text-end">
                                </td>

                                <td class="num tight text-(--color-ink-muted)"
                                    x-text="format(lineOf({{ $note }}))">0.00</td>
                            </tr>
                        @endforeach
                    </tbody>

                    <tfoot>
                        <tr class="bg-(--color-surface-app) font-semibold">
                            <td>{{ __('core.print.total') }}</td>
                            <td class="num" x-text="pieces">0</td>
                            <td class="num" x-text="format(total)">0.00</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </section>

        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <label class="block">
                <span class="mb-1 block text-sm font-medium">{{ __('core.table.narration') }}</span>
                <input type="text" name="narration" value="{{ old('narration') }}"
                       class="h-(--spacing-field) w-full rounded-(--radius-field) border
                              border-(--color-border) bg-(--color-surface-card) px-3">
            </label>
        </section>

        <div class="flex flex-wrap gap-2">
            <x-ui.button type="submit" tone="primary"
                         ::class="busy && 'pointer-events-none opacity-50'">
                {{ __('accounts::action.save_count') }}
            </x-ui.button>

            <x-ui.button tone="secondary" :href="route('accounts.count.index')">
                {{ __('core.action.cancel') }}
            </x-ui.button>
        </div>
    </form>

</x-layouts.app>
