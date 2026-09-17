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
        'party_type' => $l->party_type,
        'party_id' => $l->party_id,
        'cost_center_id' => $l->cost_center_id,
    ])->all());

    $rows = max(5, count($existing) + 1);
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('accounts::voucher.journal') }}</x-slot:title>

    <x-slot:header>
        {{--
            ⓘ নকশায় নম্বরটা ডানে একটা ব্যাজে, আর উপশিরোনামে জাবেদার
            সংজ্ঞাটাই — "টাকা নড়ে না — খাতা নড়ে"। ⭐ মালিকের কথা:
            *"১ নম্বরের চেহারা, ২ নম্বরের ঘরগুলো"*।
        --}}
        <x-ui.page-header
            :title="__('accounts::voucher.journal')"
            :subtitle="__('accounts::message.journal_subtitle')">
            <x-slot:actions>
                <span class="num rounded-(--radius-field) border border-(--color-border)
                             bg-(--color-surface-sunken) px-3 py-1.5 text-sm text-(--color-ink-muted)">
                    {{ $isNew ? __('accounts::message.number_on_save') : $voucher->document_no }}
                </span>
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>

    {{--
        ⛔ `enctype` ছাড়া ফাইলের ঘরটা নীরবে অকেজো — ১৫ সেপ্টেম্বর ২০২৬।

        ⓘ ব্রাউজার ডিফল্টে ফর্মটা `application/x-www-form-urlencoded`
        হিসেবে পাঠায়, আর তাতে ফাইলের কেবল **নামটা** যায়, বাইটগুলো নয়।

        ⚠️ সার্ভারে কোনো ভুল দেখা যেত না: `$request->file('attachment')`
        হত `null`, আর কোড ভাবত ব্যবহারকারী কিছু দেননি। ⓘ তিনি দেখতেন
        ভাউচারটা সেভ হয়েছে, কেবল ছবিটা নেই — আর কেন, তার কোনো চিহ্নও
        থাকত না।
    --}}
    <form method="POST" enctype="multipart/form-data"
          action="{{ $isNew ? route('accounts.voucher.store', 'journal') : route('accounts.voucher.update', $voucher) }}"
          x-data="journalForm()"
          @submit="busy ? $event.preventDefault() : (busy = true)"
          class="space-y-4">
        @csrf
        @unless ($isNew) @method('PUT') @endunless
        <input type="hidden" name="type" value="journal">

        {{--
            ⛔ "কেন এভাবে" ব্লকটা তুলে দেওয়া হলো — মালিকের সিদ্ধান্ত, ১৮ সেপ্টেম্বর ২০২৬।

            ── ⓘ কী ছিল আর কেন গেল ─────────────────────────────────────
            নকশার ছবিতে মাথায় দুইটা লাইন ছিল: একটা নিয়ম (নগদ/ব্যাংকের
            খাত বসালে সতর্ক করে, থামায় না), আর একটা স্বীকারোক্তি
            (*"আমি প্রথমে এটা পুরো আটকে দিয়েছিলাম, আর সেটা ভুল ছিল…"*)।

            ⚠️ দ্বিতীয়টা পর্দার লেখাই নয় — ওটা নকশা আঁকার সময় নকশা-পাঠকের
            উদ্দেশে লেখা, প্রথম পুরুষে। ⓘ মালিক ধরিয়ে দেন, আর সেটা বাদ যায়।
            তারপর তিনি বলেন *"eta bad daw"* — বাকিটাও।

            ── ⭐ আর নিয়মটা হারায়নি ───────────────────────────────────
            নগদ/ব্যাংকের খাত বসালে পর্দা **তখনই** সতর্ক করে, যখন সত্যিই
            বসানো হয় — সেটাই ঠিক জায়গা। ⛔ আগে থেকে সবার মাথায় চার লাইন
            পড়ানোর দরকার নেই; বেশিরভাগ জাবেদায় নগদের খাত থাকেই না।

            ⓘ আর নিয়মটা কেন আটকায় না তার কারণ কোডেই লেখা
            ([[VoucherService]]): ব্যাংক নিজে সুদ দিলে বা চার্জ কাটলে
            দাখিলাটা জাবেদা ছাড়া লেখারই পথ থাকত না, আর মানুষ তখন একটা
            ভুয়া রসিদ বানাত।
        --}}

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

        {{--
            ── নকশার উপরের অংশ — তারিখ, তারপর পুরো চওড়া বিবরণ ──────────

            ⛔ আগে তিনটা ঘর এক সারিতে ছিল (তারিখ · বিবরণ · উল্টো দাখিলা),
            আর বিবরণ পেত এক-তৃতীয়াংশ জায়গা। ⚠️ জাবেদার বিবরণ একটা
            **বাক্য** — "সেপ্টেম্বরের অবচয় ও অগ্রিম ভাড়ার সমন্বয়" — আর
            ঐটুকু ঘরে সেটা দেখাই যেত না।

            ⓘ উল্টো দাখিলার তারিখ, সংযুক্তি আর লেনদেন নম্বর নিচের সারিতে
            চলে গেছে — নকশায় ওগুলো ওখানেই।
        --}}
        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <div class="grid gap-3 sm:grid-cols-3">
                <x-ui.field name="trx_date" type="date" :label="__('accounts::field.date')"
                            :value="old('trx_date', $voucher->trx_date?->format('Y-m-d') ?? now()->format('Y-m-d'))"
                            required />
            </div>

            <label class="mt-3 block">
                <span class="mb-1 block text-sm font-medium">{{ __('core.table.narration') }}</span>
                <input type="text" name="narration" value="{{ old('narration', $voucher->narration) }}"
                       class="h-(--spacing-field) w-full rounded-(--radius-field) border
                              border-(--color-border) bg-(--color-surface-card) px-3">
            </label>
        </section>

        <section data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card)">
            <div class="overflow-x-auto">
                <table class="ui-grid">
                    <thead>
                        <tr>
                            <th scope="col">
                                {{ __('core.print.account') }}
                            </th>
                            {{--
                                ⭐ লাইনের বিবরণ খাতের ঠিক পাশে — নকশার মতো।
                                ⓘ আগে সবার ডানে ছিল, আর `lg` থেকে ছোট পর্দায়
                                **একেবারে লুকানো**। ⚠️ অথচ ছয় মাস পরে সারিটা
                                কেন বসেছিল তার একমাত্র উত্তর ওই ঘরেই।
                            --}}
                            <th scope="col">
                                {{ __('accounts::field.line_narration') }}
                            </th>
                            <th scope="col" style="width: 14rem"
