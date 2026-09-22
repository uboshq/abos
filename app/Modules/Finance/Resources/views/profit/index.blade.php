{{--
    লাভ বণ্টন — ঘোষণা, আর কে কত পেলেন।

    ── ⭐ মালিকের নকশা, ২২ সেপ্টেম্বর ২০২৬ ─────────────────────────────
    *"টাকাটা তুলে নেবেন"*, আর *"র থাকলে বছর শেষে capital-এ যোগ হবে বা
    invest-এ"*।

    ⓘ তাই ঘোষণার দিন অঙ্কটা সঞ্চিত মুনাফা থেকে **প্রদেয় মুনাফায়** নামে,
    সরাসরি মূলধনে নয় — কারণটা [[ProfitDistribution]]-এ লেখা।

    ── ⚠️ দুই ধাপ, আর সেটা ইচ্ছাকৃত ────────────────────────────────────
    আগে **দেখুন**, তারপর **ঘোষণা করুন**। ⛔ এক ধাপে করলে ভুল অঙ্ক বসিয়ে
    চাপ দিলেই খাতায় বসে যেত, আর ফেরানোর একমাত্র পথ উল্টো দাখিলা।

    ⓘ দেখার ধাপে কিছুই লেখা হয় না — সংখ্যা বদলে আবার দেখা যায়।
--}}
@php
    $money = fn ($n) => \App\Core\Support\Money::format((string) $n);
    $trim = fn (string $n) => rtrim(rtrim($n, '0'), '.');

    /*
     * ⭐ ভাগের যোগফল — পর্দাতেই মিলিয়ে দেখার জন্য।
     *
     * ⚠️ সংখ্যাটা চাওয়া অঙ্কের সমান না হলে কিছু একটা ভুল, আর সেটা
     * ঘোষণার **আগে** জানা দরকার। ⓘ বড়-অবশিষ্ট নিয়মে ভাগ হয় বলে
     * সমান হওয়ারই কথা।
     */
    $previewTotal = collect($preview ?? [])
        ->reduce(fn (string $sum, $r) => bcadd($sum, $r['amount'], 4), '0');

    $previewColumns = [
        ['key' => 'name', 'label' => __('finance::field.who')],
        ['key' => 'share', 'label' => __('finance::field.share'), 'numeric' => true,
         'render' => fn ($r) => $r['share'] === null ? '—' : $trim((string) $r['share']).'%'],
        ['key' => 'amount', 'label' => __('finance::field.gets'), 'numeric' => true,
         'render' => fn ($r) => $money($r['amount'])],
    ];

    $historyColumns = [
        ['key' => 'trx_date', 'label' => __('core.print.date'),
         'render' => fn ($r) => $r->trx_date?->format('d-m-Y')],
        ['key' => 'document_no', 'label' => __('core.table.document')],
        ['key' => 'person', 'label' => __('finance::field.who'),
         'render' => fn ($r) => $r->person?->name() ?? '—'],
        ['key' => 'share_percent', 'label' => __('finance::field.share'), 'numeric' => true,
         'render' => fn ($r) => $r->share_percent === null ? '—' : $trim((string) $r->share_percent).'%'],

        /*
         * ⓘ কোন মুনাফার উপর ভাগ হয়েছিল — সারিতেই লেখা।
         * ⚠️ পরে অনুপাত বদলালে পুরনো ঘোষণার ভাগ বদলায় না, আর এই
         * কলামটাই বলে দেয় সংখ্যাটা কোথা থেকে এসেছিল।
         */
        ['key' => 'profit_base', 'label' => __('finance::field.out_of'), 'numeric' => true,
         'render' => fn ($r) => $money($r->profit_base)],

        ['key' => 'amount', 'label' => __('finance::field.gets'), 'numeric' => true,
         'render' => fn ($r) => $money($r->amount)],
    ];
