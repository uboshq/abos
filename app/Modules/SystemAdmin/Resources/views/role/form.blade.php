{{--
    রোল ও অনুমতি — **একটাই** পর্দা (২৪ সেপ্টেম্বর ২০২৬: "ekoi jinis dui porda keno?")।

    ── ⭐ নতুন চেহারা — মালিকের অনুমোদিত নমুনা, ২০ নভেম্বর ২০২৬ ("ok") ─────────────────────────────────────────────
    ১. মাথা এক সারিতে: নাম ⓘ (ব্যাখ্যার লাইন ⓘ-এর ভেতরে — সব পাতার নিয়ম, [[x-ui.page-header]]), পিল ট্যাব (রোল · ব্যবহারকারী ·
       দুই রোল পাশাপাশি — [[x-ui.list-tabs]], "ABOS"-এ মাথার সারিতে ওঠে), ডানে ছাপুন · Excel · ＋ নতুন রোল।
    ২. পাঁচটা বড় বক্স → চিকন এক-লাইনের রঙিন কার্ড (অন্য তালিকার `list-card`); "এই রোলে যাঁরা আছেন" উঠে গেল — সদস্য ট্যাবের
       গুনতির সাথে একই সংখ্যা দুইবার হত (মালিকের নিয়ম: এক সংখ্যা এক জায়গায়)। "দায়িত্বের সংঘাত" নতুন।
    ৩. ডানের আলাদা কলাম নেই — অনুমোদনের ক্ষমতা আর সদস্য রোলের নিজের ট্যাবে; অনুমতির ছক পুরো চওড়া।
    ৪. বাঁয়ে রোলের তালিকা (২৭০px): সিস্টেম · ব্যবসায়িক · নিজের বানানো · যৌথ রোল।
    ৫. মডিউল এক সারিতে একটা, ভাঁজ করা, একটা খুললে আগেরটা বন্ধ ([[role/partials/matrix]])।
    ৬. রোলের নামের পাশে দায়িত্বের সংঘাতের চিহ্ন। ৭. নিচে স্থির পট্টি — "● n টা ঘর বদলেছে", সংরক্ষণে কারণ লাগে।
    ৮. "কে আসলে কী পারে"। ৯. যৌথ রোল। ১০. সময়-বাঁধা সদস্যপদ।

    ── ⓘ জমা দেওয়া বদলায়নি ───────────────────────────────────────────────────────────────────────────────────
    প্রতিটা ঘর এখনো `permissions[]`-এ পুরো নামটাই পাঠায় — কোন ঘরে কোনটা বসবে তা `RoleController::formData()` ঠিক করে, আর যা
    আট কলামে ধরে না তা "অন্য কাজ" কলামে যায়, বাদ পড়ে না।
--}}
@php
    /* ⓘ `null` = তালিকার পর্দা, `exists === false` = নতুন রোল। */
    $picked = $role !== null;
    $isNew = $picked && ! $role->exists;
    $isComposite = (bool) $composite;

    $chosen = collect(old('permissions', $held))->all();

    $title = ! $picked
        ? __('system_admin::menu.roles')
        : ($isNew ? ($newKind === 'composite' ? __('system_admin::permission.new_composite') : __('system_admin::action.new_role')) : \App\Core\Support\RoleLabel::for($role->name));

    $columns = [];
    foreach (['view', 'create', 'update', 'delete', 'approve', 'post', 'export', 'print'] as $c) {
        $columns[$c] = __('system_admin::permission.column_'.$c);
    }

    $saved = $picked && ! $isNew;
    $here = $saved ? route('system_admin.role.edit', $role) : route('system_admin.role.index');

    /* ⓘ রোলের ট্যাব — যেটার কিছু বলার নেই সেটা আঁকা হয় না (অনুমোদনের ক্ষমতা শূন্য হলে ট্যাবটাই নেই, আগের "বাক্স নেই"-এর মতো) */
    $roleTabs = ['permissions' => __('system_admin::permission.tab_permissions')];
    if ($saved && $isComposite) {
        $roleTabs['includes'] = __('system_admin::permission.tab_includes', ['count' => count($includes)]);
    }
    if ($saved && $approvalPower !== []) {
        $roleTabs['approval'] = __('system_admin::permission.tab_approval', ['count' => count($approvalPower)]);
    }
    if ($saved) {
        $roleTabs['members'] = __('system_admin::permission.tab_members', ['count' => collect($memberRows)->where('state.0', '!=', 'off')->count()]);
        $roleTabs['effective'] = __('system_admin::permission.tab_effective');
        $roleTabs['history'] = __('system_admin::permission.tab_history');
    }
    if ($newKind === 'composite') {
        $roleTabs = ['includes' => __('system_admin::permission.tab_includes', ['count' => count(old('includes', []))])];
    }
    $startTab = isset($roleTabs[$tab]) ? $tab : array_key_first($roleTabs);

