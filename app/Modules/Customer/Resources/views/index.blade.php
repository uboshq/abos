{{--
    গ্রাহক তালিকা — শেয়ার্ড কম্পোনেন্টের উপর, নিজের কিছু নয়।

    এটাই Phase 2-এর আসল পরীক্ষা (সেকশন ২.৩): এখানে টেবিল, টুলবার বা ব্যাজ
    নতুন করে লিখতে হলে বুঝতে হবে ভিত্তিতে ফাঁক আছে। প্রথম চেষ্টায় ঠিক
    সেটাই হয়েছিল — ঘরের ভেতর লিংক বসাতে HtmlString হাতে বানাতে হচ্ছিল।
    সমাধান স্ক্রিনে নয়, কম্পোনেন্টে: কলাম এখন নিজের render দিতে পারে।
--}}
@php
    $columns = [
        [
            'key' => 'sl',
            'label' => __('core.table.serial'),
            'width' => '4rem',
            'numeric' => true,
            'render' => fn ($c, $i) => $customers->firstItem() + $i,
        ],
        /*
         * ⭐ কোড নিজের কলামে — মালিকের দেওয়া ক্রম, ১৯ সেপ্টেম্বর ২০২৬:
         * SL# · পার্টি কোড · নাম · পয়েন্ট · এরিয়া · পূর্ণ ঠিকানা · মালিক ·
         * মোবাইল · বকেয়া · অবস্থা · কাজ।
         *
         * ⓘ আগে কোডটা নামের নিচে ছোট করে বসত। আলাদা কলামে থাকলে কোড ধরে
         * সাজানো, লুকানো আর রপ্তানি করা যায় — আর কাগজ মেলানোর সময় কোডই
         * খোঁজা হয়।
         */
        [
            'key' => 'code',
            'label' => __('customer::field.code'),
            'width' => '7rem',
            'render' => fn ($c) => view('customer::partials.name-link', ['customer' => $c, 'text' => $c->code]),
        ],
        [
            'key' => 'name_en',
            'label' => __('customer::field.name'),
            // স্পষ্ট প্রস্থ, নাহলে বাংলা নাম কয়েক লাইনে ভাঙে —
            // বাকি কলামগুলোর নির্দিষ্ট প্রস্থের পর যা থাকে তাতেই
            // নামটা চাপা পড়ে যায়
            'width' => '16rem',
            'render' => fn ($c) => view('customer::partials.name-link', ['customer' => $c, 'text' => $c->name()]),
        ],
        /*
         * ⚠️ শিরোনাম মইয়ের নিজের নাম থেকে — `master_data::level.*`। মালিক
         * একদিন আবার নাম বদলালে তালিকাও সাথে সাথে বদলায়। ⓘ "এরিয়া" এখন
         * `territory` চাবি ([[Customer::ladderNode()]])।
         */
        [
            'key' => 'point',
            'label' => __('master_data::level.point'),
            'width' => '9rem',
            'render' => fn ($c) => $c->ladderNode(\App\Modules\MasterData\Models\Location::POINT)?->name() ?? '—',
        ],
        [
            'key' => 'area',
            'label' => __('master_data::level.territory'),
            'width' => '9rem',
            'render' => fn ($c) => $c->ladderNode(\App\Modules\MasterData\Models\Location::TERRITORY)?->name() ?? '—',
        ],
        [
            'key' => 'address',
            'label' => __('customer::field.full_address'),
            'width' => '16rem',
            'render' => fn ($c) => $c->address() ?: '—',
        ],
        [
            'key' => 'owner_name',
            'label' => __('customer::field.owner_name'),
            'width' => '11rem',
            'render' => fn ($c) => $c->owner_name ?: '—',
        ],
        ['key' => 'phone', 'label' => __('customer::field.phone'), 'width' => '9rem'],
        [
            'key' => 'outstanding',
            'total' => 'money',
            'raw' => fn ($c) => $c->outstanding_in_view ?? $c->outstanding(),
            'label' => __('customer::field.outstanding'),
            'numeric' => true,
            'width' => '10rem',
            /*
             * অঙ্কটাই লিংক — নিয়ম ১, আর `#transactions` অংশটাই আসল কাজ।
             *
             * ── কেন টুকরাটা ছাড়া লিংকটা অর্থহীন ছিল ─────────────────
             * ২৮ আগস্ট ২০২৬-এ মালিক জিজ্ঞেস করেছেন: *"hyper link clic
             * korle ki asar kotha ki asche?"* মেপে দেখা গেল অঙ্কের
             * লিংক আর নামের লিংক **একই জায়গায়** যেত — `/customers/10`।
             * অর্থাৎ সংখ্যাটায় ক্লিক করে নামে ক্লিক করার চেয়ে এক বিন্দু
             * বেশি কিছু জানা যেত না।
             *
             * নিয়ম ১ বলে সংখ্যা তার **উৎসে** নিয়ে যাবে — ৫০ লাখ টাকা
             * কোন কোন নথি মিলে হলো। উৎসটা ওই পাতাতেই আছে, কিন্তু
             * প্রায় ৭৫০px নিচে, আর ক্লিক নামাত পাতার মাথায়।
             *
             * মজুদের তালিকা এটা আগে থেকেই ঠিক করত (`#movements`)।
             * এখানে কেবল টুকরাটা লেখা হয়নি বলে বাকি ছিল।
             */
            'render' => fn ($c) => view('ui.amount-link', [
                // ⭐ হেডারে বাছা শাখায় ([[Customer::scopeWithOutstandingInView()]])
                'value' => bcadd((string) ($c->outstanding_in_view ?? $c->outstanding()), '0', 4),
                'href' => route('customer.show', $c).'#transactions',
            ]),
        ],
        [
            'key' => 'is_active',
            'label' => __('customer::field.state'),
            'width' => '7rem',
            'render' => fn ($c) => view('customer::partials.state-badge', ['customer' => $c]),
        ],
        [
            'key' => 'actions',
            'label' => __('core.table.actions'),
            'width' => '8rem',
            'render' => fn ($c) => view('customer::partials.row-actions', ['customer' => $c]),
        ],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('customer::menu.customers') }}</x-slot:title>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form method="GET" class="contents">
            {{-- ⛔ ছাঁকনিটা খোঁজার পরেও টিকে থাকতে হবে।

                 ⚠️ এই ফর্মটা GET — সাবমিট হলে কেবল ভেতরের ঘরগুলো
                 ঠিকানায় যায়। ⓘ লুকানো ঘরটা না থাকলে পরিবেশক তালিকায়
                 দাঁড়িয়ে কিছু খুঁজলেই ছাঁকনিটা **নীরবে খসে পড়ত**, আর
                 হঠাৎ সব গ্রাহক ফিরে আসত — কেউ বুঝতেন না কেন। --}}
            @if ($partyTypeFilter)
                <input type="hidden" name="party_type" value="{{ $partyTypeFilter }}">
            @endif

            @php
                /*
                 * ⭐ ছাঁকনির চিপ — নাম আর মানের লেখা (মালিক, ১ অক্টোবর ২০২৬; [[CustomerListFilters]])।
                 * ⓘ এরিয়া-পয়েন্টের ঠিকানায় আইডি; চিপে জায়গার নাম।
                 */
                $filterKeys = ['created_from', 'created_to', 'due_min', 'due_max', 'advance_min', 'advance_max',
                    'area', 'point', 'status', 'quick', 'rank', 'rank_n', 'rank_by', 'sales_from', 'sales_to'];
                $filterLabels = collect($filterKeys)->mapWithKeys(fn ($k) => [$k => __('customer::filter.'.$k)])->all();
                $filterValues = [
                    'area' => $areas->mapWithKeys(fn ($l) => [(string) $l->id => $l->name()])->all(),
                    'point' => $points->mapWithKeys(fn ($l) => [(string) $l->id => $l->name()])->all(),
                    'status' => collect(['active', 'inactive', 'all'])->mapWithKeys(fn ($v) => [$v => __('customer::filter.'.$v)])->all(),
                    'quick' => collect(['due', 'advance', 'good', 'over_limit'])->mapWithKeys(fn ($v) => [$v => __('customer::filter.quick_'.$v)])->all(),
                    'rank' => ['top' => __('customer::filter.top'), 'bottom' => __('customer::filter.bottom')],
                    'rank_by' => ['sales' => __('customer::filter.by_sales'), 'due' => __('customer::filter.by_due')],
                ];
                $here = fn (array $set) => route('customer.index', array_filter(
                    array_merge(request()->except(['page', 'inactive', ...array_keys($set)]), $set),
                    fn ($v) => $v !== null && $v !== '',
                ));
                $status = request()->boolean('inactive') ? 'all' : (string) request('status', 'active');
            @endphp

            <x-ui.toolbar :title="$rankTitle ?? ($distributorType && (string) $partyTypeFilter === (string) $distributorType->id
                    ? __('customer::menu.distributors')
                    : __('customer::menu.customers'))" :count="trans_choice('customer::message.count', $customers->total(), ['count' => $customers->total()])"
                :columns="$columns"
                :search-placeholder="__('customer::message.search_placeholder')"
                :sort="$sortOptions"
                :filter-labels="$filterLabels"
                :filter-values="$filterValues"
                view>
        <x-slot:actions>
            {{-- ⭐ পরিবেশক তালিকা — মালিকের চাওয়া, ১৬ সেপ্টেম্বর ২০২৬।

                 ⓘ আলাদা পর্দা নয়, **একই তালিকা এক ধরনে ছাঁকা**। খোঁজা,
                 সাজানো, কলাম বাছা, বকেয়ার হিসাব — সব যেমন ছিল তেমনই
                 চলে। ⚠️ আলাদা পর্দা বানালে ঐ সবগুলো দুই জায়গায় থাকত,
                 আর একদিন একটায় ঠিক হয়ে অন্যটায় পুরনো থেকে যেত।

                 ⛔ বোতামটা ছাঁকনি চালু থাকলে **ফেরার পথ** দেখায়, নাহলে
                 ব্যবহারকারী আটকে যেতেন — বেরোনোর একমাত্র উপায় হত
                 ঠিকানার ঘর থেকে হাতে লেখা মুছে ফেলা। --}}
            @if ($distributorType)
                        @php $onDistributors = (string) $partyTypeFilter === (string) $distributorType->id; @endphp

                        <x-ui.button :tone="$onDistributors ? 'primary' : 'secondary'"
                                     :href="route('customer.index', $onDistributors
                                        ? []
                                        : ['party_type' => $distributorType->id])">
                            {{ $onDistributors
                                ? __('customer::action.all_customers')
                                : __('customer::action.distributor_list') }}
                        </x-ui.button>
                    @endif

                    @can('create', \App\Modules\Customer\Models\Customer::class)
                    <x-ui.button tone="primary" icon="plus" :href="route('customer.create')">
                        {{ __('customer::action.new') }}
                    </x-ui.button>
                @endcan
        </x-slot:actions>
                {{-- ⭐ ছাঁকনি — মালিক, ১ অক্টোবর ২০২৬: *"eivabe hoy jani, khali ekta diyecho"*। ⓘ উপরে এক ক্লিকের দৃশ্য,
                     নিচে ঘরগুলো; সব মিলে একসাথে চলে, খোঁজার সাথেও, আর রপ্তানি-ছাপা একই কোয়েরি পায়
                     ([[CustomerListFilters]])। নিষ্ক্রিয়রা ডিফল্টে লুকানো — তালিকাটা রোজকার কাজের। --}}
                <div class="flex w-full flex-wrap items-center gap-1 text-xs" data-customer-quick>
                    <span class="text-(--color-ink-muted)">{{ __('customer::filter.quick') }}:</span>
                    @foreach (['active', 'inactive', 'all'] as $s)
                        <a href="{{ $here(['status' => $s]) }}" @class(['rounded-(--radius-pill) px-2.5 py-0.5',
                            'bg-(--color-accent-600) text-(--color-accent-ink)' => $status === $s,
                            'bg-(--color-surface-selected)' => $status !== $s])>{{ __('customer::filter.'.$s) }}</a>
                    @endforeach
                    @foreach (['due', 'advance', 'good', 'over_limit'] as $v)
                        <a href="{{ $here(['quick' => request('quick') === $v ? null : $v]) }}"
                           @if ($v === 'good') title="{{ __('customer::filter.good_rule', ['days' => \App\Modules\Customer\Support\CustomerListFilters::SALES_DAYS]) }}" @endif
                           @class(['rounded-(--radius-pill) px-2.5 py-0.5',
                            'bg-(--color-accent-600) text-(--color-accent-ink)' => request('quick') === $v,
                            'bg-(--color-surface-selected)' => request('quick') !== $v])>{{ __('customer::filter.quick_'.$v) }}</a>
                    @endforeach
                </div>

                <input type="hidden" name="status" value="{{ $status }}">
                @if (request()->filled('quick'))
                    <input type="hidden" name="quick" value="{{ request('quick') }}">
                @endif

                <div class="grid w-full grid-cols-2 gap-2 text-sm sm:grid-cols-4 lg:grid-cols-6">
                    @foreach (['created_from', 'created_to'] as $k)
                        <label class="grid gap-1"><span class="text-xs text-(--color-ink-muted)">{{ __('customer::filter.'.$k) }}</span>
                            <input type="date" name="{{ $k }}" value="{{ request($k) }}" class="h-(--spacing-field-compact) rounded-(--radius-field) border border-(--color-border) px-2"></label>
                    @endforeach
                    @foreach (['due_min', 'due_max', 'advance_min', 'advance_max'] as $k)
                        <label class="grid gap-1"><span class="text-xs text-(--color-ink-muted)">{{ __('customer::filter.'.$k) }}</span>
                            <input type="number" min="0" step="any" inputmode="decimal" name="{{ $k }}" value="{{ request($k) }}" class="h-(--spacing-field-compact) rounded-(--radius-field) border border-(--color-border) px-2"></label>
                    @endforeach
                    @foreach (['area' => $areas, 'point' => $points] as $k => $places)
                        <label class="grid gap-1"><span class="text-xs text-(--color-ink-muted)">{{ __('customer::filter.'.$k) }}</span>
                            <select name="{{ $k }}" class="h-(--spacing-field-compact) rounded-(--radius-field) border border-(--color-border) px-2">
                                <option value="">{{ __('customer::filter.any') }}</option>
                                @foreach ($places as $place)
                                    <option value="{{ $place->id }}" @selected((string) request($k) === (string) $place->id)>{{ $place->name() }}</option>
                                @endforeach
                            </select></label>
                    @endforeach
                    <label class="grid gap-1"><span class="text-xs text-(--color-ink-muted)">{{ __('customer::filter.rank') }}</span>
                        <select name="rank" class="h-(--spacing-field-compact) rounded-(--radius-field) border border-(--color-border) px-2">
                            <option value="">{{ __('customer::filter.any') }}</option>
                            <option value="top" @selected(request('rank') === 'top')>{{ __('customer::filter.top') }}</option>
                            <option value="bottom" @selected(request('rank') === 'bottom')>{{ __('customer::filter.bottom') }}</option>
                        </select></label>
                    <label class="grid gap-1"><span class="text-xs text-(--color-ink-muted)">{{ __('customer::filter.rank_n') }}</span>
                        <input type="number" min="1" max="500" name="rank_n" value="{{ request('rank_n') }}" placeholder="{{ \App\Modules\Customer\Support\CustomerListFilters::RANK_DEFAULT }}" class="h-(--spacing-field-compact) rounded-(--radius-field) border border-(--color-border) px-2"></label>
                    <label class="grid gap-1"><span class="text-xs text-(--color-ink-muted)">{{ __('customer::filter.rank_by') }}</span>
                        <select name="rank_by" class="h-(--spacing-field-compact) rounded-(--radius-field) border border-(--color-border) px-2">
                            <option value="">{{ __('customer::filter.by_sales') }}</option>
                            <option value="due" @selected(request('rank_by') === 'due')>{{ __('customer::filter.by_due') }}</option>
                        </select></label>
                    @foreach (['sales_from', 'sales_to'] as $k)
                        <label class="grid gap-1"><span class="text-xs text-(--color-ink-muted)">{{ __('customer::filter.'.$k) }}</span>
                            <input type="date" name="{{ $k }}" value="{{ request($k) }}" class="h-(--spacing-field-compact) rounded-(--radius-field) border border-(--color-border) px-2"></label>
                    @endforeach
                </div>
            </x-ui.toolbar>
        </form>

        <x-ui.table
            :grand="$grand ?? []"
            :view-url="fn ($d) => route('customer.show', $d)"
            :empty="$q ? __('core.empty.no_results') : __('customer::message.none_yet')"
            :rows="$customers"
            :compact="request()->boolean('compact')"
            :grid="request('view') === 'grid'"
            {{--
                কলামের ক্রমটা মালিকের দেওয়া — প্রথমবার ২০২৬-০৮-০৭, আর
                ১৯ সেপ্টেম্বর ২০২৬-এ আবার: ক্রম · পার্টি কোড · নাম ·
                পয়েন্ট · এরিয়া · পূর্ণ ঠিকানা · মালিক · মোবাইল · বকেয়া ·
                অবস্থা · কাজ। কোড আগে নামের নিচে বসত, এখন নিজের কলামে।

                ক্রম নম্বরটা পাতার সাথে চলে (firstItem), সারির গোনা নয় —
                তিন নম্বর পাতায় আবার ১ থেকে শুরু হলে "১৪ নম্বরটা দেখুন"
                বলা যেত না।
            --}}
            :columns="$columns" />

        <x-ui.pager :rows="$customers" />
        <x-ui.list-totals :rows="$customers" :grand="$grand ?? []" :columns="$columns" />
    </div>
</x-layouts.app>
