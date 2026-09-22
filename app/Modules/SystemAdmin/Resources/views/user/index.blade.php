{{--
    ব্যবহারকারীর তালিকা।

    ── কেন মোছার বোতাম নেই ────────────────────────────────────────────
    একজন ব্যবহারকারীর নাম প্রতিটা বিলে, প্রতিটা অডিটের সারিতে আর
    লগইনের খাতায় বসে আছে। মুছে ফেললে ওই সব কাগজে "কে করেছিল" প্রশ্নের
    উত্তর হারায়। নিষ্ক্রিয় করা যায় — তখন আর ঢোকা যায় না, ইতিহাস থাকে।
--}}
@php
    /*
     * ব্যবহারকারীর তালিকা — মালিকের নমুনা, ২২ সেপ্টেম্বর ২০২৬।
     *
     * *"ব্যবহারকারী তালিকা এইরকম ক্লিন একটা লিস্ট করো"* — সমতল কলাম,
     * এক সারিতে একজন, আর প্রতিটা তথ্য নিজের ঘরে।
     *
     * ── ⚠️ সকালের ছাঁচ থেকে কেন সরানো ──────────────────────────
     * ℹ আগে ছিল ছবিসহ নাম, আর নিচে ছোট হরফে `@লগইন · ভূমিকা`।
     * ⛔ মালিকের নতুন নমুনায় ওগুলো **আলাদা কলাম**, আর কারণটা
     * বাস্তব: এক ঘরে তিন তথ্য থাকলে **খোঁজা যায় না, সাজানোও যায় না** —
     * লগইনের নাম ধরে খুঁজতে গেলে নামের ঘরটাই মিলাতে হত।
     *
     * ── ℹ `সারি` কলামটা পাতা ধরে গোনে ─────────────────────────
     * ⛔ `$loop->index + 1` লিখলে দ্বিতীয় পাতাও ১ থেকে শুরু হত, আর
     * দুই পাতায় দুইটা "১ নম্বর" বসত।
     */
    $serial = ($users->currentPage() - 1) * $users->perPage();

    $columns = [
        [
            'key' => 'serial',
            'label' => __('core.table.serial'),
            'width' => '4rem',
            'numeric' => true,
            'render' => function ($u) use (&$serial) {
                return ++$serial;
            },
        ],
        [
            /*
             * ⛔ নামের ঘরে মাপ বসানো — ২২ সেপ্টেম্বর ২০২৬, মালিকের
             * *"Name vangteche keno?"*।
             *
             * ── ⚠️ কেন ভাঙছিল ─────────────────────────────────────
             * বাকি **প্রতিটা** কলামের মাপ বলা ছিল, কেবল নামেরটার নয়।
             * ⓘ তাই ব্রাউজার নামকে দিত "যা বাকি থাকে", আর বারোটা
             * কলামের পর বাকি থাকত সবচেয়ে কম — *"Al-Amin / Shuvo"*
             * দুই লাইনে ভেঙে যেত।
             *
             * ⛔ আর ভাঙছিল ঠিক সেই ঘরটাই যেটা দিয়ে মানুষ সারিটা চেনেন।
             * ⚠️ ছকে মাপ না বলা মানে "যা ইচ্ছা" নয় — মানে **সবার শেষে
             * যা পড়ে থাকে**, আর সেটা একটা সিদ্ধান্ত, দুর্ঘটনা নয়।
             *
             * ⓘ ছকটা `table-layout: auto`, তাই এখানে `width` একটা
             * **মেঝে**, ছাদ নয় — লম্বা নাম এলে ঘরটা বাড়ে, কিন্তু
             * এর নিচে নামে না। ⚠️ আর নিচের partial-এ `nowrap` বসানো,
             * কারণ কেবল মাপ বললে সরু পর্দায় ব্রাউজার তবু ভাঙত।
             */
            'key' => 'name',
            'label' => __('system_admin::field.user_name'),
            'width' => '12rem',
            'render' => fn ($u) => view('system_admin::user.partials.name', ['user' => $u]),
        ],
        [
            'key' => 'login_id',
            'label' => __('system_admin::field.login_id'),
            'width' => '9rem',
            'render' => fn ($u) => $u->login_id ?: '—',
        ],
        [
            'key' => 'code',
            'label' => __('core.table.code'),
            'width' => '7rem',
            'render' => fn ($u) => $u->code ?: '—',
        ],
        [
            'key' => 'roles',
            'label' => __('system_admin::field.roles'),
            'render' => fn ($u) => view('system_admin::user.partials.roles', ['user' => $u]),
        ],
        [
            /*
             * ℹ কোম্পানি ফিরে এসেছে — সকালে সরানো হয়েছিল।
             *
             * ⛔ যুক্তি ছিল: তালিকাটা চলতি কোম্পানিতে ছাঁকা, তাই কলামটা
             * প্রতি সারিতে একই কথা বলত। ⚠️ কিন্তু যুক্তিটা অসম্পূর্ণ ছিল:
             * একজন মানুষ **একাধিক কোম্পানিতে** থাকতে পারেন, আর
             * ঘরটা বলে তিনি আর কোথায় ঢুকতে পারেন — সেটা এক কথা নয়।
             */
            'key' => 'companies',
            'label' => __('core.company.company'),
            'render' => fn ($u) => $u->companies->map(fn ($c) => $c->code)->implode(', ') ?: '—',
        ],
        [
            'key' => 'branch',
            'label' => __('core.company.branch'),
            'render' => fn ($u) => view('system_admin::user.partials.branch', ['user' => $u]),
        ],
        [
            'key' => 'mobile',
            'label' => __('core.profile.mobile'),
            'width' => '9rem',
            'render' => fn ($u) => $u->mobile
                ? new \Illuminate\Support\HtmlString(
                    '<a class="hover:underline" href="tel:'.e($u->mobile).'">'.e($u->mobile).'</a>')
                : '—',
        ],
        [
            'key' => 'email',
            'label' => __('core.profile.email'),
            'render' => fn ($u) => new \Illuminate\Support\HtmlString(
                '<a class="hover:underline" href="mailto:'.e($u->email).'">'.e($u->email).'</a>'),
        ],
        [
            'key' => 'remarks',
            'label' => __('core.table.remarks'),
            'render' => fn ($u) => $u->remarks ?: '',
        ],
        [
            'key' => 'status',
            'label' => __('core.table.status'),
            'width' => '7rem',
            'render' => fn ($u) => view('system_admin::user.partials.status', ['user' => $u]),
        ],
        [
            'key' => 'actions',
            'label' => __('core.table.actions'),
            'width' => '6rem',
            'render' => fn ($u) => view('system_admin::user.partials.edit', ['user' => $u]),
        ],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('system_admin::menu.users') }}</x-slot:title>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form method="GET" class="contents">
            {{-- ⭐ শিরোনাম, বর্ণনা আর "নতুন" বোতাম এখন টুলবারে — আগে ছিল
                 page-header-এ, তালিকার বাক্সের বাইরে। মালিকের নির্দেশ,
                 ১৯ সেপ্টেম্বর ২০২৬: *"সব মডিউলেই একই অবস্থা, সব ঠিক করো"*।

                 ⓘ খোঁজা নাম ও ইমেইলে (UserController::index)। সাজানো নেই —
                 তালিকা সবসময় নামের ক্রমে, আর অন্য কোনো ক্রম কন্ট্রোলার
                 জানে না। --}}
            <x-ui.toolbar :title="__('system_admin::menu.users')"
                          :subtitle="__('system_admin::message.users_note')"
                          :search-placeholder="__('system_admin::message.user_search')"
                          :columns="$columns">
                <x-slot:actions>
                    <x-ui.button tone="primary" icon="plus" :href="route('system_admin.user.create')">
                        {{ __('core.action.create') }}
                    </x-ui.button>
                </x-slot:actions>
            </x-ui.toolbar>
        </form>

        <x-ui.table :rows="$users"
                    :columns="$columns"
                    :compact="request()->boolean('compact')"
                    :empty="__('core.empty.no_results')" />
    </div>

    {{ $users->links() }}

</x-layouts.app>