>
                                {{ __('accounts::field.party') }}
                            </th>
                            @if ($costCenters->isNotEmpty())
                                <th scope="col" style="width: 11rem"
>
                                    {{ __('accounts::field.cost_center') }}
                                </th>
                            @endif
                            <th scope="col" style="width: 10rem"
                                class="num">
                                {{ __('core.table.debit') }}
                            </th>
                            <th scope="col" style="width: 10rem"
                                class="num">
                                {{ __('core.table.credit') }}
                            </th>
                        </tr>
                    </thead>

                    <tbody>
                        @for ($i = 0; $i < $rows; $i++)
                            @php $line = $existing[$i] ?? [] @endphp
                            <tr>
                                <td class="tight">
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

                                <td class="tight">
                                    <input type="text" name="lines[{{ $i }}][narration]"
                                           value="{{ $line['narration'] ?? '' }}"
                                           class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                                  border-(--color-border) bg-(--color-surface-card) px-2">
                                </td>


                                {{--
                                    সারির পক্ষ — কার নামে টাকাটা বসবে।

                                    ── কেন সারিতে, মাথায় নয় ───────────────
                                    পরিবেশকের রোজকার ঘটনা: ডিলার টাকাটা
                                    সরাসরি কোম্পানিকে দিলেন। তখন এক
                                    ভাউচারে **দুইটা আলাদা পক্ষ** — ডেবিটে
                                    সরবরাহকারী, ক্রেডিটে ডিলার। মাথার
                                    একটামাত্র পক্ষ দিয়ে ওটা লেখাই যেত না।

                                    ঐচ্ছিক, আর বেশিরভাগ জাবেদায় খালিই
                                    থাকবে — খরচ বা সমন্বয়ের সারিতে কোনো
                                    পক্ষ থাকে না।

                                    ধরন ও নাম একসাথে একটাই ঘরে, কারণ
                                    দুইটা আলাদা ঘর হলে একটা ভরে অন্যটা
                                    খালি রাখা যেত — আর তখন খতিয়ানে একটা
                                    আধা-পক্ষ বসত, যাকে কোনো রিপোর্ট
                                    খুঁজে পেত না।
                                --}}
                                <td class="tight">
                                    <select name="lines[{{ $i }}][party]"
                                            class="h-(--spacing-field) w-full rounded-(--radius-field)
                                                   border border-(--color-border)
                                                   bg-(--color-surface-card) px-2">
                                        <option value="">—</option>
                                        @foreach ($parties as $group)
                                            <optgroup label="{{ $group['label'] }}">
                                                @foreach ($group['options'] as $party)
                                                    <option value="{{ $group['type'] }}:{{ $party['id'] }}"
                                                            @selected(($line['party_type'] ?? null) === $group['type']
                                                                && ($line['party_id'] ?? null) == $party['id'])>
                                                        {{ $party['label'] }}
                                                    </option>
                                                @endforeach
                                            </optgroup>
                                        @endforeach
                                    </select>
                                </td>

                                {{--
                                    খরচের কেন্দ্র — কোন রুটের খরচ।

                                    কলামটা কেবল তখনই আসে যখন অন্তত একটা
                                    কেন্দ্র বসানো আছে। কেউ কেন্দ্র ব্যবহার
                                    না করলে প্রতিটা জাবেদায় একটা খালি ঘর
                                    জায়গা নিত আর কিছু বলত না।
                                --}}
                                @if ($costCenters->isNotEmpty())
                                    <td class="tight">
                                        <select name="lines[{{ $i }}][cost_center_id]"
                                                class="h-(--spacing-field) w-full rounded-(--radius-field)
                                                       border border-(--color-border)
                                                       bg-(--color-surface-card) px-2">
                                            <option value="">—</option>
                                            @foreach ($costCenters as $centre)
                                                <option value="{{ $centre->id }}"
                                                        @selected(($line['cost_center_id'] ?? null) == $centre->id)>
                                                    {{ $centre->name() }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </td>
                                @endif

                                <td class="tight">
                                    <input type="number" step="0.01" inputmode="decimal"
                                           name="lines[{{ $i }}][debit]" value="{{ $line['debit'] ?? '' }}"
                                           @input="recount()"
                                           class="num h-(--spacing-field) w-full rounded-(--radius-field) border
                                                  border-(--color-border) bg-(--color-surface-card) px-2 text-end">
                                </td>

                                <td class="tight">
                                    <input type="number" step="0.01" inputmode="decimal"
                                           name="lines[{{ $i }}][credit]" value="{{ $line['credit'] ?? '' }}"
                                           @input="recount()"
                                           class="num h-(--spacing-field) w-full rounded-(--radius-field) border
                                                  border-(--color-border) bg-(--color-surface-card) px-2 text-end">
                                </td>
                            </tr>
                        @endfor
                    </tbody>

                    <tfoot>
                        {{--
                            ⓘ কলামের ক্রম বদলেছে, তাই মোটের সারিও — সংখ্যা
                            দুইটা এখন ডানপ্রান্তে, আর "মোট" তার ঠিক বাঁয়ে।

                            ⚠️ পার্থক্যটা বাঁ পাশে দেখানো হয়, লুকানো হয় না:
                            কত টাকা কম পড়ছে জানলে ভুলটা খুঁজে পাওয়া সহজ।
                            ⛔ কিন্তু খালি ফর্মে নয় — কিছু টাইপ করার আগেই
                            লাল "পার্থক্য ০.০০" দেখালে সেটা ভুলের বার্তা হয়ে
                            দাঁড়াত, অথচ কেউ এখনো কিছু করেনি।
                        --}}
                        <tr class="bg-(--color-surface-app) font-semibold">
                            <td colspan="{{ $costCenters->isNotEmpty() ? 3 : 2 }}">
                                <span x-show="touched && ! balanced" x-cloak
                                      class="text-2xs font-normal text-(--color-danger)"
                                      x-text="'{{ __('accounts::message.difference') }} ' + format(Math.abs(debit - credit))">
                                </span>
                            </td>
                            <td class="text-end">{{ __('core.print.total') }}</td>
                            <td class="num" x-text="format(debit)">0.00</td>
                            <td class="num" x-text="format(credit)">0.00</td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            {{--
                ⭐ সবুজ বার্তা — নকশার নিজস্ব অংশ, ১৮ সেপ্টেম্বর ২০২৬।

                ⛔ আগে পর্দা কেবল **না মিললে** কথা বলত; মিললে চুপ থাকত।
                ⓘ আর চুপ থাকার দুইটা অর্থ হয়: "সব ঠিক আছে" আর "আমি
                এখনো কিছু দেখিনি" — ব্যবহারকারী কোনটা বুঝবেন তা বলা যায় না।

                ⭐ তাই এখন মিললে পর্দা নিজেই বলে — "পোস্ট করা যাবে"।
            --}}
            <p x-show="balanced" x-cloak
               class="m-3 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2
                      text-sm text-(--color-badge-success-ink)">
                {{ __('accounts::message.journal_balanced') }}
            </p>
        </section>

        {{--
            ── নকশার শেষ সারি ─────────────────────────────
                উল্টো দাখিলার তারিখ · সংযুক্তি · চেক/লেনদেন নম্বর

            ⓘ নকশায় প্রথম দুইটা আছে। ⚠️ তৃতীয়টা নেই, আর সেটা নকশার
            নিজের লাল লাইনের সাথেই বিরোধী: ব্যাংক যখন সুদ দেয় বা চার্জ
            কাটে, তখন দাখিলাটা জাবেদায় লেখা হয় — আর ঠিক তখনই ব্যাংক
            বিবরণীর সাথে মেলানোর জন্য নম্বরটা লাগে।

            ⛔ পোস্ট করার সময় সারিতে ব্যাংক-খাত থাকলে নম্বরটা
            বাধ্যতামূলক — ঘরটা না থাকলে পর্দা এমন একটা জিনিস চাইত
            যেটা দেওয়ার জায়গাই সে দেয়নি।
        --}}
        <section data-boxed
                 class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <div class="grid gap-3 sm:grid-cols-3">
                <x-ui.field name="reverse_on" type="date"
                            :label="__('accounts::field.reverse_on')"
                            :value="old('reverse_on', $voucher->reverse_on?->format('Y-m-d'))"
                            :hint="__('accounts::message.reverse_on_hint')" />

                @include('accounts::voucher.partials.attachment-field')

                <x-ui.field name="instrument_no"
                            :label="__('accounts::field.instrument_no')"
                            :hint="__('accounts::message.instrument_no_when_bank')"
                            :value="old('instrument_no', $voucher->instrument_no)" />
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
        <script @nonce>
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
                    /*
                     * $root, $el নয় — আর এই এক অক্ষরেই ফিচারটা মরে ছিল।
                     *
                     * @input="recount()" থেকে ডাকা হলে Alpine-এর $el মানে
                     * **যে ঘরে টাইপ করা হয়েছে সেই input-টা**, কম্পোনেন্টের
                     * গোড়া নয়। একটা input-এর ভেতরে আর কোনো input থাকে না,
                     * তাই তালিকাটা সবসময় খালি আসত, debit ও credit দুইটাই
                     * ০ থাকত, balanced কখনো true হত না — আর "Save and post"
                     * বোতামটা balanced দেখে নিষ্ক্রিয় থাকে।
                     *
                     * ফল: **কোনো জাবেদা কখনো সেভ করা যেত না।** কনসোলে এরর
                     * নেই, পর্দা দেখতে নিখুঁত, শুধু বোতামে ক্লিক করা যায় না।
                     * নগদ গণনার পর্দায় হুবহু একই ভুল ছিল — একই দিনে ধরা
                     * পড়েছে দুইটাই।
                     *
                     * $root কম্পোনেন্টের গোড়া, যেখান থেকেই ডাকা হোক।
                     */
                    recount() {
                        const sum = (sel) => [...this.$root.querySelectorAll(sel)]
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
