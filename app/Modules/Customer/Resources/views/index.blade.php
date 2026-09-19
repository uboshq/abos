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
                'value' => $c->outstanding(),
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

            <x-ui.toolbar :title="$distributorType && (string) $partyTypeFilter === (string) $distributorType->id
                    ? __('customer::menu.distributors')
                    : __('customer::menu.customers')" :count="trans_choice('customer::message.count', $customers->total(), ['count' => $customers->total()])"
                :columns="$columns"
                :search-placeholder="__('customer::message.search_placeholder')"
                :sort="$sortOptions"
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
                {{-- নিষ্ক্রিয় গ্রাহকও দেখা যাবে, কিন্তু ডিফল্টে নয়: তালিকাটা
                     রোজকার কাজের, আর নিষ্ক্রিয়রা সেখানে শুধু ভিড় বাড়ায়। --}}
                <label class="flex min-h-(--spacing-touch) items-center gap-2 text-sm">
                    <input type="checkbox" name="inactive" value="1" @checked($showInactive) class="size-4">
                    {{ __('customer::action.show_inactive') }}
                </label>
            </x-ui.toolbar>
        </form>

        <x-ui.table
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
    </div>
</x-layouts.app>
