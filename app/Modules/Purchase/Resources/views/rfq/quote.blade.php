{{--
    একজন সরবরাহকারীর দর লেখা।

    ── ⚠️ সারিগুলো RFQ থেকেই আসে, বদলানো যায় না ────────────────────────
    ⓘ দরটা **ঐ প্রশ্নেরই** উত্তর। ⛔ নতুন পণ্য যোগ করতে দিলে তুলনার ছকে
    দুইজনের দুই রকম তালিকা বসত, আর পাশাপাশি রাখার কোনো মানে থাকত না।

    ── ⓘ ভাড়া ও অন্যান্য খরচ কাগজের মাথায়, সারিতে নয় ──────────────────
    সরবরাহকারী সাধারণত পুরো চালানের জন্য একটা ভাড়া বলেন, পণ্য ধরে ধরে
    নয়। ⚠️ সারিতে ভাগ করে বসালে সংখ্যাটা বানানো হত, আর তুলনায় ভুল
    তথ্য যেত।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('purchase::action.add_quotation') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('purchase::action.add_quotation')"
                          :subtitle="$rfq->document_no" />
    </x-slot:header>

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

    {{-- ⓘ যাঁরা আগেই জবাব দিয়েছেন তাঁরা তালিকায় নেই। ⚠️ সবাই দিয়ে
         ফেললে এটাই সঠিক বার্তা: আর কারও জবাব বাকি নেই। --}}
    @if ($suppliers->isEmpty())
        <x-ui.empty-state :message="__('purchase::message.everyone_has_answered')" />
    @else
        <form method="POST" action="{{ route('purchase.rfq.quote.store', $rfq) }}" class="space-y-4">
            @csrf

            <section data-boxed
                     class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <x-ui.select name="supplier_id" :label="__('purchase::field.supplier')"
                                 :options="$suppliers->mapWithKeys(fn ($s) => [$s->id => $s->name()])"
                                 :selected="old('supplier_id')" placeholder="-" required />

                    {{-- ⓘ সরবরাহকারীর নিজের নম্বর — তাঁর কাগজে যা ছাপা।
                         ⚠️ তর্কের দিন এই নম্বরটাই দুই পক্ষের সাধারণ ভাষা। --}}
                    <x-ui.field name="supplier_quote_no" :label="__('purchase::field.supplier_quote_no')"
                                :value="old('supplier_quote_no')" />

                    <x-ui.field name="quoted_on" type="date" :label="__('purchase::field.quoted_on')"
                                :value="old('quoted_on', now()->toDateString())" required />

                    {{-- ⭐ কত দিন দরটা টিকবে — ⚠️ মেয়াদ পেরোনো দর দিয়ে
                         আদেশ দিলে সরবরাহকারী বলতেন "ওটা তো পুরনো দর",
                         আর কথাটা তাঁরই ঠিক হত। --}}
                    <x-ui.field name="valid_until" type="date" :label="__('purchase::field.valid_until')"
                                :value="old('valid_until')" />

                    <x-ui.field name="delivery_days" type="number" min="0"
                                :label="__('purchase::field.delivery_days')"
                                :value="old('delivery_days')" />

                    <x-ui.field name="payment_terms" :label="__('purchase::field.payment_terms')"
                                :value="old('payment_terms')" />

                    <x-ui.field name="freight" type="number" step="0.01"
                                :label="__('purchase::field.freight')"
                                :value="old('freight', '0')" />

                    <x-ui.field name="other_charges" type="number" step="0.01"
                                :label="__('purchase::field.other_charges')"
                                :value="old('other_charges', '0')" />
                </div>

                <div class="mt-3">
                    <x-ui.field name="narration" :label="__('purchase::field.narration')"
                                :value="old('narration')" />
                </div>
            </section>

            <section data-boxed
                     class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                <h2 class="mb-3 font-semibold">{{ __('purchase::field.items') }}</h2>

                <div class="table-responsive">
                    <table class="ui-lines table-cards w-full text-sm">
                        <thead>
                            <tr>
                                <th class="text-start">{{ __('purchase::field.product') }}</th>
                                <th class="text-end">{{ __('purchase::field.quantity') }}</th>
                                <th class="text-end">{{ __('purchase::field.rate') }}</th>
                                <th class="text-end">{{ __('purchase::field.discount') }}</th>
                                <th class="text-end">{{ __('purchase::field.tax') }}</th>
                            </tr>
                        </thead>

                        <tbody>
                            @foreach ($rfq->lines as $i => $line)
                                <tr class="border-b border-(--color-border)">
                                    <td class="cell-input" data-label="{{ __('purchase::field.product') }}">
                                        {{ $line->product?->name() ?? '—' }}

                                        @if ($line->specification)
                                            <span class="block text-2xs text-(--color-ink-muted)">
                                                {{ $line->specification }}
                                            </span>
                                        @endif

                                        <input type="hidden" name="lines[{{ $i }}][product_id]"
                                               value="{{ $line->product_id }}">
                                    </td>

                                    {{-- ⓘ পরিমাণটা বদলানো যায়: সরবরাহকারী
                                         কখনো বলেন *"এত কম দিতে পারব না,
                                         একশোর নিচে নয়"*। ⚠️ ঘরটা তালাবদ্ধ
                                         করলে ঐ কথাটা কোথাও লেখাই যেত না। --}}
                                    <td class="cell-input" data-label="{{ __('purchase::field.quantity') }}">
                                        <input type="number" step="0.01" inputmode="decimal"
                                               name="lines[{{ $i }}][qty]"
                                               value="{{ old('lines.'.$i.'.qty', (string) $line->qty) }}"
                                               class="num h-(--spacing-field-compact) w-full sm:w-24
                                                      rounded-(--radius-field) border border-(--color-border)
                                                      bg-(--color-surface-card) px-2 text-end">
                                    </td>

                                    <td class="cell-input" data-label="{{ __('purchase::field.rate') }}">
                                        <input type="number" step="0.01" inputmode="decimal"
                                               name="lines[{{ $i }}][rate]"
                                               value="{{ old('lines.'.$i.'.rate') }}"
                                               class="num h-(--spacing-field-compact) w-full sm:w-24
                                                      rounded-(--radius-field) border border-(--color-border)
                                                      bg-(--color-surface-card) px-2 text-end">
                                    </td>

                                    <td class="cell-input" data-label="{{ __('purchase::field.discount') }}">
                                        <input type="number" step="0.01" inputmode="decimal"
                                               name="lines[{{ $i }}][discount]"
                                               value="{{ old('lines.'.$i.'.discount', '0') }}"
                                               class="num h-(--spacing-field-compact) w-full sm:w-24
                                                      rounded-(--radius-field) border border-(--color-border)
                                                      bg-(--color-surface-card) px-2 text-end">
                                    </td>

                                    <td class="cell-input" data-label="{{ __('purchase::field.tax') }}">
                                        <input type="number" step="0.01" inputmode="decimal"
                                               name="lines[{{ $i }}][tax]"
                                               value="{{ old('lines.'.$i.'.tax', '0') }}"
                                               class="num h-(--spacing-field-compact) w-full sm:w-24
                                                      rounded-(--radius-field) border border-(--color-border)
                                                      bg-(--color-surface-card) px-2 text-end">
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            <div class="flex flex-wrap gap-2">
                <x-ui.button type="submit" tone="primary">{{ __('core.action.save') }}</x-ui.button>
                <x-ui.button tone="secondary" :href="route('purchase.rfq.show', $rfq)">
                    {{ __('core.action.cancel') }}
                </x-ui.button>
            </div>
        </form>
    @endif
</x-layouts.app>
