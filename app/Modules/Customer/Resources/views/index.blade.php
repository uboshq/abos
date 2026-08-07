{{--
    গ্রাহক তালিকা — শেয়ার্ড কম্পোনেন্টের উপর, নিজের কিছু নয়।

    এটাই Phase 2-এর আসল পরীক্ষা (সেকশন ২.৩): এখানে টেবিল, টুলবার বা ব্যাজ
    নতুন করে লিখতে হলে বুঝতে হবে ভিত্তিতে ফাঁক আছে। প্রথম চেষ্টায় ঠিক
    সেটাই হয়েছিল — ঘরের ভেতর লিংক বসাতে HtmlString হাতে বানাতে হচ্ছিল।
    সমাধান স্ক্রিনে নয়, কম্পোনেন্টে: কলাম এখন নিজের render দিতে পারে।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('customer::menu.customers') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header
            :title="__('customer::menu.customers')"
            :subtitle="trans_choice('customer::message.count', $customers->total(), ['count' => $customers->total()])">
            <x-slot:actions>
                @can('create', \App\Modules\Customer\Models\Customer::class)
                    <x-ui.button tone="primary" icon="+" :href="route('customer.create')">
                        {{ __('customer::action.new') }}
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
                :search-placeholder="__('customer::message.search_placeholder')"
                :sort="$sortOptions"
                view>
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
                কলামের ক্রমটা মালিকের দেওয়া (২০২৬-০৮-০৭), হুবহু:
                ক্রম · নাম · পয়েন্ট · এরিয়া · মালিক · মোবাইল · পাওনা ·
                অবস্থা · বিস্তারিত · কাজ।

                কোডের কলামটা সরানো হয়েছে, বাদ দেওয়া হয়নি — নামটাই এখন
                কোডের লিংক বহন করে। কারণ ওই ক্রমে কোড নেই, অথচ কোড ছাড়া
                একই নামের দুইটা দোকান আলাদা করা যেত না।

                ক্রম নম্বরটা পাতার সাথে চলে (firstItem), সারির গোনা নয় —
                তিন নম্বর পাতায় আবার ১ থেকে শুরু হলে "১৪ নম্বরটা দেখুন"
                বলা যেত না।
            --}}
            :columns="[
                [
                    'key' => 'sl',
                    'label' => __('core.table.serial'),
                    'width' => '4rem',
                    'numeric' => true,
                    'render' => fn ($c, $i) => $customers->firstItem() + $i,
                ],
                [
                    'key' => 'name_en',
                    'label' => __('customer::field.name'),
                    // স্পষ্ট প্রস্থ, নাহলে বাংলা নাম কয়েক লাইনে ভাঙে —
                    // বাকি কলামগুলোর নির্দিষ্ট প্রস্থের পর যা থাকে তাতেই
                    // নামটা চাপা পড়ে যায়
                    'width' => '18rem',
                    'render' => fn ($c) => view('customer::partials.code-link', ['customer' => $c]),
                ],
                [
                    'key' => 'point',
                    'label' => __('customer::field.point'),
                    'width' => '9rem',
                    'render' => fn ($c) => $c->location?->name() ?? '—',
                ],
                [
                    'key' => 'area',
                    'label' => __('customer::field.area'),
                    'width' => '9rem',
                    'render' => fn ($c) => $c->area()?->name() ?? '—',
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
                    // অঙ্কটাই লিংক — নিয়ম ১
                    'render' => fn ($c) => view('ui.amount-link', [
                        'value' => $c->outstanding(),
                        'href' => route('customer.show', $c),
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
            ]" />

        @if ($customers->hasPages())
            <div class="border-t border-(--color-border) px-3 py-2">
                {{ $customers->links() }}
            </div>
        @endif
    </div>
</x-layouts.app>
