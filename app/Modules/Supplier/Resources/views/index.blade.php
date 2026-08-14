{{--
    সরবরাহকারী তালিকা।

    গ্রাহকের তালিকার সাথে গঠন এক, দুইটা কলাম আলাদা: "বকেয়া"-র বদলে
    "প্রদেয়" (চিহ্ন উল্টো), আর ধরনটা এখন মাস্টার তালিকা থেকে আসা নাম —
    মুক্ত লেখা নয়।
--}}
@php
    $columns = [
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
                'href' => route('supplier.show', $s),
            ]),
        ],
        [
            'key' => 'is_active',
            'label' => __('supplier::field.state'),
            'width' => '7rem',
            'render' => fn ($s) => view('supplier::partials.state-badge', ['supplier' => $s]),
        ],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('supplier::menu.suppliers') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header
            :title="__('supplier::menu.suppliers')"
            :subtitle="trans_choice('supplier::message.count', $suppliers->total(), ['count' => $suppliers->total()])">
            <x-slot:actions>
                @can('create', \App\Modules\Supplier\Models\Supplier::class)
                    <x-ui.button tone="primary" icon="plus" :href="route('supplier.create')">
                        {{ __('supplier::action.new') }}
                    </x-ui.button>
                @endcan
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    <div class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form method="GET" class="contents">
            <x-ui.toolbar
                :columns="$columns"
                :search-placeholder="__('supplier::message.search_placeholder')"
                :sort="$sortOptions"
                view>
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

        @if ($suppliers->hasPages())
            <div class="border-t border-(--color-border) px-3 py-2">
                {{ $suppliers->links() }}
            </div>
        @endif
    </div>
</x-layouts.app>
