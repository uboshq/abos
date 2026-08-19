{{--
    টাকা ও হেফাজত — কোন টাকা কার কাছে আছে।

    এক পর্দায় সব: প্রতিটা নগদ কাউন্টার তার হেফাজতকারীসহ, প্রতিটা ব্যাংক
    ও MFS খাত, আর সবার শেষে পথের টাকা — যেটা কারও হাতে নেই।

    প্রতিটা সংখ্যা তার উৎসে নিয়ে যায় (নিয়ম ১)। যে সংখ্যা ক্লিক করা যায়
    না সেটা বিশ্বাস করতে হয়, যাচাই করা যায় না।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('accounts::custody.title') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('accounts::custody.title')"
                          :subtitle="__('accounts::custody.subtitle')">
            <x-slot:actions>
                @can('accounts.transfer.create')
                    <x-ui.button tone="primary" icon="handover"
                                 :href="route('accounts.transfer.create')">
                        {{ __('accounts::action.new_transfer') }}
                    </x-ui.button>
                @endcan
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>

    {{-- ── আমার গ্রহণের অপেক্ষায় ────────────────────────────────────
         ইনবক্সটা সবার উপরে, কারণ এটাই একমাত্র অংশ যেখানে **আমার**
         কিছু করার আছে। বাকি পর্দাটা তথ্য; এটা কাজ।

         না দেখালে হাতে হাতে দেওয়া টাকা সপ্তাহখানেক অগৃহীত পড়ে থাকে,
         আর তখন দুই ধাপের হস্তান্তর নিছক একটা বাড়তি বোতাম হয়ে যায়। --}}
    @if ($waitingForMe->isNotEmpty())
        <div class="mb-4 rounded-(--radius-card) border border-(--color-badge-warning-bg)
                    bg-(--color-badge-warning-bg) p-4">
            <p class="flex items-center gap-2 text-sm font-semibold text-(--color-badge-warning-ink)">
                <x-ui.icon name="handover" :size="18" />
                {{ trans_choice('accounts::custody.waiting_for_you', $waitingForMe->count(),
                    ['count' => $waitingForMe->count()]) }}
            </p>

            <div class="mt-2 flex flex-wrap gap-2">
                @foreach ($waitingForMe as $transfer)
                    <a href="{{ route('accounts.transfer.show', $transfer) }}"
                       class="rounded-(--radius-field) bg-(--color-surface-card) px-3 py-1.5 text-sm
                              hover:underline">
                        <span class="font-medium">{{ $transfer->document_no }}</span>
                        <span class="num ms-2">{{ \App\Core\Support\Money::format($transfer->amount) }}</span>
                    </a>
                @endforeach
            </div>
        </div>
    @endif

    {{-- ── কার কাছে কত ────────────────────────────────────────────────

         টেবিলটা হাতে লেখা, `x-ui.table` দিয়ে নয়: এখানকার ঘরগুলো
         সাধারণ লেখা নয় — হেফাজতকারী খালি থাকলে সতর্কতার ব্যাজ, শূন্য
         "পথে" ম্লান, আর শেষে একটা যোগফলের সারি যেটা কোনো রেকর্ড নয়।
         শেয়ার্ড কম্পোনেন্টে এই তিনটা শর্ত জুড়লে বাকি চল্লিশটা তালিকাও
         ওগুলো বইত (§১৯.৮)। টোকেন ও মাপ হুবহু একই। --}}
    <div class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                bg-(--color-surface-card) shadow-(--shadow-card)">
        <div class="table-responsive">
            <table class="table-cards w-full border-collapse text-sm">
                <thead>
                    <tr class="border-b border-(--color-border) bg-(--color-surface-app)">
                        @foreach ([
                            ['label' => __('core.table.code'), 'num' => false, 'w' => '110px'],
                            ['label' => __('core.table.name'), 'num' => false, 'w' => null],
                            ['label' => __('accounts::custody.kind'), 'num' => false, 'w' => '120px'],
                            ['label' => __('accounts::custody.holder'), 'num' => false, 'w' => '190px'],
                            ['label' => __('accounts::custody.balance'), 'num' => true, 'w' => '160px'],
                            ['label' => __('accounts::custody.sent'), 'num' => true, 'w' => '140px'],
                        ] as $head)
                            <th @class([
                                    'px-3 py-2 text-end font-medium text-(--color-ink-muted) whitespace-nowrap',
                                    'num' => $head['num'],
                                ])
                                @if ($head['w']) style="width: {{ $head['w'] }}" @endif
                                scope="col">{{ $head['label'] }}</th>
                        @endforeach
                    </tr>
                </thead>

                <tbody>
                    @foreach ($rows as $row)
                        <tr class="border-b border-(--color-border) transition-colors
                                   hover:bg-(--color-surface-hover)">
                            <td data-label="{{ __('core.table.code') }}" class="px-3 py-2.5 align-middle">
                                <a href="{{ $row['url'] }}"
                                   class="font-medium text-(--color-brand-600) hover:underline">
                                    {{ $row['code'] }}
                                </a>
                            </td>

                            <td data-label="{{ __('core.table.name') }}" class="px-3 py-2.5 align-middle">
                                {{ $row['name'] }}
                                @if ($row['primary'])
                                    <span class="ms-1 text-2xs text-(--color-ink-muted)">
                                        {{ __('accounts::custody.primary') }}
                                    </span>
                                @endif
                            </td>

                            <td data-label="{{ __('accounts::custody.kind') }}"
                                class="px-3 py-2.5 align-middle text-(--color-ink-muted)">
                                {{ $row['kind'] }}
                            </td>

                            {{-- হেফাজতকারীহীন নগদ কাউন্টার — এটাই এই পর্দার
                                 সবচেয়ে কাজের ঘর।

                                 কারও নাম না থাকলে টাকাটা কার্যত অভিভাবকহীন:
                                 ঘাটতি হলে কেউ দায়ী নয়, আর কেউ দায়ী না হলে
                                 ঘাটতি নিয়ে কেউ প্রশ্নও করে না। খালি ঘর রেখে
                                 দিলে ওটা চোখেই পড়ত না, তাই লেখা হয়। --}}
                            <td data-label="{{ __('accounts::custody.holder') }}"
                                class="px-3 py-2.5 align-middle">
                                @if ($row['holder'])
                                    {{ $row['holder'] }}
                                @elseif ($row['kind'] === __('accounts::custody.kind_bank'))
                                    <span class="text-(--color-ink-disabled)">—</span>
                                @else
                                    <span class="inline-flex items-center gap-1 rounded-full
                                                 bg-(--color-badge-warning-bg) px-2 py-0.5 text-2xs
                                                 font-semibold text-(--color-badge-warning-ink)">
                                        <x-ui.icon name="help" :size="12" />
                                        {{ __('accounts::custody.nobody') }}
                                    </span>
                                @endif
                            </td>

                            <td data-label="{{ __('accounts::custody.balance') }}"
                                class="num px-3 py-2.5 align-middle font-medium">
                                {{ $row['balance'] }}
                            </td>

                            {{-- পথে পাঠানো — শূন্য হলে ম্লান, কারণ শূন্যটা
                                 কোনো খবর নয়; খবরটা হলো "করিমের পাঠানো
                                 ১২,০০০ এখনো কেউ নেয়নি"। --}}
                            <td data-label="{{ __('accounts::custody.sent') }}"
                                @class([
                                    'num px-3 py-2.5 align-middle',
                                    'text-(--color-ink-disabled)' => ! preg_match('/[1-9১-৯]/u', $row['sent']),
                                ])>
                                {{ $row['sent'] }}
                            </td>
                        </tr>
                    @endforeach

                    {{-- ── শেষ সারি: পথের টাকা ─────────────────────────
                         এটা কারও নামের পাশে বসে না, আর সেটাই পুরো কথা।
                         টাকাটা ড্রয়ার ছেড়েছে, কেউ এখনো নেয়নি — অর্থাৎ
                         এই মুহূর্তে ওটা কারও হেফাজতে নেই। --}}
                    <tr class="border-t-2 border-(--color-border) bg-(--color-surface-app)">
                        <td class="px-3 py-2.5 align-middle text-(--color-ink-muted)">
                            {{ \App\Modules\Accounts\Services\StandardChart::CASH_IN_TRANSIT }}
                        </td>
                        <td class="px-3 py-2.5 align-middle font-medium">
                            {{ __('accounts::custody.on_the_road') }}
                        </td>
                        <td class="px-3 py-2.5 align-middle text-(--color-ink-muted)">
                            {{ __('accounts::custody.kind_transit') }}
                        </td>
                        <td class="px-3 py-2.5 align-middle text-(--color-ink-muted)">
                            {{ __('accounts::custody.nobody_holds_it') }}
                        </td>
                        <td class="num px-3 py-2.5 align-middle font-semibold">{{ $transit }}</td>
                        <td class="num px-3 py-2.5 align-middle text-(--color-ink-disabled)">—</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    {{-- ── পথে কার কাছে কত ──────────────────────────────────────────
         যোগফলটা উপরের সারিতে আছে; এখানে **কে কাকে** পাঠিয়েছেন।
         যোগফল দেখে কেউ খুঁজতে যেতে পারে না, দলিল দেখে পারে। --}}
    @if ($onTheRoad->isNotEmpty())
        <h2 class="mt-6 mb-2 text-sm font-semibold text-(--color-ink-muted)">
            {{ __('accounts::custody.on_the_road_detail') }}
        </h2>

        <div class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card) shadow-(--shadow-card)">
            @foreach ($onTheRoad as $transfer)
                <a href="{{ route('accounts.transfer.show', $transfer) }}"
                   class="flex items-center gap-3 border-b border-(--color-border) px-4 py-3
                          transition-colors last:border-b-0 hover:bg-(--color-surface-hover)">
                    <span class="grid size-8 shrink-0 place-items-center rounded-(--radius-field)
                                 bg-(--color-surface-app) text-(--color-brand-600)">
                        <x-ui.icon name="handover" :size="16" />
                    </span>

                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-medium">
                            {{ $transfer->fromTill?->name() }}
                            <span class="text-(--color-ink-muted)">→</span>
                            {{ $transfer->toTill?->name() ?? $transfer->toAccount?->label() }}
                        </span>
                        <span class="block truncate text-2xs text-(--color-ink-muted)">
                            {{ $transfer->document_no }}
                            · {{ \App\Core\Support\DateFormat::format($transfer->trx_date) }}
                            @if ($transfer->giver)
                                · {{ $transfer->giver->name }}
                            @endif
                        </span>
                    </span>

                    <span class="num text-sm font-semibold">
                        {{ \App\Core\Support\Money::format($transfer->amount) }}
                    </span>

                    <x-ui.icon name="chevron_right" :size="16"
                               class="text-(--color-ink-disabled) rtl:rotate-180" />
                </a>
            @endforeach
        </div>
    @endif
</x-layouts.app>
