{{--
    ব্যবহারকারী — তৈরি ও সম্পাদনা।

    ── কেন রোল ও কোম্পানি দুইটাই এক পর্দায় ─────────────────────────────
    দুইটার একটা বাদ পড়লে ব্যবহারকারী লগইন করে খালি পর্দা পান: রোল ছাড়া
    কোনো মেনু নেই, কোম্পানি ছাড়া কোনো তথ্য নেই। আলাদা দুই পর্দায় রাখলে
    দ্বিতীয়টা ভুলে যাওয়া সহজ, আর তখন নতুন কর্মী ভাবেন ব্যবস্থাটা ভাঙা।
--}}
@php
    $isNew = ! $user->exists;

    $chosenRoles = collect(old('roles', $user->roles->pluck('name')->all()))->all();
    $chosenCompanies = collect(old('companies', $user->companies->pluck('id')->all()))
        ->map(fn ($id) => (int) $id)->all();
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $isNew ? __('system_admin::action.new_user') : $user->name }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header
            :title="$isNew ? __('system_admin::action.new_user') : $user->name"
            :subtitle="__('system_admin::message.users_note')" />
    </x-slot:header>

    <form method="POST"
          action="{{ $isNew ? route('system_admin.user.store') : route('system_admin.user.update', $user) }}"
          class="space-y-4">
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
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                <x-ui.field name="name" :label="__('system_admin::field.user_name')"
                            :value="old('name', $user->name)" required />

                <x-ui.field name="email" type="email" :label="__('core.profile.email')"
                            :value="old('email', $user->email)" required />

                {{-- পাসওয়ার্ড কখনো ভরে দেখানো হয় না — পর্দার HTML-এ থাকলে
                     যে কেউ দেখে ফেলতে পারতেন। সম্পাদনায় খালি মানে
                     "আগেরটাই থাক", মুছে দেওয়া নয়। --}}
                <x-ui.field name="password" type="password"
                            :label="__('system_admin::field.password')"
                            :hint="$isNew ? __('system_admin::field.password_hint')
                                          : __('system_admin::field.password_blank_hint')"
                            :required="$isNew" />

                <x-ui.select name="locale" :label="__('core.appearance.language')"
                             :options="['bn' => 'বাংলা', 'en' => 'English']"
                             :selected="old('locale', $user->locale ?? 'bn')" required />

                <label class="flex min-h-(--spacing-touch) items-center gap-2 text-sm">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" name="is_active" value="1" class="size-4"
                           @checked(old('is_active', $user->is_active ?? true))>
                    {{ __('core.state.active') }}
                </label>
            </div>
        </section>

        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="font-semibold">{{ __('system_admin::field.roles') }}</h2>

            {{-- রোল ব্যবহারকারী ধরে, কোম্পানি ধরে নয় — আর সেটা লিখে
                 রাখা হয়েছে, কারণ পর্দা দেখে উল্টোটা ধরে নেওয়া সহজ।
                 কেউ দুই কোম্পানিতে দুই রকম অধিকার আশা করলে আজ সেটা
                 পাবেন না, আর না জানলে সেটাই একটা নিরাপত্তার ফাঁক। --}}
            <p class="mt-1 text-xs text-(--color-ink-muted)">{{ __('system_admin::message.roles_are_global') }}</p>

            <div class="mt-3 flex flex-wrap gap-x-6 gap-y-2">
                @foreach ($roles as $role)
                    <label class="flex min-h-(--spacing-touch) items-center gap-2 text-sm">
                        <input type="checkbox" name="roles[]" value="{{ $role->name }}" class="size-4"
                               @checked(in_array($role->name, $chosenRoles, true))>
                        {{ $role->name }}
                    </label>
                @endforeach
            </div>
        </section>

        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="font-semibold">{{ __('core.company.company') }}</h2>
            <p class="mt-1 text-xs text-(--color-ink-muted)">{{ __('system_admin::message.company_access_note') }}</p>

            <div class="mt-3 space-y-2">
                @foreach ($companies as $company)
                    <div class="flex flex-wrap items-center gap-3 rounded-(--radius-field)
                                border border-(--color-border) px-3 py-2 text-sm">
                        {{-- `peer` — নিচের সীমার ঘরগুলো এই টিকটার উপর নির্ভর করে --}}
                        <label class="peer flex min-h-(--spacing-touch) flex-1 items-center gap-2">
                            <input type="checkbox" name="companies[]" value="{{ $company->id }}" class="size-4"
                                   @checked(in_array($company->id, $chosenCompanies, true))>
                            <span class="font-medium">{{ $company->code }}</span>
                            <span>{{ $company->name() }}</span>
                        </label>

                        <label class="flex items-center gap-2 text-xs text-(--color-ink-muted)">
                            {{ __('core.company.branch') }}
                            <select name="default_branch[{{ $company->id }}]"
                                    class="h-(--spacing-field-compact) rounded-(--radius-field) border border-(--color-border)
                                           bg-(--color-surface-card) px-2 text-sm">
                                <option value="">-</option>
                                @foreach ($branches[$company->id] ?? [] as $branch)
                                    <option value="{{ $branch->id }}"
                                        @selected(old("default_branch.{$company->id}",
                                            $user->companies->firstWhere('id', $company->id)?->pivot?->default_branch_id)
                                            == $branch->id)>
                                        {{ $branch->code }} - {{ $branch->name() }}
                                    </option>
                                @endforeach
                            </select>
                        </label>

                        {{--
                            দেখার সীমা — কোম্পানির ভেতরের দেয়াল (ভাগ চ)।

                            ── কেন কোম্পানির ঘরটা টিক না থাকলে এটা দেখা যায় না ──
                            সীমা বসে কেবল সেই কোম্পানিগুলোতে যেগুলোতে মানুষটা
                            ঢুকতে পারেন — যে কোম্পানিতে তিনি নেই, সেখানে "কোন
                            শাখা দেখবেন" প্রশ্নটার কোনো মানে নেই।

                            আগে ঘরগুলো তবু দেখা যেত। কেউ টিক দিয়ে সংরক্ষণ
                            করতেন, সারিটা বসত না, আর ফিরে এসে টিকটা উধাও —
                            কোনো ভুলের বার্তা ছাড়াই। ধরা পড়েছে ব্রাউজারে।

                            CSS-এ, JavaScript-এ নয়: টিকটা তোলার সাথে সাথেই
                            ঘরগুলো মিলিয়ে যায়, সংরক্ষণের অপেক্ষা ছাড়া।
                        --}}
                        <div class="hidden w-full border-t border-(--color-border) pt-2
                                    peer-has-[:checked]:block">
                            <p class="text-2xs text-(--color-ink-muted)">
                                {{ __('system_admin::message.scope_note') }}
                            </p>

                            <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1">
                                @forelse ($branches[$company->id] ?? [] as $branch)
                                    <label class="flex items-center gap-2 text-xs">
                                        <input type="checkbox"
                                               name="branch_scope[{{ $company->id }}][]"
                                               value="{{ $branch->id }}" class="size-4"
                                               @checked(in_array((int) $branch->id,
                                                   collect(old("branch_scope.{$company->id}",
                                                       $scopes[$company->id] ?? []))
                                                       ->map(fn ($id) => (int) $id)->all(), true))>
                                        <span>{{ $branch->code }}</span>
                                        <span class="text-(--color-ink-muted)">{{ $branch->name() }}</span>
                                    </label>
                                @empty
                                    <span class="text-2xs text-(--color-ink-muted)">
                                        {{ __('system_admin::message.scope_no_branches') }}
                                    </span>
                                @endforelse
                            </div>
                        </div>

                        {{--
                            গুদামের সীমা — শাখার পাশে, আলাদা দেয়াল।

                            ── কেন শাখাই যথেষ্ট নয় ─────────────────────────────
                            শাখা বলে কোন অফিসের কাগজ; গুদাম বলে কোন তাকের মাল।
                            ময়মনসিংহের একজন স্টোরকিপার শাখার সব বিল দেখতে
                            পারেন, কিন্তু তাঁর কাজ একটাই গুদামে — বাকি গুদামের
                            মজুদ তিনি মেলাতেও পারবেন না।

                            ঘরগুলো কেবল তখনই, যখন কোম্পানিতে গুদাম আছে।
                        --}}
                        @if (($warehouses[$company->id] ?? collect())->isNotEmpty())
                            <div class="hidden w-full peer-has-[:checked]:block">
                                <p class="text-2xs text-(--color-ink-muted)">
                                    {{ __('system_admin::message.scope_house_note') }}
                                </p>

                                <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1">
                                    @foreach ($warehouses[$company->id] as $house)
                                        <label class="flex items-center gap-2 text-xs">
                                            <input type="checkbox"
                                                   name="warehouse_scope[{{ $company->id }}][]"
                                                   value="{{ $house->id }}" class="size-4"
                                                   @checked(in_array((int) $house->id,
                                                       collect(old("warehouse_scope.{$company->id}",
                                                           $houseScopes[$company->id] ?? []))
                                                           ->map(fn ($id) => (int) $id)->all(), true))>
                                            <span>{{ $house->code }}</span>
                                            <span class="text-(--color-ink-muted)">{{ $house->name() }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </section>

        <div class="flex flex-wrap gap-2">
            <x-ui.button type="submit" tone="primary">{{ __('core.action.save') }}</x-ui.button>
            <x-ui.button tone="secondary" :href="route('system_admin.user.index')">
                {{ __('core.action.cancel') }}
            </x-ui.button>
        </div>
    </form>
</x-layouts.app>
