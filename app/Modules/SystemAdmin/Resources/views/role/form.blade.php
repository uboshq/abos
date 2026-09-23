{{--
    রোল ও অনুমতি — **একটাই** পর্দা।

    ── ⭐ কেন একটাই, ২৪ সেপ্টেম্বর ২০২৬ ─────────────────────────────────
    মালিকের প্রশ্ন: *"ekoi jinis dui porda keno?"* ⓘ আগে তালিকা আর
    সম্পাদনা দুইটা আলাদা পর্দা ছিল, অথচ সম্পাদনার পর্দার বাঁ কলামে ঠিক
    সেই তালিকাটাই আবার বসত। ⚠️ একই জিনিস দুইবার, আর রোল বদলাতে গেলে
    প্রতিবার পুরো পাতা নতুন করে।

    ⭐ এখন তিন কলাম (স্পেক §২.০): বাঁয়ে তালিকা, মাঝে অনুমতির ছক, ডানে
    অ্যাকসেসের বিবরণ। ⓘ `role` শূন্য মানে *"এখনো কিছু বাছা হয়নি"*।

    ── ⛔ কেন ছক, ১৯ সেপ্টেম্বর ২০২৬ ────────────────────────────────────
    আগে প্রতিটা মডিউলের নিচে অনুমতিগুলো **কাঁচা নামে** বসত —
    `accounts.voucher.update`, `inventory.stock.opening`। ⚠️ কাঁচা নামে
    টিক দেওয়া মানে না বুঝে অধিকার দেওয়া — আর এখানে ভুল টিক মানে ভুল
    মানুষের হাতে টাকার দরজা।

    ── ⓘ জমা দেওয়া বদলায়নি ────────────────────────────────────────────
    প্রতিটা সুইচ এখনো `permissions[]`-এ পুরো নামটাই পাঠায়। ছকটা কেবল
    সাজানো — কোন ঘরে কোনটা বসবে সেটা `RoleController::formData()` ঠিক
    করে, আর যেটা চার কলামে ধরে না সেটা "বিশেষ" কলামে যায়, বাদ পড়ে না।
--}}
@php
    /* ⓘ `null` = তালিকার পর্দা, `exists === false` = নতুন রোল। */
    $picked = $role !== null;
    $isNew = $picked && ! $role->exists;

    $chosen = collect(old('permissions', $held))->all();

    $title = ! $picked
        ? __('system_admin::menu.roles')
        : ($isNew ? __('system_admin::action.new_role') : \App\Core\Support\RoleLabel::for($role->name));

    $columns = [
        'view' => __('system_admin::permission.column_view'),
        'create' => __('system_admin::permission.column_create'),
        'update' => __('system_admin::permission.column_update'),
        'delete' => __('system_admin::permission.column_delete'),
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $title }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('system_admin::menu.roles')"
                          :subtitle="__('system_admin::message.roles_note')" />
    </x-slot:header>

    {{-- ⭐ সংরক্ষণের বার্তা এখানে — ২৪ সেপ্টেম্বর ২০২৬।

         ⓘ আগে এটা কেবল তালিকার পর্দায় ছিল, আর সংরক্ষণের পর ওখানেই ফেরত
         পাঠানো হত। ⚠️ এখন সংরক্ষণের পর রোলটাতেই থাকা হয়
         ([[RoleController::store()]]), তাই বার্তাটা এই পর্দায় না থাকলে
         **কোনো বার্তাই দেখা যেত না** — কাজ হয়েছে কি না বলার উপায় থাকত না। --}}
    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    {{-- ⭐ সারাংশের কার্ড — মালিকের স্পেকের "Overview Cards", ২৩ সেপ্টেম্বর ২০২৬।

         ⓘ স্পেকে ছয়টা কার্ড: ব্যবহারকারী · রোল · অনুমতি · উচ্চ ঝুঁকি ·
         অপেক্ষায় · স্থগিত।

         ── ⛔ এখানে চারটা, আর সেটাই ইচ্ছাকৃত ───────────────────────────
         ⚠️ *"উচ্চ ঝুঁকি"*, *"অপেক্ষায়"* আর *"স্থগিত"* — তিনটার পিছনেই
         এখনো কোনো ব্যবস্থা নেই (ঝুঁকির মাত্রা, অ্যাকসেস রিভিউ, স্থগিত
         করা)। ⛔ স্পেকের সংখ্যা তিনটা (১৪ · ৭ · ৩) বসিয়ে দিলে পর্দাটা
         নকশার মতোই দেখাত — আর কার্ডগুলো হত **সাজসজ্জা**, যা একদিন কেউ
         বিশ্বাস করে সিদ্ধান্ত নিতেন।

         ⓘ শূন্য দেখানোও চলত না: শূন্য মানে *"একটাও নেই"*, *"এখনো বানানো
         হয়নি"* নয়। ⭐ ব্যবস্থাগুলো এলে কার্ডগুলো এখানেই বসবে — জায়গা
         আছে, আর সংখ্যাটা তখন সত্যি বলবে।

         ⓘ চতুর্থ কার্ডটা স্পেকের বাইরে, কিন্তু মেপে পাওয়া আর কাজের:
         **যে রোলে কেউ নেই** — আজ ১৩টার মধ্যে ১০টাই ফাঁকা, আর ঐ সংখ্যাটা
         দেখলে সবার আগে ওগুলো গোছানোর কথা মনে পড়ে।

         ⚠️ উচ্চতা স্পেক মতো ১০০–১২০px — `p-4` ও দুই লাইনের লেখায় ≈১০৪। --}}
    <div class="mb-4 grid grid-cols-2 gap-3 md:grid-cols-4">
        @foreach ([
            ['users', 'system_admin::permission.card_users', 'people'],
            ['roles', 'system_admin::permission.card_roles', 'check-circle'],
            ['permissions', 'system_admin::permission.card_permissions', 'lock'],
            ['unassigned', 'system_admin::permission.card_unassigned', 'alert-triangle'],
        ] as [$key, $label, $icon])
            <div data-boxed
                 class="rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card) p-4">
                <div class="flex items-center gap-2 text-2xs text-(--color-ink-muted)">
                    <x-ui.icon :name="$icon" class="size-4" />
                    <span>{{ __($label) }}</span>
                </div>

                <div class="num mt-1 text-2xl font-bold">{{ $summary[$key] ?? 0 }}</div>
            </div>
        @endforeach
    </div>

    {{-- ⭐ তিন প্যানেলের মাপ — মালিকের স্পেক, ২৩ সেপ্টেম্বর ২০২৬।

         তাঁর দেওয়া মাপ, হুবহু:
             Left Role Panel        ২৪০–২৮০px
             Permission Matrix      flex / 1fr
             Right Details Panel    ৩২০–৩৮০px

         ⓘ বাঁ কলাম `16.25rem` (২৬০px) — তাঁর ২৪০–২৮০-র মাঝামাঝি।
         ⓘ ডান কলাম `21.875rem` (৩৫০px) — তাঁর ৩২০–৩৮০-র মাঝামাঝি।

         ── ⛔ আগে কী ছিল ───────────────────────────────────────────────
         বাঁ ১৫rem (২৪০) আর ডান **১৬rem (২৫৬)** — ⚠️ ডানটা স্পেকের
         সর্বনিম্নেরও ৬৪px নিচে। ⓘ ঐ কলামে ডেটা স্কোপ, অনুমোদনের সীমা
         আর ঝুঁকি বসবে; ২৫৬px-এ *"৳৫,০০,০০০"* সারিটাই দুই লাইনে ভাঙত।

         ⚠️ `minmax(0,1fr)` — নাহলে ম্যাট্রিক্সের চওড়া ছক গ্রিড ঠেলে
         বাড়িয়ে দেয়, আর ডান কলাম পর্দার বাইরে চলে যায়। --}}
    <div class="grid items-start gap-4
                lg:grid-cols-[16.25rem_minmax(0,1fr)]
                xl:grid-cols-[16.25rem_minmax(0,1fr)_21.875rem]">

        {{-- ── বাঁয়ে: রোলের তালিকা ─────────────────────────────────────── --}}
        <aside data-boxed x-data="{ q: '' }"
               class="order-2 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-3 lg:order-none">
            <div class="flex items-center justify-between gap-2">
                <h2 class="text-sm font-semibold">{{ __('system_admin::permission.roles') }}</h2>
                <x-ui.button tone="primary" icon="plus" :href="route('system_admin.role.create')">
                    {{ __('system_admin::action.new_role') }}
                </x-ui.button>
            </div>

            <label class="mt-3 flex items-center gap-2 rounded-(--radius-field) border border-(--color-border) px-2">
                <x-ui.icon name="search" class="size-4 text-(--color-ink-muted)" />
                <input type="search" x-model="q" placeholder="{{ __('system_admin::permission.search_roles') }}"
                       aria-label="{{ __('system_admin::permission.search_roles') }}"
                       class="min-h-(--spacing-touch) w-full border-0 bg-transparent p-0 text-sm focus:ring-0">
            </label>

            {{-- ⭐ তিন ভাগে — স্পেক §২.৩। ভাগটা কোথা থেকে আসে তা
                 [[RoleController::grouped()]]-এ, আর সেটা মেপে, ধরে নিয়ে নয়। --}}
            @foreach ($roleList as $groupKey => $group)
                <h3 class="mt-3 px-2 text-2xs font-semibold uppercase tracking-wide text-(--color-ink-muted)">
                    {{ $group['label'] }}
                </h3>

                <ul class="mt-1 space-y-0.5">
                    @foreach ($group['roles'] as $r)
                        @php
                            $current = $picked && $role->exists && $r->id === $role->id;
                            $label = \App\Core\Support\RoleLabel::for($r->name);
                        @endphp
                        <li x-show="! q || @js(mb_strtolower($label.' '.$r->name)).includes(q.toLowerCase())">
                            @if ($r->name === $ownerRole)
                                <div title="{{ __('system_admin::permission.owner_locked') }}"
                                     class="flex min-h-(--spacing-touch) items-center gap-2 rounded-(--radius-field) px-2 text-sm text-(--color-ink-muted)">
                                    <x-ui.icon name="lock" class="size-4 flex-none" />
                                    <span class="min-w-0 flex-1 truncate">{{ $label }}</span>
                                    <span class="tabular-nums text-xs">{{ $r->users_count }}</span>
                                </div>
                            @else
                                <a href="{{ route('system_admin.role.edit', $r) }}"
                                   @if ($current) aria-current="page" @endif
                                   @class([
                                       'flex min-h-(--spacing-touch) items-center gap-2 rounded-(--radius-field) px-2 text-sm',
                                       'bg-(--color-surface-selected) font-semibold text-(--color-brand-700)' => $current,
                                       'hover:bg-(--color-surface-hover)' => ! $current,
                                   ])>
                                    <x-ui.icon name="people" class="size-4 flex-none text-(--color-ink-muted)" />
                                    <span class="min-w-0 flex-1 truncate">{{ $label }}</span>

                                    {{-- ⓘ দুইটা সংখ্যা: কতজন · কয়টা অনুমতি। ⛔ পুরনো
                                         তালিকার পর্দায় দুইটাই কলাম হয়ে ছিল, আর ঐ পর্দাটা
                                         আর নেই — সংখ্যাগুলো হারিয়ে যেতে দেওয়া হয়নি। --}}
                                    <span class="tabular-nums text-xs text-(--color-ink-muted)"
                                          title="{{ __('system_admin::field.user_count') }} · {{ __('system_admin::field.permission_count') }}">
                                        {{ $r->users_count }} · {{ $r->permissions_count }}
                                    </span>
                                </a>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endforeach
        </aside>

        {{-- ── মাঝে: নাম আর অনুমতির ছক ───────────────────────────────── --}}
        @if (! $picked)
            {{-- ⭐ কোনো রোল বাছা হয়নি — খালি পর্দা নয়, একটা কথা।

                 ⚠️ ছকটা এখানে আঁকা হয় না, আর সেটা ইচ্ছাকৃত: কোন রোলের ছক?
                 ⛔ সব টিক তোলা একটা ছক দেখালে মনে হত এই রোলটার কিছুই নেই। --}}
            <section data-boxed
                     class="order-1 grid min-h-64 place-items-center rounded-(--radius-card) border
                            border-dashed border-(--color-border) bg-(--color-surface-card) p-8 text-center lg:order-none">
                <div>
                    <x-ui.icon name="lock" class="mx-auto size-8 text-(--color-ink-muted)" />
                    <h2 class="mt-3 text-base font-semibold">{{ __('system_admin::permission.pick_a_role') }}</h2>
                    <p class="mx-auto mt-1 max-w-sm text-sm text-(--color-ink-muted)">
                        {{ __('system_admin::permission.pick_a_role_note') }}
                    </p>
                </div>
            </section>
        @else
        <form method="POST"
              action="{{ $isNew ? route('system_admin.role.store') : route('system_admin.role.update', $role) }}"
              class="order-1 min-w-0 space-y-3 lg:order-none">
            @csrf
            @unless ($isNew) @method('PUT') @endunless

            @if ($errors->any())
                <div role="alert"
                     class="rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm
                            text-(--color-badge-danger-ink)">
                    <ul class="list-inside list-disc">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h2 class="text-base font-semibold">{{ $title }}</h2>

                        {{-- ⭐ রোলের মাথা — মালিকের স্পেক §২.৪, ২৪ সেপ্টেম্বর ২০২৬।

                             তাঁর নমুনায়: *"ব্যবসায়িক রোল · ১২ জন · ● সচল"*, আর
                             নিচে *"তৈরি ১২ আগস্ট · শেষ বদল ২১ সেপ্টেম্বর"*।

                             ⓘ প্রতিটা টুকরো মাপা: ভাগটা রেজিস্ট্রি থেকে
                             ([[RoleController::grouped()]]), গুনতি `users_count`,
                             তারিখ দুইটা সারির নিজের ঘর।

                             ⛔ *"● সচল"* এখানে **নেই**: রোলে সচল/নিষ্ক্রিয় বলে
                             কোনো ঘরই নেই আজ। ⚠️ সবুজ বিন্দুটা বসিয়ে দিলে সেটা
                             সবসময় সবুজ থাকত — অর্থাৎ একটা চিহ্ন যা কোনোদিন কিছু
                             বলে না, অথচ দেখে মনে হয় বলছে। --}}
                        @unless ($isNew)
                            <p class="mt-0.5 text-xs text-(--color-ink-muted)">
                                @foreach ($roleList as $groupKey => $group)
                                    @if ($group['roles']->contains('id', $role->id))
                                        {{ $group['label'] }} ·
                                        @break
                                    @endif
                                @endforeach

                                {{ __('system_admin::permission.people_count', [
                                    'count' => $members->count(),
                                ]) }}
                            </p>

                            @if ($role->created_at)
                                <p class="mt-0.5 text-2xs text-(--color-ink-muted)">
                                    {{ __('system_admin::permission.made_on', [
                                        'date' => \App\Core\Support\DateFormat::format($role->created_at),
                                    ]) }}
                                    @if ($role->updated_at && ! $role->updated_at->equalTo($role->created_at))
                                        · {{ __('system_admin::permission.changed_on', [
                                            'date' => \App\Core\Support\DateFormat::format($role->updated_at),
                                        ]) }}
                                    @endif
                                </p>
                            @endif
                        @endunless
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <x-ui.button tone="secondary" :href="route('system_admin.role.index')">
                            {{ __('core.action.cancel') }}
                        </x-ui.button>
                        <x-ui.button type="submit" tone="primary">{{ __('core.action.save') }}</x-ui.button>
                    </div>
                </div>

                <div class="mt-3 max-w-md">
                    <x-ui.field name="name" :label="__('system_admin::field.role_name')"
                                :value="old('name', $role->name)"
                                :hint="__('system_admin::field.role_name_hint')" required />
                </div>
            </section>

            {{-- ⭐ ছকের সরঞ্জাম — মালিকের স্পেক §২.৫, ২৪ সেপ্টেম্বর ২০২৬।

                 তাঁর নমুনায়: `🔎 মডিউল / পর্দা / অনুমতি খুঁজুন…` আর তার নিচে
                 `[সব বাছুন] [সব মুছুন] …`

                 ── ⛔ কেন এটা ছাড়া চলছিল না ────────────────────────────────
                 ⓘ আজ ছকে **চোদ্দটা মডিউল, চারশোর বেশি অনুমতি**। ⚠️ একটা
                 নির্দিষ্ট অনুমতি (*"ক্রয় বিল অনুমোদন"*) খুঁজতে হলে চোদ্দটা
                 ভাঁজ একে একে খুলে চোখে খুঁজতে হত — আর না পেলে মানুষ ধরে নেয়
                 জিনিসটা নেই, তারপর গোটা মডিউলটাই টিক দিয়ে দেয়।

                 ⛔ *"সব বাছুন"* সত্যিই বিপজ্জনক, তাই সে **একা নয়** — পাশেই
                 *"শুধু দেখা"*, কারণ বাস্তবে সবচেয়ে চাওয়া ভূমিকাটা ঐটাই
                 (*"সবকিছু দেখবে, কিছুই বদলাবে না"*)। ⓘ সহজ পথটা নিরাপদ
                 পথ না হলে মানুষ বিপজ্জনকটাই নেয়। --}}
            <section data-boxed data-permission-grid
                     class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-3">
                <label class="flex items-center gap-2 rounded-(--radius-field) border border-(--color-border) px-2">
                    <x-ui.icon name="search" class="size-4 text-(--color-ink-muted)" />
                    <input type="search" data-permission-search
                           placeholder="{{ __('system_admin::permission.search_permissions') }}"
                           aria-label="{{ __('system_admin::permission.search_permissions') }}"
                           class="min-h-(--spacing-touch) w-full border-0 bg-transparent p-0 text-sm focus:ring-0">
                </label>

                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <x-ui.button type="button" tone="secondary" data-permission-bulk="view">
                        {{ __('system_admin::permission.read_only_preset') }}
                    </x-ui.button>
                    <x-ui.button type="button" tone="secondary" data-permission-bulk="all">
                        {{ __('system_admin::permission.select_everything') }}
                    </x-ui.button>
                    <x-ui.button type="button" tone="secondary" data-permission-bulk="none">
                        {{ __('system_admin::permission.clear_everything') }}
                    </x-ui.button>

                    {{-- ⭐ ঘরের অবস্থার ব্যাখ্যা — স্পেক §৩।

                         ⛔ স্পেকে ছয় রকম ঘর; এখানে **তিনটা**, আর কারণটা
                         ভাষা ফাইলে লেখা: বাকি তিনটার পিছনে এখনো কোনো
                         ব্যবস্থা নেই, আর ব্যবস্থাহীন চিহ্ন সাজসজ্জা। --}}
                    <span class="ms-auto flex flex-wrap items-center gap-3 text-2xs text-(--color-ink-muted)">
                        <span class="font-semibold">{{ __('system_admin::permission.legend') }}:</span>
                        <span class="flex items-center gap-1">
                            <span aria-hidden="true" class="text-(--color-badge-success-ink)">✓</span>
                            {{ __('system_admin::permission.state_granted') }}
                        </span>
                        <span class="flex items-center gap-1">
                            <span aria-hidden="true">☐</span>
                            {{ __('system_admin::permission.state_denied') }}
                        </span>
                        <span class="flex items-center gap-1">
                            <span aria-hidden="true" class="text-(--color-ink-disabled)">∅</span>
                            {{ __('system_admin::permission.state_absent') }}
                        </span>
                    </span>
                </div>

                <p data-permission-empty hidden
                   class="mt-3 text-sm text-(--color-ink-muted)">{{ __('system_admin::permission.nothing_matched') }}</p>
            </section>

            {{--
                ⭐ দুই কলামে মডিউল — মালিকের নমুনা, ২২ সেপ্টেম্বর ২০২৬।

                ℹ চৌদ্দটা মডিউল একটার পর একটা বসলে পর্দাটা অনেক লম্বা হয়,
                আর নিচের মডিউলগুলো কেউ স্ক্রল করে দেখেই না।

                ℹ `items-start` — ভাঁজ খুললে পাশের বাক্সটা যেন লম্বা না হয়।
            --}}
            {{-- ⛔ `xl:`, `2xl:` নয় — ২২ সেপ্টেম্বর ২০২৬।

                 ⚠️ এখানে লেখা ছিল `2xl:grid-cols-2`, আর সেটা **কিছুই করত
                 না**: বিল্ড করা CSS-এ ঐ ক্লাসটা নেই। ⓘ ফলে চওড়া পর্দাতেও
                 অনুমতির ছকটা এক কলামেই থাকত, আর কেউ টের পেত না — ক্লাসটা
                 ব্লেডে লেখা আছে বলে দেখে মনে হত কাজ করছে।

                 ⛔ কারণটা এই একটা ক্লাস নয়, **গোটা `2xl:` ভ্যারিয়েন্টটাই
                 এখানে তৈরি হয় না** — মেপে দেখা: একই পরীক্ষামূলক লাইনে
                 একই ইউটিলিটির `xl` রূপটা বিল্ডে আসে, `2xl` রূপটা আসে না।
                 ⚠️ এখানে কোনো আস্ত ক্লাসের নাম লেখা নেই, আর সেটা
                 ইচ্ছাকৃত: Tailwind মন্তব্যও স্ক্যান করে, তাই ব্যাখ্যাটাই
                 একটা অব্যবহৃত নিয়ম বানিয়ে বান্ডিলে বসিয়ে দিত — দুইবার
                 মেপে দেখা হয়েছে। ⓘ টোকেনে
                 `--breakpoint-2xl: 1920px` থাকা সত্ত্বেও
                 ([[tokens.css]])।

                 ⓘ `xl` এখানে ১৪৪০px — দুই কলামে ভাগ করার জন্য যথেষ্ট চওড়া। --}}
            <div class="grid items-start gap-3 xl:grid-cols-2">
            @foreach ($grid as $module => $block)
                @php
                    $on = count(array_intersect($block['all'], $chosen));
                    $all = count($block['all']);
                @endphp

                <details data-boxed data-permission-module="{{ $module }}" @if ($on > 0) open @endif
                         class="group rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
                    <summary class="flex min-h-(--spacing-touch) cursor-pointer list-none items-center gap-3 px-4 py-2
                                    [&::-webkit-details-marker]:hidden">
                        <span class="text-(--color-ink-muted) transition group-open:rotate-90" aria-hidden="true">▸</span>
                        <span class="flex-1 font-semibold">{{ $block['label'] }}</span>

                        {{--
                            ⭐ গোটা মডিউল একসাথে — মালিকের নমুনা, ২২ সেপ্টেম্বর ২০২৬।

                            ⛔ আগে একটা নতুন ভূমিকা বানাতে **দুইশোর বেশি ক্লিক** লাগত,
                            আর মানুষ তখন যা করে: সবচেয়ে কাছাকাছি একটা ভূমিকা খুঁজে নিয়ে
                            কাজ চালায়। ⚠️ ফলে মানুষ পায় তার দরকারের চেয়ে বেশি অনুমতি — আর
                            সেটা অনুমতি ব্যবস্থা না থাকার চেয়েও খারাপ, কারণ কাগজে দেখায় ঠিক আছে।

                            ℹ `<summary>`-এ চেকবক্স বসালে ক্লিক করলে বাক্সটা ভাঁজ হয়ে যায় —
                            সেটা থামানো JS-এ, ইনলাইনে নয় (CSP)।
                        --}}
                        <label class="flex cursor-pointer items-center gap-1.5 text-xs text-(--color-ink-muted)"
                               title="{{ __('system_admin::permission.select_all_module') }}">
                            <input type="checkbox" data-permission-all
                                   class="size-4 rounded border-(--color-border)"
                                   @checked($on === $all && $all > 0)>
                            <span class="hidden sm:inline">{{ __('system_admin::permission.select_all') }}</span>
                        </label>

                        <span data-permission-count
                              data-permission-total="{{ $all }}"
                              data-permission-label="{{ __('system_admin::permission.granted', ['on' => ':on', 'all' => ':all']) }}"
                              @class([
                                  'rounded-full px-2 py-0.5 text-xs tabular-nums',
                                  'bg-(--color-badge-success-bg) text-(--color-badge-success-ink)' => $on > 0,
                                  'bg-(--color-surface-muted) text-(--color-ink-muted)' => $on === 0,
                              ])>{{ __('system_admin::permission.granted', ['on' => $on, 'all' => $all]) }}</span>
                    </summary>

                    <div class="border-t border-(--color-border) px-4 pb-3 pt-1">
                        <div class="overflow-x-auto">
                            <table class="w-full min-w-[40rem] text-sm">
                                <thead>
                                    <tr class="border-b border-(--color-border) text-left text-xs text-(--color-ink-muted)">
                                        <th class="py-2 pr-3 font-medium">{{ __('system_admin::permission.column_subject') }}</th>
                                        {{--
                                            ⭐ কলামের মাথায়ও একটা টিক — "এই মডিউলের সব দেখা"।

                                            ℹ Skin Soft-এ এটা ভাগ ধরে (Settings · Reports); আমাদের
                                            সারিগুলো পর্দা, ভাগ নয় — তাই এখানে উপযুক্ত ছাঁচটা কলাম।
                                            ⛔ "সবকিছু দেখা যাবে, কিছুই বদলানো যাবে না" — সবচেয়ে সাধারণ
                                            ভূমিকাটা এক ক্লিকে বসে।
                                        --}}
                                        @foreach ($columns as $column => $label)
                                            <th class="w-20 px-2 py-2 text-center font-medium">
                                                <label class="flex cursor-pointer flex-col items-center gap-1">
                                                    <span>{{ $label }}</span>
                                                    <input type="checkbox" data-permission-column="{{ $column }}"
                                                           class="size-3.5 rounded border-(--color-border)"
                                                           aria-label="{{ $label }} — {{ __('system_admin::permission.select_all') }}">
                                                </label>
                                            </th>
                                        @endforeach
                                        <th class="py-2 pl-3 font-medium">{{ __('system_admin::permission.column_special') }}</th>
                                    </tr>
                                </thead>
                                {{--
                                    ⭐ প্রতিটা ভাগের নিজের দেহ — মালিকের নমুনা, ২২ সেপ্টেম্বর ২০২৬।

                                    ── ⛔ আগে সব সারি একটানা ছিল ─────────────────────────────
                                    হিসাব মডিউলে বাইশটা সারি একসাথে, কোনো মাথা ছাড়া। ⚠️ যিনি
                                    *"সব রিপোর্ট দেখতে দাও, আর কিছু নয়"* চান, তাঁকে বাইশটা নাম
                                    পড়ে বেছে নিতে হত — আর একটা ভুলে গেলে কেউ বলত না।

                                    ⭐ এখন ভাগের মাথায় একটা টিক, আর ঐ কাজটা এক ক্লিক।

                                    ⓘ `<tbody>` একাধিক থাকতে পারে, আর সেটাই ঠিক ছাঁচ: ভাগটা
                                    সারিগুলোর **মালিক**, কেবল উপরে বসা একটা লেবেল নয়।
                                --}}
                                @foreach ($block['sections'] as $section => $part)
                                <tbody data-permission-section="{{ $section }}">
                                    <tr data-permission-section-head class="bg-(--color-surface-muted)">
                                        <th scope="colgroup" colspan="{{ count($columns) + 2 }}"
                                            class="px-0 py-1.5 text-left text-xs font-semibold text-(--color-ink-muted)">
                                            <label class="flex cursor-pointer items-center gap-2">
                                                <input type="checkbox" data-permission-section-all
                                                       class="size-3.5 rounded border-(--color-border)"
                                                       @checked(count(array_intersect($part['all'], $chosen)) === count($part['all']) && $part['all'] !== [])
                                                       aria-label="{{ $part['label'] }} — {{ __('system_admin::permission.select_all') }}">
                                                {{ $part['label'] }}
                                            </label>
                                        </th>
                                    </tr>

                                    @foreach ($part['rows'] as $row)
                                        {{-- ⓘ খোঁজার জন্য এই সারির সব লেখা এক জায়গায়: মডিউলের
                                             নাম, জিনিসের নাম আর **কাঁচা অনুমতির নামগুলো**।

                                             ⚠️ কাঁচা নামগুলোও থাকতে হবে: ডেভেলপার বা সাপোর্ট
                                             `sales.invoice.approve` লিখে খোঁজেন, আর পর্দায় ঐ
                                             লেখাটা কোথাও দেখা যায় না। --}}
                                        @php
                                            $haystack = mb_strtolower(implode(' ', array_filter([
                                                $block['label'],
                                                $row['label'],
                                                $row['key'],
                                                ...array_values($row['cells']),
                                                $row['manage'],
                                                ...array_keys($row['special']),
                                                ...array_values($row['special']),
                                            ])));
                                        @endphp
                                        <tr data-permission-row data-permission-text="{{ $haystack }}"
                                            class="border-b border-(--color-border) last:border-0">
                                            <th scope="row" class="py-2 pr-3 text-left font-normal">{{ $row['label'] }}</th>

                                            @foreach ($columns as $column => $label)
                                                @if ($column === 'create' && $row['manage'])
                                                    <td colspan="3" class="px-2 py-2 text-center">
                                                        @include('system_admin::role.partials.switch', [
                                                            'name' => $row['manage'],
                                                            'label' => $row['label'].' — '.__('system_admin::permission.verbs.manage'),
                                                            'checked' => in_array($row['manage'], $chosen, true),
                                                            'caption' => __('system_admin::permission.manage_spans'),
                                                            'cell' => 'manage',
                                                        ])
                                                    </td>
                                                @elseif ($row['manage'] && in_array($column, ['update', 'delete'], true))
                                                    {{-- তিন কলাম জুড়ে বসেছে — উপরের ঘরেই --}}
                                                @elseif (isset($row['cells'][$column]))
                                                    <td class="px-2 py-2 text-center">
                                                        @include('system_admin::role.partials.switch', [
                                                            'name' => $row['cells'][$column],
                                                            'label' => $row['label'].' — '.$label,
                                                            'checked' => in_array($row['cells'][$column], $chosen, true),
                                                            'cell' => $column,
                                                        ])
                                                    </td>
                                                @else
                                                    {{-- ⭐ `∅` — *"এই পর্দায় এই কাজটাই নেই"*, স্পেক §৩।

                                                         ── ⛔ আগে এখানে একটা `—` বসত, আর সেটা ভুল ছিল ──
                                                         ⚠️ স্পেক স্পষ্ট করে বলে দুইটা আলাদা: `∅` মানে
                                                         কাজটার **অস্তিত্বই নেই**, আর `—` মানে
                                                         *"উত্তরাধিকারে পাওয়া"*। ⓘ আমাদের ঘরটা প্রথমটা,
                                                         অথচ চিহ্নটা ছিল দ্বিতীয়টার।

                                                         ⛔ আর `aria-hidden` থাকায় পর্দা-পাঠকের কাছে
                                                         ঘরটা **নীরব** ছিল: যিনি চোখে দেখেন না, তিনি
                                                         "দেওয়া নেই" আর "এমন কাজই নেই" আলাদা করতে
                                                         পারতেন না — অথচ পার্থক্যটাই এখানে আসল কথা। --}}
                                                    <td class="px-2 py-2 text-center text-(--color-ink-disabled)"
                                                        title="{{ __('system_admin::permission.state_absent') }}">
                                                        <span aria-hidden="true">∅</span>
                                                        <span class="sr-only">{{ __('system_admin::permission.state_absent') }}</span>
                                                    </td>
                                                @endif
                                            @endforeach

                                            <td class="py-2 pl-3">
                                                <div class="flex flex-wrap gap-1.5">
                                                    @foreach ($row['special'] as $name => $verb)
                                                        <label class="cursor-pointer" title="{{ $name }}">
                                                            <input type="checkbox" name="permissions[]" value="{{ $name }}"
                                                                   class="peer sr-only" @checked(in_array($name, $chosen, true))>
                                                            <span class="inline-flex items-center gap-1 rounded-full border border-(--color-border)
                                                                         px-2 py-0.5 text-xs text-(--color-ink-muted)
                                                                         peer-checked:border-(--color-brand-500) peer-checked:bg-(--color-brand-50)
                                                                         peer-checked:font-medium peer-checked:text-(--color-brand-700)
                                                                         peer-focus-visible:ring-2 peer-focus-visible:ring-(--color-brand-500)">
                                                                {{ $verb }}
                                                            </span>
                                                        </label>
                                                    @endforeach
                                                </div>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                @endforeach
                            </table>
                        </div>
                    </div>
                </details>
            @endforeach
            </div>

            <div class="flex flex-wrap justify-end gap-2">
                <x-ui.button tone="secondary" :href="route('system_admin.role.index')">
                    {{ __('core.action.cancel') }}
                </x-ui.button>
                <x-ui.button type="submit" tone="primary">{{ __('core.action.save') }}</x-ui.button>
            </div>
        </form>
        @endif

        {{-- ── ডানে: অ্যাকসেসের বিবরণ — স্পেক §২.৬ ─────────────────────── --}}
        <aside data-boxed x-data="{ q: '' }"
               class="order-3 space-y-4 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-3
                      lg:col-start-2 xl:col-start-auto">

            {{-- ⭐ অনুমোদনের ক্ষমতা — স্পেক §২.৬, ২৪ সেপ্টেম্বর ২০২৬।

                 ── ⛔ কেন এটা এখানে থাকতেই হবে ──────────────────────────
                 ⓘ অনুমোদনের ক্ষমতা অনুমতির ছকে **আসেই না** — ওটা বসে
                 [[ApprovalFlowStep]]-এ, একটা আলাদা পর্দায়। ⚠️ ফলে এতদিন
                 রোলের পর্দা দেখে কেউ বুঝতেই পারতেন না যে এই রোলটা পঞ্চাশ
                 লাখ টাকার কাগজ ছাড়তে পারে।

                 ⛔ সারিগুলো মাপা, স্পেকের নমুনার সংখ্যা নয় — কারণসহ
                 [[RoleController::approvalPower()]]-এ। ⓘ একটাও না থাকলে
                 ঘরটা আঁকাই হয় না: শূন্য একটা ছক দেখালে মনে হত ব্যবস্থাটা
                 আছে অথচ খালি, আর আসল কথাটা হলো এই রোল কোথাও ধাপ নয়। --}}
            @if ($approvalPower !== [])
                <section>
                    <h2 class="text-sm font-semibold">{{ __('system_admin::permission.approval_power') }}</h2>

                    <ul class="mt-2 space-y-1.5">
                        @foreach ($approvalPower as $step)
                            <li class="flex items-baseline justify-between gap-2 text-sm">
                                <span class="min-w-0">
                                    <span class="block truncate">{{ $step['label'] }}</span>
                                    <span class="block text-2xs text-(--color-ink-muted)">
                                        {{ $step['module'] }} ·
                                        {{ __('system_admin::permission.approval_level', ['level' => $step['level']]) }}
                                    </span>
                                </span>

                                {{-- ⓘ সীমা না থাকা মানে *"সব কাগজেই"*, শূন্য নয় —
                                     ⚠️ "৳০" লিখলে মনে হত সীমাটা বসানো আছে আর শূন্য। --}}
                                <span class="num flex-none text-xs">
                                    @if ($step['threshold'] === null)
                                        {{ __('system_admin::permission.approval_always') }}
                                    @else
                                        ৳{{ \App\Core\Support\Money::format($step['threshold']) }}+
                                    @endif
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            <h2 class="text-sm font-semibold">
                {{ __('system_admin::permission.users_in_role') }}
                <span class="tabular-nums text-(--color-ink-muted)">({{ $members->count() }})</span>
            </h2>

            @if ($members->isEmpty())
                <p class="mt-3 text-sm text-(--color-ink-muted)">{{ __('system_admin::permission.no_users') }}</p>
            @else
                <label class="mt-3 flex items-center gap-2 rounded-(--radius-field) border border-(--color-border) px-2">
                    <x-ui.icon name="search" class="size-4 text-(--color-ink-muted)" />
                    <input type="search" x-model="q" placeholder="{{ __('system_admin::permission.search_users') }}"
                           aria-label="{{ __('system_admin::permission.search_users') }}"
                           class="min-h-(--spacing-touch) w-full border-0 bg-transparent p-0 text-sm focus:ring-0">
                </label>

                <ul class="mt-2 space-y-0.5">
                    @foreach ($members as $member)
                        <li x-show="! q || @js(mb_strtolower($member->name.' '.$member->email)).includes(q.toLowerCase())">
                            <a href="{{ route('system_admin.user.edit', $member->id) }}"
                               class="flex min-h-(--spacing-touch) items-center gap-2 rounded-(--radius-field) px-2 hover:bg-(--color-surface-hover)">
                                <span class="grid size-7 flex-none place-items-center rounded-full bg-(--color-avatar) text-xs font-semibold text-(--color-avatar-ink)">
                                    {{ mb_substr($member->name, 0, 1) }}
                                </span>
                                <span class="min-w-0">
                                    <span class="block truncate text-sm">{{ $member->name }}</span>
                                    <span class="block truncate text-xs text-(--color-ink-muted)">{{ $member->email }}</span>
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </aside>
    </div>
</x-layouts.app>
