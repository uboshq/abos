{{--
    টাকা ও হেফাজত — কোন টাকা কার কাছে আছে।

    এক পর্দায় সব: প্রতিটা নগদ কাউন্টার তার হেফাজতকারীসহ, প্রতিটা ব্যাংক
    ও MFS খাত, আর সবার শেষে পথের টাকা — যেটা কারও হাতে নেই।

    প্রতিটা সংখ্যা তার উৎসে নিয়ে যায় (নিয়ম ১)। যে সংখ্যা ক্লিক করা যায়
    না সেটা বিশ্বাস করতে হয়, যাচাই করা যায় না।
--}}
@php
    /*
        কলাম ধরে — `x-ui.table` স্লট পড়ে না, সারি আসে :rows থেকে।

        ── আগের সিদ্ধান্তটা বদলাল কেন ──────────────────────────────────
        এই টেবিলটা ইচ্ছে করে হাতে লেখা ছিল, আর কারণ ছিল তিনটা: ঘরগুলো
        সাধারণ লেখা নয় (ব্যাজ, ম্লান শূন্য), আর শেষে একটা সারি যেটা
        কোনো রেকর্ড নয়।

        তিনটার একটাও আর টেকে না। ব্যাজ ও রং এখন `render` ক্লোজার থেকে
        আসে, আর শেষ সারিটার জন্য কম্পোনেন্টে `totalsLabel` যোগ করা
        হয়েছে — কারণ ওই সারিটা যোগফল নয়, ওটা বলে "এই টাকা এই মুহূর্তে
        কারও হেফাজতে নেই"।

        আর কারণটা আজ আলাদা: হাতে লেখা টেবিল **থিম মানে না**।
    */
    $columns = [
        ['key' => 'code', 'label' => __('core.table.code'), 'width' => '110px',
         'render' => fn ($r) => view('accounts::custody.partials.code', ['row' => $r])],
        ['key' => 'name', 'label' => __('core.table.name'),
         'render' => fn ($r) => view('accounts::custody.partials.name', ['row' => $r])],
        ['key' => 'kind', 'label' => __('accounts::custody.kind'), 'width' => '120px',
         'render' => fn ($r) => $r['kind']],
        ['key' => 'holder', 'label' => __('accounts::custody.holder'), 'width' => '190px',
         'render' => fn ($r) => view('accounts::custody.partials.holder', ['row' => $r])],
        ['key' => 'balance', 'label' => __('accounts::custody.balance'),
         'numeric' => true, 'width' => '160px',
         'render' => fn ($r) => $r['balance']],
        ['key' => 'sent', 'label' => __('accounts::custody.sent'),
         'numeric' => true, 'width' => '140px',
         'render' => fn ($r) => view('accounts::custody.partials.sent', ['row' => $r])],
    ];
@endphp

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
        <div data-msg class="mb-4 rounded-(--radius-card) border border-(--color-badge-warning-bg)
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
    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                bg-(--color-surface-card) shadow-(--shadow-card)">
        <div class="table-responsive">
            {{-- শেষ সারিটা কারও নামের পাশে বসে না, আর সেটাই পুরো কথা:
                 টাকাটা ড্রয়ার ছেড়েছে, কেউ এখনো নেয়নি। --}}
            <x-ui.table :rows="$rows"
                        :columns="$columns"
                        {{-- শেষ সারিটার প্রতিটা ঘর ভরা, আর সেটা ইচ্ছাকৃত।

                             প্রথম রূপান্তরে শুধু `balance` দেওয়া হয়েছিল, তাই
                             "কে ধরে আছে" কলামটা ফাঁকা পড়ে থাকত। অথচ পুরো
                             সারিটার অস্তিত্বের কারণই ওই ঘরটা: টাকাটা ড্রয়ার
                             ছেড়েছে, কেউ এখনো নেয়নি — অর্থাৎ ওটা এই মুহূর্তে
                             কারও হেফাজতে নেই। ফাঁকা ঘর দেখে মনে হত তথ্যটা
                             জানা নেই, অথচ তথ্যটাই হল "কারও কাছে নেই"। --}}
                        :totals="[
                            'name' => __('accounts::custody.on_the_road'),
                            'kind' => __('accounts::custody.kind_transit'),
                            'holder' => __('accounts::custody.nobody_holds_it'),
                            'balance' => $transit,
                            'sent' => '—',
                        ]"
                        :totalsLabel="\App\Modules\Accounts\Services\StandardChart::CASH_IN_TRANSIT"
                        :empty="__('core.empty.no_results')" />
        </div>
    </div>

    {{-- ── পথে কার কাছে কত ──────────────────────────────────────────
         যোগফলটা উপরের সারিতে আছে; এখানে **কে কাকে** পাঠিয়েছেন।
         যোগফল দেখে কেউ খুঁজতে যেতে পারে না, দলিল দেখে পারে। --}}
    @if ($onTheRoad->isNotEmpty())
        <h2 class="mt-6 mb-2 text-sm font-semibold text-(--color-ink-muted)">
            {{ __('accounts::custody.on_the_road_detail') }}
        </h2>

        <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
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
