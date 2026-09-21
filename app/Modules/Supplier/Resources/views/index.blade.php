{{--
    সরবরাহকারী তালিকা।

    গ্রাহকের তালিকার সাথে গঠন এক, দুইটা কলাম আলাদা: "বকেয়া"-র বদলে
    "প্রদেয়" (চিহ্ন উল্টো), আর ধরনটা এখন মাস্টার তালিকা থেকে আসা নাম —
    মুক্ত লেখা নয়।
--}}
@php
    $columns = [
        /*
         * ⭐ ক্রম · কোড · নাম · ধরন · ঠিকানা · মোবাইল · প্রদেয় · অবস্থা · কাজ
         * — মালিকের নিজের ছক, ২১ সেপ্টেম্বর ২০২৬।
         *
         * ⓘ তিনটা জিনিস যোগ হলো: ক্রম, ঠিকানা আর কাজের কলাম।
         * ⚠️ ক্রমটা গোটা তালিকার, পাতার নয় (`firstItem()` ধরে) — নাহলে
         * দ্বিতীয় পাতাতেও ১ থেকে শুরু হত।
         */
        [
            'key' => 'sl',
            'label' => __('core.table.serial'),
            'numeric' => true,
            'width' => '4rem',
            'render' => fn ($s, $i) => (string) (($suppliers->firstItem() ?? 1) + $i),
        ],
        [
            'key' => 'code',
            'label' => __('supplier::field.code'),
            'width' => '13rem',
            'render' => fn ($s) => view('supplier::partials.code-link', ['supplier' => $s]),
        ],
        [
            'key' => 'name_en',
            'label' => __('supplier::field.name'),
            'width' => '20rem',
            'render' => fn ($s) => $s->name(),
        ],
        [
            'key' => 'party_type_id',
            'label' => __('supplier::field.party_type'),
            'render' => fn ($s) => $s->partyType?->name(),
        ],
        /*
         * ⓘ ঠিকানা — দুইজন একই নামের সরবরাহকারীকে আলাদা করার সবচেয়ে
         * সহজ উপায়। ⚠️ লম্বা ঠিকানা সারিটা ভেঙে দেয়, তাই প্রস্থ বাঁধা।
         */
        [
            'key' => 'address_en',
            'label' => __('supplier::field.address'),
            'width' => '16rem',
            'render' => fn ($s) => $s->address() ?: '—',
        ],
        ['key' => 'phone', 'label' => __('supplier::field.phone'), 'width' => '9rem'],
        [
            'key' => 'payable',
            'label' => __('supplier::field.payable'),
            'numeric' => true,
            'width' => '10rem',
            // অঙ্কটাই লিংক — নিয়ম ১। ব্যবহারকারী কোডে ক্লিক করেন
            // না, তিনি সংখ্যাটা দেখে জানতে চান এটা কোথা থেকে এল।
            'render' => fn ($s) => view('ui.amount-link', [
                'value' => $s->payable(),
                'href' => route('supplier.show', $s).'#transactions',
            ]),
        ],
        [
            'key' => 'is_active',
            'label' => __('supplier::field.state'),
            'width' => '7rem',
            'render' => fn ($s) => view('supplier::partials.state-badge', ['supplier' => $s]),
        ],

        /*
         * ⓘ কাজের কলামটা মালিকের ছকের শেষ ঘর, আর এতদিন ছিলই না।
         * ⛔ "মুছুন" নেই — কারণ [[supplier::partials.row-actions]]-এ।
         */
        [
            'key' => 'actions',
            'label' => __('core.table.actions'),
            'width' => '6rem',
            'render' => fn ($s) => view('supplier::partials.row-actions', ['supplier' => $s]),
        ],
    ];
@endphp

<x-layouts.app :menu="$menu">
    {{-- ⓘ দুইটা তালিকা একই পর্দা ব্যবহার করে, তাই শিরোনাম ও বোতামের
         লেখা কন্ট্রোলার থেকে আসে। ⚠️ স্থির লেখা রাখলে সেবাদাতার
         তালিকাতেও "সরবরাহকারী" লেখা থাকত, আর কেউ বুঝত না তিনি কোথায়। --}}
    <x-slot:title>{{ $heading }}</x-slot:title>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form method="GET" class="contents">
            <x-ui.toolbar :title="$heading" :count="trans_choice('supplier::message.count', $suppliers->total(), ['count' => $suppliers->total()])"
                :columns="$columns"
                :search-placeholder="__('supplier::message.search_placeholder')"
                :sort="$sortOptions"
                view>
        <x-slot:actions>
            @can('create', \App\Modules\Supplier\Models\Supplier::class)
                    {{-- ⭐ মালিকের নির্দেশ, ১৬ সেপ্টেম্বর ২০২৬: *"সেবাদাতা
                         তালিকা create-এ নতুন সেবাদাতা হবে"*।

                         ⓘ `?kind=service` — কাগজটা একই, কেবল পর্দার লেখা
                         বদলায়। ⚠️ আলাদা রুট বানালে একই ফর্মের দুইটা পথ
                         হত, আর একদিন একটায় ঘর যোগ হত অন্যটায় নয়। --}}
                    <x-ui.button tone="primary" icon="plus"
                                 :href="route('supplier.create', $isServices ? ['kind' => 'service'] : [])">
                        {{ $isServices ? __('supplier::action.new_service') : __('supplier::action.new') }}
                    </x-ui.button>
                @endcan
        </x-slot:actions>
                {{-- নিষ্ক্রিয়রাও দেখা যাবে, কিন্তু ডিফল্টে নয়: তালিকাটা
                     রোজকার কাজের, আর নিষ্ক্রিয়রা সেখানে শুধু ভিড় বাড়ায়। --}}
                <label class="flex min-h-(--spacing-touch) items-center gap-2 text-sm">
                    <input type="checkbox" name="inactive" value="1" @checked($showInactive) class="size-4">
                    {{ __('supplier::action.show_inactive') }}
                </label>
            </x-ui.toolbar>
        </form>

        {{--
            নামের কলামে স্পষ্ট প্রস্থ দেওয়া। না দিলে বাকি কলামগুলোর
            নির্দিষ্ট প্রস্থের পর যা অবশিষ্ট থাকে তাতেই নামটা চাপা পড়ে,
            আর লম্বা বাংলা নাম তিন লাইনে ভেঙে যায় — অথচ পাতায় জায়গা
            পড়ে থাকে।

            নিচের তালিকাটার ভেতরে ডাবল কোট লেখা যাবে না, মন্তব্যেও নয়:
            পুরোটা একটা HTML অ্যাট্রিবিউটের ভেতরে বসে, তাই একটা কোট
            পড়লেই অ্যাট্রিবিউট ওখানে শেষ হয়ে যায় আর বাকি অংশ কাঁচা
            লেখা হিসেবে পাতায় ছাপা হয়। তাই ব্যাখ্যাটা এখানে, বাইরে।
        --}}
        <x-ui.table
            :empty="$q ? __('core.empty.no_results') : __('supplier::message.none_yet')"
            :rows="$suppliers"
            :compact="request()->boolean('compact')"
            :grid="request('view') === 'grid'"
            :columns="$columns" />

        <x-ui.pager :rows="$suppliers" />
    </div>
</x-layouts.app>
