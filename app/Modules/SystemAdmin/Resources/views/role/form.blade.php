{{--
    রোল — তৈরি ও সম্পাদনা। তিন ভাগ: বাঁয়ে রোলের তালিকা, মাঝে অনুমতির
    ছক, ডানে এই রোলে কারা আছেন।

    ── ⛔ কেন ছক, ১৯ সেপ্টেম্বর ২০২৬ ────────────────────────────────────
    আগে প্রতিটা মডিউলের নিচে অনুমতিগুলো **কাঁচা নামে** বসত —
    `accounts.voucher.update`, `inventory.stock.opening`। ⓘ মালিক দুইটা
    নকশা পাঠালেন (ERPNext-এর Role Permissions, আর একটা User Management):
    প্রতিটা জিনিস এক সারি, পাশে দেখা · তৈরি · সম্পাদনা · মোছা সুইচ।

    ⚠️ কাঁচা নামে টিক দেওয়া মানে না বুঝে অধিকার দেওয়া — আর এখানে ভুল
    টিক মানে ভুল মানুষের হাতে টাকার দরজা।

    ── ⓘ জমা দেওয়া বদলায়নি ────────────────────────────────────────────
    প্রতিটা সুইচ এখনো `permissions[]`-এ পুরো নামটাই পাঠায়। ছকটা কেবল
    সাজানো — কোন ঘরে কোনটা বসবে সেটা `RoleController::formData()` ঠিক করে,
    আর যেটা চার কলামে ধরে না সেটা "বিশেষ" কলামে যায়, বাদ পড়ে না।

    ── ⚠️ মডিউলগুলো ভাঁজ করা, যেখানে কিছু দেওয়া নেই ────────────────────
    কুড়িটা মডিউল একসাথে খোলা থাকলে পর্দা দশ স্ক্রিন লম্বা। ⓘ যে
    মডিউলে এই রোলের অন্তত একটা অনুমতি আছে সেটা খোলা, বাকিগুলো ভাঁজ
    করা — মাথায় "৩ / ১২" গুনে দেখায়, তাই ভাঁজ করা অংশেও কিছু লুকায় না।
--}}
@php
    $chosen = collect(old('permissions', $held))->all();
    $isNew = ! $role->exists;
    $title = $isNew ? __('system_admin::action.new_role') : \App\Core\Support\RoleLabel::for($role->name);

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

    <div class="grid items-start gap-4 lg:grid-cols-[15rem_minmax(0,1fr)] xl:grid-cols-[15rem_minmax(0,1fr)_16rem]">

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

            <ul class="mt-2 space-y-0.5">
                @foreach ($roleList as $r)
                    @php
                        $current = $role->exists && $r->id === $role->id;
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
                                <span class="tabular-nums text-xs text-(--color-ink-muted)">{{ $r->users_count }}</span>
                            </a>
                        @endif
                    </li>
                @endforeach
            </ul>
        </aside>

        {{-- ── মাঝে: নাম আর অনুমতির ছক ───────────────────────────────── --}}
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
                    <h2 class="text-base font-semibold">{{ $title }}</h2>
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

            {{--
                ⭐ দুই কলামে মডিউল — মালিকের নমুনা, ২২ সেপ্টেম্বর ২০২৬।

                ℹ চৌদ্দটা মডিউল একটার পর একটা বসলে পর্দাটা অনেক লম্বা হয়,
                আর নিচের মডিউলগুলো কেউ স্ক্রল করে দেখেই না।

                ⚠️ কেবল `2xl`-এ দুই কলাম: ভিতরের ছকটার নিজেরই
                `min-w-[40rem]`, তাই তার চেয়ে সরু পর্দায় ভাগ করলে প্রতিটা
                মডিউলে আড়াআড়ি স্ক্রলবার উঠত — লম্বা পর্দার চেয়ে খারাপ।

                ℹ `items-start` — ভাঁজ খুললে পাশের বাক্সটা যেন লম্বা না হয়।
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
                            আর মানুষ তখন যা করে: সবচেয়ে কাছাকাছি একটা ভূমিকা খুঁজে নিয়ে
                            কাজ চালায়। ⚠️ ফলে মানুষ পায় তার দরকারের চেয়ে বেশি অনুমতি — আর
                            সেটা অনুমতি ব্যবস্থা না থাকার চেয়েও খারাপ, কারণ কাগজে দেখায় ঠিক আছে।

                            ℹ `<summary>`-এ চেকবক্স বসালে ক্লিক করলে বাক্সটা ভাঁজ হয়ে যায় —
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
                                            ⭐ কলামের মাথায়ও একটা টিক — "এই মডিউলের সব দেখা"।

                                            ℹ Skin Soft-এ এটা ভাগ ধরে (Settings · Reports); আমাদের
                                            সারিগুলো পর্দা, ভাগ নয় — তাই এখানে উপযুক্ত ছাঁচটা কলাম।
                                            ⛔ "সবকিছু দেখা যাবে, কিছুই বদলানো যাবে না" — সবচেয়ে সাধারণ
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
                                    <tr class="bg-(--color-surface-muted)">
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
                                        <tr class="border-b border-(--color-border) last:border-0">
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
                                                    <td class="px-2 py-2 text-center text-(--color-ink-disabled)" aria-hidden="true">—</td>
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

        {{-- ── ডানে: এই রোলে কারা ──────────────────────────────────────── --}}
        <aside data-boxed x-data="{ q: '' }"
               class="order-3 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-3
                      lg:col-start-2 xl:col-start-auto">
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