@endphp
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('finance::menu.profit_share') }}</x-slot:title>

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
            {{ $errors->first() }}
        </div>
    @endif

    {{--
        ⓘ টাকাটা কোথায় যায় — এক লাইনে, পর্দাতেই।

        ⚠️ এটা সাজসজ্জা নয়: অংশীদার পরে জিজ্ঞেস করলে *"আমার লাভ কোথায়"*,
        উত্তরটা এই পাতাতেই থাকা দরকার। ⛔ না থাকলে মানুষ ধরে নেয় টাকাটা
        মূলধনে গেছে, আর তখন তোলার সময় হিসাব নিয়ে তর্ক হয়।
    --}}
    <p class="mb-4 rounded-(--radius-field) bg-(--color-surface-sunken) px-3 py-2 text-sm">
        {{ __('finance::message.profit_flow') }}
    </p>

    <div class="grid gap-4 lg:grid-cols-2 lg:items-start">
        <div class="min-w-0 space-y-4">
            {{-- ── ঘোষণার ফর্ম ─────────────────────────────────────── --}}
            <section data-boxed
                     class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                <h2 class="mb-3 font-semibold">{{ __('finance::action.declare_profit') }}</h2>

                <form method="POST" action="{{ route('finance.profit.preview') }}" class="space-y-3">
                    @csrf

                    <x-ui.field name="profit" type="number" step="0.01" inputmode="decimal"
                                :label="__('finance::field.profit_to_share')"
                                :value="old('profit', $profit)" numeric required />

                    {{--
                        ⓘ `max` বসানো — তারিখ বাছাইয়েই আগামীকাল ধরা যায় না।

                        ⚠️ এটা পাহারা নয়, সৌজন্য — আসল বাধাটা
                        [[ProfitDistributionController]]-এ `before_or_equal:today`।
                        ⓘ দুইটাই থাকে: ব্রাউজারটা ভুলটা আগে থামায়,
                        সার্ভারটা শেষে রুখে দেয়।
                    --}}
                    <x-ui.field name="trx_date" type="date"
                                :label="__('core.print.date')"
                                :max="now()->format('Y-m-d')"
                                :value="old('trx_date', now()->format('Y-m-d'))" required />

                    <x-ui.field name="narration"
                                :label="__('finance::field.narration')"
                                :value="old('narration')" />

                    <button type="submit"
                            class="min-h-(--spacing-touch) rounded-(--radius-field) border border-(--color-border)
                                   px-4 text-sm font-semibold">
                        {{ __('finance::action.see_the_split') }}
                    </button>
                </form>
            </section>

            {{-- ── ভাগটা, আর তারপর ঘোষণা ────────────────────────────── --}}
            @if ($preview !== null)
                <section data-boxed
                         class="rounded-(--radius-card) border border-(--color-border)
                                border-l-4 border-l-(--color-brand-500) bg-(--color-surface-card) p-4">
                    <h2 class="mb-3 font-semibold">{{ __('finance::field.the_split') }}</h2>

                    <x-ui.table :rows="$preview" :columns="$previewColumns"
                                :empty="__('finance::validation.nobody_has_a_share')" />

                    {{--
                        ⭐ যোগফল আর চাওয়া অঙ্ক পাশাপাশি — মিলিয়ে দেখার জন্য।

                        ⛔ দুইটা আলাদা হলে ঘোষণা করা যাবে না, কারণ দাখিলার
                        ডেবিট বসে যোগফলের উপর। ⓘ বড়-অবশিষ্ট নিয়মে সমান
                        হওয়ারই কথা, কিন্তু "কথা" আর "দেখা" এক নয়।
                    --}}
                    <p class="mt-3 flex items-center justify-between border-t border-(--color-border) pt-2 text-sm">
                        <span class="text-(--color-ink-muted)">{{ __('finance::field.split_total') }}</span>
                        <span class="num font-semibold">{{ $money($previewTotal) }}</span>
                    </p>

                    @if ($preview !== [])
                        <form method="POST" action="{{ route('finance.profit.declare') }}" class="mt-3">
                            @csrf
                            <input type="hidden" name="profit" value="{{ $profit }}">
                            <input type="hidden" name="trx_date" value="{{ old('trx_date', now()->format('Y-m-d')) }}">
                            <input type="hidden" name="narration" value="{{ old('narration') }}">

                            <button type="submit"
                                    class="min-h-(--spacing-touch) w-full rounded-(--radius-field)
                                           bg-(--color-brand-600) px-4 text-sm font-semibold text-white">
                                {{ __('finance::action.declare_now') }}
                            </button>

                            <p class="mt-2 text-2xs text-(--color-ink-muted)">
                                {{ __('finance::message.declare_is_final') }}
                            </p>
                        </form>
                    @endif
                </section>
            @endif
        </div>

        {{-- ── কে কী পেয়েছেন, আগে ────────────────────────────────────── --}}
        <div class="min-w-0 space-y-4">
            <section data-boxed
                     class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                <h2 class="mb-3 font-semibold">{{ __('finance::field.past_distributions') }}</h2>

                <x-ui.table :rows="$history" :columns="$historyColumns"
                            :empty="__('finance::message.no_distribution_yet')" />

                <x-ui.pager :rows="$history" />
            </section>
        </div>
    </div>
</x-layouts.app>