@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $title }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('system_admin::menu.roles')"
                          :subtitle="__('system_admin::message.roles_note')">
            <x-slot:actions>
                {{-- ⭐ ছাপুন · Excel — বাছা রোলের পুরো ছক, সাধারণ রপ্তানির পথে ([[RoleController::matrix()]]) --}}
                @if ($saved)
                    <x-ui.button tone="secondary" icon="printer" data-role-print target="_blank"
                                 :href="route('system_admin.role.matrix', ['role' => $role, 'export' => 'print'])">
                        {{ __('system_admin::permission.print') }}
                    </x-ui.button>
                    <x-ui.button tone="secondary" icon="download" data-role-excel
                                 :href="route('system_admin.role.matrix', ['role' => $role, 'export' => 'xlsx'])">
                        Excel
                    </x-ui.button>
                @endif
                <x-ui.button tone="primary" icon="plus" :href="route('system_admin.role.create')">
                    {{ __('system_admin::action.new_role') }}
                </x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>

    {{-- ⭐ পাতার পিল ট্যাব — "ABOS"-এ নামের পাশে মাথার সারিতে ওঠে ([[abos-list-head.js]] `compactPageHead`) --}}
    <x-ui.list-tabs :label="__('system_admin::menu.roles')" :tabs="array_values(array_filter([
        ['key' => 'roles', 'label' => __('system_admin::permission.pill_roles'), 'url' => route('system_admin.role.index'), 'active' => true],
        \Illuminate\Support\Facades\Route::has('system_admin.user.index')
            ? ['key' => 'users', 'label' => __('system_admin::permission.pill_users'), 'url' => route('system_admin.user.index'), 'active' => false] : null,
        ['key' => 'compare', 'label' => __('system_admin::permission.compare_roles'),
            'url' => $saved ? route('system_admin.role.compare', ['a' => $role->id]) : route('system_admin.role.compare'), 'active' => false],
    ]))" />

    @if (session('saved'))
        <div role="status"
             class="mb-3 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    {{-- ⭐ চিকন কার্ড — দফা ২ ("উপরের বড় বক্স পাঁচটা — চিকন, এক লাইনে, অন্য পাতার মতো")। ⓘ "যে রোলে কেউ নেই" আর
         "দায়িত্বের সংঘাত" চাপলে বাঁয়ের তালিকা কেবল সেগুলো; আবার চাপলে সব। --}}
    @php
        $cardHref = fn (string $key) => $only === $key ? $here : $here.'?only='.$key;
        $cards = [
            ['users', 'system_admin::permission.card_users', '👥', $summary['users'] ?? 0, '', ['#2346d8', '#3b82f6'],
                \Illuminate\Support\Facades\Route::has('system_admin.user.index') ? route('system_admin.user.index') : $here, false],
            ['roles', 'system_admin::permission.card_roles', '🔑', $summary['roles'] ?? 0, '', ['#059669', '#10b981'], $here, false],
            ['unassigned', 'system_admin::permission.card_unassigned', '∅', $summary['unassigned'] ?? 0, '', ['#dc2626', '#f97316'], $cardHref('empty'), $only === 'empty'],
            ['clash', 'system_admin::permission.card_clash', '⚠', $summary['clash'] ?? 0, '', ['#d97706', '#f59e0b'], $cardHref('clash'), $only === 'clash'],
            ['last-change', 'system_admin::permission.card_last_change', '🕒',
                $lastChange ? \App\Core\Support\DateFormat::spokenDate($lastChange['at']) : '—',
                $lastChange && $lastChange['by'] ? __('system_admin::permission.changed_by', ['name' => $lastChange['by']]) : '',
                ['#0284c7', '#22d3ee'], $saved ? $here.'?tab=history' : $here, false],
        ];
    @endphp
    {{-- ⓘ `data-allow-all-branches` — কার্ড আর তালিকা কেবল রোল দেখার লিংক (রোল কোম্পানির, শাখার নয়); "সব শাখা"-তে ধূসর হত, কারণ রোলের পাতার ঠিকানা `/edit`-এ শেষ। সংরক্ষণ তবু বন্ধ থাকে --}}
    <div class="list-cards role-cards" data-role-cards data-allow-all-branches>
        @foreach ($cards as [$key, $label, $icon, $value, $sub, $tone, $href, $on])
            <a href="{{ $href }}" @class(['list-card', 'is-on' => $on]) data-role-card="{{ $key }}"
               style="--a: {{ $tone[0] }}; --b: {{ $tone[1] }}" @if ($on) aria-current="true" @endif>
                <span class="list-card-icon" aria-hidden="true">{{ $icon }}</span>
                <span class="min-w-0">
                    <span class="list-card-label">{{ __($label) }}</span>
                    <span class="list-card-value num">{{ $value }}</span>
                    <span class="list-card-sub">{{ $sub }}</span>
                </span>
            </a>
        @endforeach
    </div>

    <div class="role-body" data-role-screen data-role-tab-start="{{ $startTab }}">

        {{-- ── বাঁয়ে: রোলের তালিকা (২৭০px) ────────────────────────────────────── --}}
        <aside data-boxed x-data="{ q: '' }" class="role-list" data-allow-all-branches>
            <label class="role-search">
                <x-ui.icon name="search" class="size-4 text-(--color-ink-muted)" />
                {{-- ⓘ `data-shortcut` — Ctrl+K এই ঘরটাই খোঁজে --}}
                <input type="search" x-model="q" data-shortcut="search-roles"
                       placeholder="{{ __('system_admin::permission.search_roles') }}"
                       aria-label="{{ __('system_admin::permission.search_roles') }}" title="Ctrl+K">
            </label>

            @if ($only)
                <a href="{{ $here }}" class="role-filter-on" data-role-filter-clear>
                    {{ __('system_admin::permission.filter_'.$only) }} ✕
                </a>
            @endif

            @php
                $legendShown = false;
            @endphp
            @foreach ($roleList as $groupKey => $group)
                <h3 class="role-group-head">
                    <span>{{ $group['label'] }}</span>
                    @if ($groupKey === 'composite')
                        <small>{{ __('system_admin::permission.legend_composite') }}</small>
                    @elseif ($groupKey !== 'system' && ! $legendShown)
                        <small>{{ __('system_admin::permission.legend_people_permissions') }}</small>
                        @php
                            $legendShown = true;
                        @endphp
                    @endif
                </h3>

                <ul class="role-items">
                    @foreach ($group['roles'] as $r)
                        @php
                            $current = $saved && $r->id === $role->id;
                            $label = \App\Core\Support\RoleLabel::for($r->name);
                        @endphp
                        <li x-show="! q || @js(mb_strtolower($label.' '.$r->name)).includes(q.toLowerCase())">
                            @if ($r->name === $ownerRole)
                                <div class="role-item is-locked" title="{{ __('system_admin::permission.owner_locked') }}">
                                    <x-ui.icon name="lock" class="size-4 flex-none" />
                                    <span class="role-item-name" title="{{ $label }}">{{ $label }}</span>
                                    <small class="num">{{ $r->users_count }}</small>
                                </div>
                            @else
                                <a href="{{ route('system_admin.role.edit', $r) }}{{ $only ? '?only='.$only : '' }}"
                                   @if ($current) aria-current="page" @endif
                                   @class(['role-item', 'is-on' => $current])>
                                    <x-ui.icon :name="$groupKey === 'composite' ? 'grid' : 'people'" class="size-4 flex-none" />
                                    <span class="role-item-name" title="{{ $label }}">{{ $label }}</span>
                                    {{-- ⓘ দুইটা সংখ্যা: কতজন · কয়টা অনুমতি (যৌথে: কতজন · কয়টা রোল) --}}
                                    <small class="num" title="{{ __('system_admin::field.user_count') }} · {{ __('system_admin::field.permission_count') }}">
                                        @if ($groupKey === 'composite')
                                            {{ $r->users_count }} · {{ __('system_admin::permission.roles_inside', ['count' => count(app(\App\Core\Services\CompositeRoles::class)->includedIds((int) $r->id))]) }}
                                        @else
                                            {{ $r->users_count }} · {{ $r->permissions_count }}
                                        @endif
                                    </small>
                                </a>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endforeach

            {{-- ⭐ যৌথ রোল — কয়েকটা রোল মিলিয়ে একটা (নমুনা, দফা ৯) --}}
            <a href="{{ route('system_admin.role.create', ['kind' => 'composite']) }}" class="role-new-composite" data-role-new-composite>
                ＋ {{ __('system_admin::permission.new_composite') }}
            </a>
        </aside>

        {{-- ── ডানে: বাছা রোল, পুরো চওড়া ──────────────────────────────────────── --}}
        @if (! $picked)
            {{-- ⓘ কোনো রোল বাছা হয়নি — খালি ছক নয়, একটা কথা (সব টিক তোলা ছক দেখালে মনে হত রোলটার কিছুই নেই) --}}
            <section data-boxed class="role-main role-pick">
                <div>
                    <x-ui.icon name="lock" class="mx-auto size-8 text-(--color-ink-muted)" />
                    <h2 class="mt-3 text-base font-semibold">{{ __('system_admin::permission.pick_a_role') }}</h2>
                    <p class="mx-auto mt-1 max-w-sm text-sm text-(--color-ink-muted)">{{ __('system_admin::permission.pick_a_role_note') }}</p>
                </div>
            </section>
        @else
        <section data-boxed class="role-main">
            <form method="POST" id="role-form" data-role-form @if ($saved) data-role-reason-needed @endif
                  action="{{ $isNew ? route('system_admin.role.store') : route('system_admin.role.update', $role) }}">
                @csrf
                @unless ($isNew) @method('PUT') @endunless
                @if ($newKind === 'composite')
                    <input type="hidden" name="kind" value="composite">
                @endif

                @if ($errors->any())
                    <div role="alert" class="role-errors">
                        <ul>
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                {{-- ── রোলের মাথা: নাম · ভাগ · তৈরি · সংঘাত · এর মতো নতুন · নাম বদলান ── --}}
                <div class="role-head">
                    <h2>{{ $title }}</h2>

                    @if ($saved)
                        <span class="role-meta">
                            @foreach ($roleList as $groupKey => $group)
                                @if ($group['roles']->contains('id', $role->id))
                                    {{ $group['label'] }} ·
                                    @break
                                @endif
                            @endforeach
                            @if ($role->created_at)
                                {{ __('system_admin::permission.made_on', ['date' => \App\Core\Support\DateFormat::spokenDate($role->created_at)]) }} ·
                            @endif
                            {{ __('system_admin::permission.inner_name', ['name' => $role->name]) }}
                        </span>

                        {{-- ⭐ দায়িত্বের সংঘাত — দফা ৬; চাপলে কোন কাগজে --}}
                        @if ($clashes === [])
                            <span class="role-sod is-ok" data-role-sod="0" title="{{ __('system_admin::permission.sod_hint') }}">✓ {{ __('system_admin::permission.sod_none') }}</span>
                        @else
                            <details class="role-sod-wrap" data-role-sod="{{ count($clashes) }}">
                                <summary class="role-sod is-warn">⚠ {{ __('system_admin::permission.sod_some', ['count' => count($clashes)]) }}</summary>
                                <div class="role-pop">
                                    <p>{{ __('system_admin::permission.sod_hint') }}</p>
                                    <ul>
                                        @foreach ($clashes as $clash)
                                            <li>{{ $clash }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            </details>
                        @endif
                    @endif

                    <span class="role-grow"></span>

                    @if ($saved)
                        {{-- ⓘ "দুই রোল পাশাপাশি" মাথার পিল ট্যাবে; এখানে "এর মতো নতুন রোল" আর "নাম বদলান" --}}
                        @unless ($isComposite)
                            <x-ui.button tone="secondary" icon="plus" data-role-copy
                                         :href="route('system_admin.role.create', ['from' => $role->id])">
                                {{ __('system_admin::permission.copy_role') }}
                            </x-ui.button>
                        @endunless
                        <details class="role-rename" data-role-rename @if ($errors->has('name')) open @endif>
                            <summary class="role-btn">✎ {{ __('system_admin::permission.rename') }}</summary>
                            <div class="role-pop">
                                <x-ui.field name="name" :label="__('system_admin::field.role_name')"
                                            :value="old('name', $role->name)"
                                            :hint="__('system_admin::field.role_name_hint')" required />
                                @if (\App\Core\Support\RoleLabel::for($role->name) !== $role->name)
                                    <p data-role-shown-as class="mt-1 text-xs text-(--color-ink-muted)">
                                        {{ __('system_admin::permission.shown_as', ['name' => \App\Core\Support\RoleLabel::for($role->name)]) }}
                                    </p>
                                @endif
                            </div>
                        </details>
                    @endif
                </div>

                @if ($saved)
                    {{-- ⭐ দফা ১৩ (কন্ট্রোল প্যানেল): শেষ বদল কে করলেন, আর নিরীক্ষার খাতার দরজা --}}
                    <x-audit.last-change :subject="$role" class="role-last-change" />
                @else
                    <div class="role-newname">
                        <x-ui.field name="name" :label="__('system_admin::field.role_name')"
                                    :value="old('name', $role->name)"
                                    :hint="__('system_admin::field.role_name_hint')" required />
                    </div>
                @endif

                {{-- ── রোলের ট্যাব ── --}}
                <div class="role-tabs" role="tablist" aria-label="{{ $title }}">
                    @foreach ($roleTabs as $key => $label)
                        <button type="button" role="tab" data-role-tab="{{ $key }}" id="role-tab-{{ $key }}"
                                aria-controls="role-panel-{{ $key }}" aria-selected="{{ $key === $startTab ? 'true' : 'false' }}"
                                @class(['role-tab', 'is-on' => $key === $startTab])>{{ $label }}</button>
                    @endforeach
                </div>

                @if ($newKind === 'composite')
                    {{-- ⭐ নতুন যৌথ রোল — ছক নয়, কোন রোলগুলো মিলবে (দফা ৯) --}}
                    <section class="role-panel" data-role-panel="includes" id="role-panel-includes" role="tabpanel" aria-labelledby="role-tab-includes">
                        @include('system_admin::role.partials.includes', ['choices' => $allRoles, 'picked' => array_map('intval', old('includes', [])), 'self' => null])
                    </section>
                @else
                    {{-- ── অনুমতি ── --}}
                    <section data-permission-grid class="role-panel" data-role-panel="permissions" id="role-panel-permissions" role="tabpanel"
                             aria-labelledby="role-tab-permissions" @if ($startTab !== 'permissions') hidden @endif>
                        @if ($isComposite)
                            <p class="role-note" data-role-composite-note>{{ __('system_admin::permission.composite_matrix_note') }}</p>
                        @endif

                        {{-- ⭐ ছকের সরঞ্জাম — খোঁজা, শুধু দেখা, সব বাছুন, সব মুছুন, কেবল দেওয়াগুলো, ঘরের অবস্থা (স্পেক §২.৫) --}}
                        <div class="role-tools">
                            <label class="role-search role-search-wide">
                                <x-ui.icon name="search" class="size-4 text-(--color-ink-muted)" />
                                <input type="search" data-permission-search
                                       placeholder="{{ __('system_admin::permission.search_permissions') }}"
                                       aria-label="{{ __('system_admin::permission.search_permissions') }}"
                                       title="{{ __('system_admin::permission.shortcuts') }}">
                                <kbd>Ctrl F</kbd>
                            </label>
                            @unless ($isComposite)
                                <button type="button" class="role-btn" data-permission-bulk="view">{{ __('system_admin::permission.read_only_preset') }}</button>
                                <button type="button" class="role-btn" data-permission-bulk="all">{{ __('system_admin::permission.select_everything') }}</button>
                                <button type="button" class="role-btn" data-permission-bulk="none">{{ __('system_admin::permission.clear_everything') }}</button>
                            @endunless
                            <label class="role-btn">
                                <input type="checkbox" data-permission-granted-only class="role-ck">
                                {{ __('system_admin::permission.granted_only') }}
                            </label>
                            <span class="role-legend">
                                <span><span class="role-mark is-on" aria-hidden="true">✓</span> {{ __('system_admin::permission.state_granted') }}</span>
                                <span><span class="role-mark" aria-hidden="true"></span> {{ __('system_admin::permission.state_denied') }}</span>
                                <span><span class="role-dash" aria-hidden="true">–</span> {{ __('system_admin::permission.state_absent') }}</span>
                            </span>
                        </div>

                        <p data-permission-empty hidden class="role-note">{{ __('system_admin::permission.nothing_matched') }}</p>

                        @include('system_admin::role.partials.matrix', [
                            'grid' => $grid, 'chosen' => $chosen, 'columns' => $columns, 'readonly' => $isComposite, 'prefix' => 'perm',
                        ])

                        {{-- ⭐ স্থির পট্টি — দফা ৭: "● n টা ঘর বদলেছে" · বাতিল · সংরক্ষণ ([[x-ui.form-actions]]) --}}
                        <x-ui.form-actions :cancel="route('system_admin.role.index')">
                            <span class="role-dirty" data-role-dirty hidden
                                  data-label="{{ __('system_admin::permission.dirty', ['count' => ':count']) }}"></span>
                            <span class="role-dirty-note">{{ __('system_admin::permission.dirty_note') }}</span>
                        </x-ui.form-actions>

                        {{-- ⭐ কারণ — সংরক্ষণের আগে কোন ঘরগুলো বদলাল তার সারাংশ, আর কারণ লেখা (ইতিহাসে থাকে) --}}
                        @if ($saved)
                            <dialog class="role-dialog" data-role-reason aria-labelledby="role-reason-title">
                                <h3 id="role-reason-title">{{ __('system_admin::permission.reason_title') }}</h3>
                                <ul class="role-changes" data-role-reason-list
                                    data-add="{{ __('system_admin::permission.change_added') }}"
                                    data-remove="{{ __('system_admin::permission.change_removed') }}"
                                    data-rename="{{ __('system_admin::permission.change_renamed') }}"></ul>
                                <label class="role-reason-field">
                                    <span>{{ __('system_admin::permission.reason_label') }}</span>
                                    <textarea name="reason" rows="3" maxlength="500" data-role-reason-text>{{ old('reason') }}</textarea>
                                </label>
                                <div class="role-dialog-actions">
                                    <button type="button" class="role-btn" data-role-reason-cancel>{{ __('core.action.cancel') }}</button>
                                    <button type="submit" class="role-btn is-primary" data-role-reason-ok>{{ __('core.action.save') }}</button>
                                </div>
                            </dialog>
                        @endif
                    </section>
                @endif
            </form>

            @if ($saved)
                {{-- ── এতে যে রোল আছে (যৌথ রোল) ── --}}
                @if ($isComposite)
                    <section class="role-panel" data-role-panel="includes" id="role-panel-includes" role="tabpanel"
                             aria-labelledby="role-tab-includes" @if ($startTab !== 'includes') hidden @endif>
                        <form method="POST" action="{{ route('system_admin.role.includes', $role) }}" data-role-includes-form>
                            @csrf
                            @method('PUT')
                            @include('system_admin::role.partials.includes', ['choices' => $allRoles, 'picked' => $includes, 'self' => $role])
                            <div class="role-panel-actions">
                                <label class="role-reason-inline">
                                    <span>{{ __('system_admin::permission.reason_label') }}</span>
                                    <input type="text" name="reason" maxlength="500">
                                </label>
                                <button type="submit" class="role-btn is-primary">{{ __('core.action.save') }}</button>
                            </div>
                        </form>
                    </section>
                @endif

                {{-- ── অনুমোদনের ক্ষমতা — ApprovalFlowStep থেকে, মাপা ([[RoleController::approvalPower()]]) ── --}}
                @if ($approvalPower !== [])
                    <section class="role-panel" data-role-panel="approval" id="role-panel-approval" role="tabpanel"
                             aria-labelledby="role-tab-approval" @if ($startTab !== 'approval') hidden @endif>
                        <h3 class="sr-only">{{ __('system_admin::permission.approval_power') }}</h3>
                        <table class="role-list-table">
                            <thead>
                                <tr>
                                    <th>{{ __('system_admin::permission.approval_paper') }}</th>
                                    <th>{{ __('system_admin::permission.column_module') }}</th>
                                    <th>{{ __('system_admin::permission.approval_step') }}</th>
                                    <th class="text-end">{{ __('system_admin::permission.approval_from_amount') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($approvalPower as $step)
                                    <tr>
                                        <td>{{ $step['label'] }}</td>
                                        <td>{{ $step['module'] }}</td>
                                        <td>{{ __('system_admin::permission.approval_level', ['level' => $step['level']]) }}</td>
                                        {{-- ⓘ সীমা না থাকা মানে "সব কাগজেই", শূন্য নয় --}}
                                        <td class="num text-end">
                                            @if ($step['threshold'] === null)
                                                {{ __('system_admin::permission.approval_always') }}
                                            @else
                                                ৳{{ \App\Core\Support\Money::format($step['threshold']) }}+
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </section>
                @endif

                {{-- ── সদস্য — সময়-বাঁধা সদস্যপদসহ (দফা ১০) ── --}}
                <section class="role-panel" data-role-panel="members" id="role-panel-members" role="tabpanel"
                         aria-labelledby="role-tab-members" @if ($startTab !== 'members') hidden @endif>
                    @include('system_admin::role.partials.members', ['rows' => $memberRows, 'role' => $role])
                </section>

                {{-- ── কে আসলে কী পারে (দফা ৮) ── --}}
                <section class="role-panel" data-role-panel="effective" id="role-panel-effective" role="tabpanel"
                         aria-labelledby="role-tab-effective" @if ($startTab !== 'effective') hidden @endif>
                    <form method="GET" action="{{ route('system_admin.role.edit', $role) }}" class="role-tools" data-role-effective-form>
                        <input type="hidden" name="tab" value="effective">
                        <label class="role-pick-user">
                            <span>{{ __('system_admin::permission.effective_pick') }}</span>
                            <select name="as" data-action="submit-form">
                                <option value="">—</option>
                                @foreach ($companyUsers as $u)
                                    <option value="{{ $u->id }}" @selected($effectiveUser?->id === $u->id)>{{ $u->name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <span class="role-note">{{ __('system_admin::permission.effective_access_note') }}</span>
                    </form>
                    @if ($effectiveUser)
                        @include('system_admin::role.partials.matrix', [
                            'grid' => $grid, 'chosen' => $effectiveHeld, 'columns' => $columns, 'readonly' => true, 'prefix' => 'eff',
                        ])
                    @endif
                </section>

                {{-- ── বদলের ইতিহাস — কারণসহ (দফা ৭) ── --}}
                <section class="role-panel" data-role-panel="history" id="role-panel-history" role="tabpanel"
                         aria-labelledby="role-tab-history" @if ($startTab !== 'history') hidden @endif>
                    @include('system_admin::role.partials.history', ['trails' => $history])
                </section>
            @endif
        </section>
        @endif
    </div>
</x-layouts.app>
