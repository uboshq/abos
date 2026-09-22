{{--
    কোথায় সই বসানো যায়, আর কোথায় বসানো আছে।

    ── ⭐ মালিকের প্রশ্ন, ২২ সেপ্টেম্বর ২০২৬ ────────────────────────────
    *"কোথায় কোথায় সই চান"* জিজ্ঞেস করতে গিয়ে দেখা গেল **তিনিই জানতেন
    না কোথায় কোথায় বসানো যায়** — তালিকাটা সোর্স কোডে, আর পর্দায় সেটা
    দেখা যেত কেবল একটা নতুন নিয়ম বানানোর ড্রপডাউনের ভিতরে।

    ── ⛔ কেন অনুপস্থিতিটাই আসল তথ্য ───────────────────────────────────
    `assertClear()` কোনো ছক না পেলে **চুপচাপ ছেড়ে দেয়**। ⚠️ অর্থাৎ
    একটাও নিয়ম না থাকলেও প্রতিটা পর্দা স্বাভাবিক চলে, আর কোথাও কিছু
    বলে না। ⓘ ২২ সেপ্টেম্বর সকাল পর্যন্ত লাইভে তিনটা ছক ছিল, আর মালিক
    যে কোম্পানিতে বসেন সেখানে **একটাও নয়** — মাসের পর মাস।

    ⭐ তাই এই পর্দায় **যেগুলো নেই সেগুলোও সারি পায়**। কেবল বসানো
    নিয়মগুলো দেখালে প্রশ্নটার উত্তরই মিলত না।

    ── ⚠️ সংকেত নিয়ে একটা কথা ─────────────────────────────────────────
    ⓘ সংকেত (`AN007`) **কোম্পানি-প্রতি ক্রম** — একই সংকেত অন্য
    কোম্পানিতে অন্য নিয়ম হতে পারে। ⛔ "সব কোম্পানিতে এক" ধরে নেবেন না;
    আজ মিলছে কেবল কারণ তিন কোম্পানিতে ছকের সেট হুবহু এক।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('approval::menu.coverage') }}</x-slot:title>

    <div data-boxed class="mb-3 overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form method="GET" class="contents">
            {{-- ⓘ খোঁজা বন্ধ: সারিগুলো কোডে বাঁধা, আর কন্ট্রোলার `q` পড়ে না।
                 ⛔ বসালে সেটা একটা মৃত ঘর হত। --}}
            <x-ui.toolbar :title="__('approval::menu.coverage')"
                          :subtitle="__('approval::message.coverage_note')"
                          :count="__('approval::message.coverage_count', ['on' => $covered, 'all' => $total])"
                          :search="false" :filter="false" :density="false" :export="false" :columns="[]">
                <x-slot:actions>
                    <x-ui.button tone="secondary" :href="route('approval.flow.index')">
                        {{ __('approval::menu.flows') }}
                    </x-ui.button>
                    <x-ui.button tone="primary" icon="plus" :href="route('approval.flow.create')">
                        {{ __('approval::action.new_flow') }}
                    </x-ui.button>
                </x-slot:actions>
            </x-ui.toolbar>
        </form>
    </div>

    <div class="space-y-3">
        @foreach ($rows as $module => $block)
            @php
                $on = collect($block['actions'])->filter(fn ($row) => $row['flow'] && ! $row['dormant'])->count();
                $all = count($block['actions']);
            @endphp

            <section data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
                <h2 class="flex flex-wrap items-center gap-3 border-b border-(--color-border)
                           bg-(--color-section-head) px-4 py-2 font-semibold">
                    <span class="flex-1">{{ $block['label'] }}</span>

                    <span @class([
                              'rounded-full px-2 py-0.5 text-xs tabular-nums',
                              'bg-(--color-badge-success-bg) text-(--color-badge-success-ink)' => $on === $all,
                              'bg-(--color-badge-pending-bg) text-(--color-badge-pending-ink)' => $on > 0 && $on < $all,
                              'bg-(--color-surface-muted) text-(--color-ink-muted)' => $on === 0,
                          ])>{{ __('approval::message.coverage_count', ['on' => $on, 'all' => $all]) }}</span>
                </h2>

                <x-ui.table
                    :rows="$block['actions']"
                    :empty="__('core.empty.no_results')"
                    :columns="[
                        [
                            'key' => 'where',
                            'label' => __('approval::field.action'),
                            'render' => fn ($row) => $row['label'],
                        ],
                        [
                            'key' => 'state',
                            'label' => __('core.table.status'),
                            'width' => '9rem',
                            'render' => fn ($row) => view('approval::flow.partials.coverage-state', ['row' => $row]),
                        ],
                        [
                            'key' => 'who',
                            'label' => __('approval::field.approver'),
                            'render' => fn ($row) => view('approval::flow.partials.coverage-who', [
                                'row' => $row,
                                'names' => $names,
                            ]),
                        ],
                        [
                            'key' => 'threshold',
                            'label' => __('approval::field.threshold'),
                            'numeric' => true,
                            'width' => '10rem',
                            'render' => fn ($row) => $row['flow'] === null
                                ? ''
                                : ($row['flow']->threshold_amount === null
                                    ? __('approval::action.always')
                                    : \App\Core\Support\Money::format($row['flow']->threshold_amount)),
                        ],
                        [
                            'key' => 'actions',
                            'label' => __('core.table.actions'),
                            'width' => '7rem',
                            'render' => fn ($row) => view('approval::flow.partials.coverage-edit', ['row' => $row]),
                        ],
                    ]" />
            </section>
        @endforeach
    </div>
</x-layouts.app>
