            <section data-boxed x-show="depositOpen" x-cloak
                     class="rounded-(--radius-card) border border-(--color-border)
                            bg-(--color-surface-card) p-3">

                {{-- যোগ হয়ে যাওয়া পরিশোধগুলো --}}
                <template x-for="(row, i) in deposits" :key="i">
                    <div class="mb-1 flex items-center gap-2 rounded-(--radius-field)
                                bg-(--color-surface-sunken) px-2 py-1 text-2xs">
                        <span class="min-w-0 flex-1 truncate">
                            <span x-text="methodName(row.methodId)"></span>
                            <span class="text-(--color-ink-muted)"
                                  x-show="row.reference"
                                  x-text="' · ' + row.reference"></span>
                            <span class="text-(--color-ink-muted)"
                                  x-show="row.narration"
                                  x-text="' · ' + row.narration"></span>
                        </span>
                        <span class="num font-medium" x-text="money(Number(row.amount))"></span>
                        <button type="button" @click="dropDeposit(i)"
                                class="px-1 text-(--color-danger)"
                                aria-label="{{ __('purchase::action.clear_line') }}">&times;</button>

                        {{-- ⓘ সার্ভারে যা যায় — নামের ভিতরে সূচক, তাই
                             PHP-তে সারিগুলো আলাদা থাকে। --}}
                        <x-counter.deposit-fields />
                    </div>
                </template>

                <div class="flex flex-wrap items-end gap-2">
                    <label class="min-w-0 flex-1">
                        <span class="mb-1 block text-2xs text-(--color-ink-muted)">
                            {{ __('purchase::field.ref_date') }}
                        </span>
                        <x-ui.date dense name="deposit_ref_date"
                                   bind-name="'deposit_ref_date'"
                                   bind-iso="depositDraft.refDate"
                                   bind-model="depositDraft.refDate" />
                    </label>

                    <label class="min-w-0 flex-1">
                        <span class="mb-1 block text-2xs text-(--color-ink-muted)">
                            {{ __('purchase::field.instrument') }}
                        </span>
                        <select x-model="depositDraft.methodId" @change="methodPicked()"
                                class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                       border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
                            <option value="">{{ __('purchase::field.paid_how') }}</option>
                            @foreach ($depositMethods as $method)
                                <option value="{{ $method['id'] }}">{{ $method['label'] }}</option>
                            @endforeach
                        </select>
                    </label>

                    {{-- ⚠️ খাতের তালিকা উপায় বাছার পরেই। উপায় না বেছে খাত
                         দেখালে কেউ নগদের খাতে চেকের টাকা বসিয়ে দিতেন, আর
                         মাস শেষে নগদ মিলত না। --}}
                    <label class="min-w-0 flex-1">
                        <span class="mb-1 block text-2xs text-(--color-ink-muted)">
                            {{ __('purchase::field.paid_from') }}
                        </span>
                        <select x-model="depositDraft.accountId" :disabled="! depositDraft.methodId"
                                class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                       border-(--color-border) bg-(--color-surface-card) px-2 text-sm
                                       disabled:opacity-50">
                            <option value="">{{ __('purchase::field.paid_from') }}</option>
                            <template x-for="a in depositAccounts" :key="a.id">
                                <option :value="a.id" x-text="a.label"></option>
                            </template>
                        </select>
                    </label>

                    {{-- রেফারেন্স — কেবল যে উপায়ে সেটা লাগে --}}
                    <label class="min-w-0 flex-1" x-show="depositNeedsReference" x-cloak>
                        <span class="mb-1 block text-2xs text-(--color-ink-muted)">
                            {{ __('purchase::field.reference') }}
                        </span>
                        <input type="text" x-model="depositDraft.reference"
                               class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                      border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
                    </label>

                    <label class="min-w-0 flex-1">
                        <span class="mb-1 block text-2xs text-(--color-ink-muted)">
                            {{ __('purchase::field.amount') }}
                        </span>
                        <input type="number" step="0.01" inputmode="decimal"
                               x-model="depositDraft.amount"
                               class="num h-(--spacing-field) w-full rounded-(--radius-field) border
                                      border-(--color-border) bg-(--color-surface-card) px-2 text-end text-sm">
                    </label>

                    <label class="min-w-0 flex-1">
                        <span class="mb-1 block text-2xs text-(--color-ink-muted)">
                            {{ __('purchase::field.narration') }}
                        </span>
                        <input type="text" maxlength="255" x-model="depositDraft.narration"
                               class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                      border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
                    </label>

                    <button type="button" @click="addDeposit()" :disabled="! depositReady"
                            class="h-(--spacing-field) shrink-0 rounded-(--radius-field)
                                   bg-(--color-brand-500) px-4 text-sm font-medium
                                   text-(--color-brand-ink) hover:bg-(--color-brand-600)
                                   disabled:opacity-40">
                        {{ __('purchase::action.add_deposit') }}
                    </button>
                </div>

                {{-- ── এই উপায়ের কোনো খাত নেই ───────────────────────────

                     ⚠️ ছাঁকনিটা ঠিকমতো কাজ করলে এই অবস্থাটা আসবেই: যে
                     কোম্পানির ব্যাংক হিসাব ছকে বসানো নেই, সে "ব্যাংক
                     ট্রান্সফার" বাছলে **একটাও খাত পাবে না**।

                     ⛔ বার্তাটা না থাকলে পর্দাটা চুপ করে থাকত — খালি
                     তালিকা, নিষ্ক্রিয় "যোগ" বোতাম, আর কোনো কারণ নয়।
                     মানুষটা ভাবতেন পর্দা নষ্ট, অথচ অনুপস্থিত জিনিসটা
                     তাঁর নিজের হিসাবের ছকে। --}}
                <p x-show="depositDraft.methodId && depositAccounts.length === 0" x-cloak
                   class="mt-2 rounded-(--radius-field) bg-(--color-badge-warning-bg) px-2 py-1
                          text-2xs text-(--color-badge-warning-ink)">
                    {{ __('purchase::message.no_account_for_method') }}
                </p>
            </section>
