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
            <x-ui.toolbar :columns="false" :density="false">
                {{-- নিষ্ক্রিয় গ্রাহকও দেখা যাবে, কিন্তু ডিফল্টে নয়: তালিকাটা
                     রোজকার কাজের, আর নিষ্ক্রিয়রা সেখানে শুধু ভিড় বাড়ায়। --}}
                <label class="flex min-h-(--spacing-touch) items-center gap-2 text-sm">
                    <input type="checkbox" name="inactive" value="1" @checked($showInactive)
                           onchange="this.form.submit()" class="size-4">
                    {{ __('customer::action.show_inactive') }}
                </label>
            </x-ui.toolbar>
        </form>

        <x-ui.table
            :empty="$q ? __('core.empty.no_results') : __('customer::message.none_yet')"
            :rows="$customers"
            :columns="[
                [
                    'key' => 'code',
                    'label' => __('customer::field.code'),
                    // CUS-2026-2027-0001 — অর্থবছর সহ কোড লম্বা হয়, আর
                    // ৯rem-এ সেটা দুই লাইনে ভেঙে যাচ্ছিল
                    'width' => '13rem',
                    'render' => fn ($c) => view('customer::partials.code-link', ['customer' => $c]),
                ],
                ['key' => 'name_en', 'label' => __('customer::field.name'), 'render' => fn ($c) => $c->name()],
                ['key' => 'phone', 'label' => __('customer::field.phone'), 'width' => '9rem'],
                [
                    'key' => 'outstanding',
                    'label' => __('customer::field.outstanding'),
                    'numeric' => true,
                    'width' => '10rem',
                    'render' => fn ($c) => number_format((float) $c->outstanding(), 2),
                ],
                [
                    'key' => 'is_active',
                    'label' => __('customer::field.state'),
                    'width' => '7rem',
                    'render' => fn ($c) => view('customer::partials.state-badge', ['customer' => $c]),
                ],
            ]" />

        @if ($customers->hasPages())
            <div class="border-t border-(--color-border) px-3 py-2">
                {{ $customers->links() }}
            </div>
        @endif
    </div>
</x-layouts.app>
