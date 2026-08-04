{{--
    খাত তৈরি ও সম্পাদনা — একটাই ফর্ম, দুই কাজে (One Form Standard, ১৫.২৪)।

    ধরনের ঘরটা বাবা বাছার সাথে সাথে নিষ্ক্রিয় হয়ে যায়: বাবা থাকলে ধরন
    বাবার থেকেই আসে, আর দুই জায়গায় দুই রকম বাছতে দিলে ব্যবহারকারী ভাবত
    তার বাছাটা টিকবে। সার্ভারেও একই নিয়ম — এটা শুধু চোখে দেখানো, যাচাই নয়।
--}}
@php
    $isNew = ! $account->exists;
    $locked = $account->is_system;
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $isNew ? __('accounts::action.new_account') : $account->name() }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header
            :title="$isNew ? __('accounts::action.new_account') : __('accounts::action.edit_account')"
            :subtitle="$isNew ? null : $account->label()" />
    </x-slot:header>

    @if ($locked)
        <div role="status"
             class="mb-4 max-w-3xl rounded-(--radius-field) bg-(--color-badge-warning-bg) px-3 py-2 text-sm
                    text-(--color-badge-warning-ink)">
            {{ __('accounts::validation.system_account_locked', ['name' => $account->name()]) }}
        </div>
    @endif

    <form method="POST"
          action="{{ $isNew ? route('accounts.coa.store') : route('accounts.coa.update', $account) }}"
          x-data="{
              busy: false,
              parent: '{{ old('parent_id', $preselectedParent) }}',
              isGroup: {{ old('is_group', $account->is_group) ? 'true' : 'false' }},
          }"
          @submit="busy ? $event.preventDefault() : (busy = true)"
          class="max-w-3xl space-y-4">
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

        <section class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="mb-3 font-semibold">{{ __('accounts::section.identity') }}</h2>

            <div class="grid gap-3 sm:grid-cols-2">
                <x-ui.field name="code" :label="__('accounts::field.code')"
                                   :value="old('code', $account->code)" required
                                   :readonly="$locked" numeric />

                <x-ui.field name="name_en" :label="__('accounts::field.name_en')"
                                   :value="old('name_en', $account->name_en)" required />

                <x-ui.field name="name_bn" :label="__('accounts::field.name_bn')"
                                   :value="old('name_bn', $account->name_bn)" />
            </div>
        </section>

        <section class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="font-semibold">{{ __('accounts::section.placement') }}</h2>
            <p class="mt-0.5 mb-3 max-w-(--spacing-prose-max) text-sm text-(--color-ink-muted)">
                {{ __('accounts::message.parent_sets_type') }}
            </p>

            <div class="grid gap-3 sm:grid-cols-2">
                <label class="block">
                    <span class="mb-1 block text-sm font-medium">{{ __('accounts::field.parent') }}</span>
                    <select name="parent_id" x-model="parent" @if ($locked) disabled @endif
                            class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                   border-(--color-border) bg-(--color-surface-card) px-3">
                        <option value="">— {{ __('accounts::field.type') }} —</option>
                        @foreach ($parents as $parent)
                            <option value="{{ $parent->id }}"
                                    @selected(old('parent_id', $preselectedParent) == $parent->id)>
                                {{ str_repeat('　', $parent->ancestors()->count()) }}{{ $parent->label() }}
                            </option>
                        @endforeach
                    </select>
                </label>

                <label class="block">
                    <span class="mb-1 block text-sm font-medium">{{ __('accounts::field.type') }}</span>
                    <select name="type"
                            :disabled="parent !== ''"
                            @if ($locked) disabled @endif
                            class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                   border-(--color-border) bg-(--color-surface-card) px-3
                                   disabled:bg-(--color-surface-app) disabled:text-(--color-ink-muted)">
                        @foreach (\App\Modules\Accounts\Models\Account::TYPES as $type)
                            <option value="{{ $type }}" @selected(old('type', $account->type) === $type)>
                                {{ __('accounts::type.' . $type) }}
                            </option>
                        @endforeach
                    </select>
                </label>

                <label class="block">
                    <span class="mb-1 block text-sm font-medium">{{ __('accounts::field.nature') }}</span>
                    <select name="nature" @if ($locked) disabled @endif
                            class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                   border-(--color-border) bg-(--color-surface-card) px-3">
                        @foreach (['debit', 'credit'] as $nature)
                            <option value="{{ $nature }}" @selected(old('nature', $account->nature) === $nature)>
                                {{ __('accounts::nature.' . $nature) }}
                            </option>
                        @endforeach
                    </select>
                </label>
            </div>

            <div class="mt-3 space-y-2">
                <label class="flex min-h-(--spacing-touch) items-center gap-2 text-sm">
                    <input type="checkbox" name="is_group" value="1" x-model="isGroup"
                           @checked(old('is_group', $account->is_group)) @if ($locked) disabled @endif
                           class="size-4">
                    <span>
                        {{ __('accounts::field.is_group') }}
                        <span class="block text-2xs text-(--color-ink-muted)">
                            {{ __('accounts::message.group_hint') }}
                        </span>
                    </span>
                </label>

                {{-- গ্রুপে টাকা বসে না, তাই গ্রুপ বাছলে এই দুটো অদৃশ্য —
                     লুকানো ঘর সেভ হয় না, আর prepareForValidation()
                     সেগুলোকে false ধরে। --}}
                <div x-show="! isGroup" x-cloak class="space-y-2">
                    <label class="flex min-h-(--spacing-touch) items-center gap-2 text-sm">
                        <input type="checkbox" name="is_cash" value="1"
                               @checked(old('is_cash', $account->is_cash)) @if ($locked) disabled @endif
                               class="size-4">
                        {{ __('accounts::field.is_cash') }}
                    </label>

                    <label class="flex min-h-(--spacing-touch) items-center gap-2 text-sm">
                        <input type="checkbox" name="is_bank" value="1"
                               @checked(old('is_bank', $account->is_bank)) @if ($locked) disabled @endif
                               class="size-4">
                        {{ __('accounts::field.is_bank') }}
                    </label>
                </div>
            </div>
        </section>

        {{-- ব্যাংকের তথ্য শুধু ব্যাংক খাতে — অন্য খাতে ঘরগুলো থাকলে
             ব্যবহারকারী ভাবত সেগুলো ভরতে হবে --}}
        <section x-show="! isGroup" x-cloak
                 class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="mb-3 font-semibold">{{ __('accounts::section.bank') }}</h2>

            <div class="grid gap-3 sm:grid-cols-3">
                <x-ui.field name="bank_name" :label="__('accounts::field.bank_name')"
                                   :value="old('bank_name', $account->bank_name)" />
                <x-ui.field name="branch_name" :label="__('accounts::field.branch_name')"
                                   :value="old('branch_name', $account->branch_name)" />
                <x-ui.field name="account_number" :label="__('accounts::field.account_number')"
                                   :value="old('account_number', $account->account_number)" numeric />
            </div>
        </section>

        @if ($isNew)
            <section class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4"
                     x-show="! isGroup" x-cloak>
                <h2 class="font-semibold">{{ __('accounts::section.opening') }}</h2>
                <p class="mt-0.5 mb-3 max-w-(--spacing-prose-max) text-sm text-(--color-ink-muted)">
                    {{ __('accounts::message.opening_note') }}
                </p>

                <div class="grid gap-3 sm:grid-cols-2">
                    <x-ui.field name="opening_balance" type="number" step="0.01" inputmode="decimal"
                                       :label="__('accounts::field.opening_balance')"
                                       :value="old('opening_balance', 0)" numeric />
                    <x-ui.field name="opening_date" type="date"
                                       :label="__('accounts::field.opening_date')"
                                       :value="old('opening_date')" />
                </div>
            </section>
        @endif

        <div class="flex flex-wrap gap-2">
            <x-ui.button type="submit" tone="primary"
                         ::class="busy && 'pointer-events-none opacity-70'">
                {{ __('core.action.save') }}
            </x-ui.button>

            <x-ui.button tone="secondary"
                         :href="$isNew ? route('accounts.coa.index') : route('accounts.coa.show', $account)">
                {{ __('core.action.cancel') }}
            </x-ui.button>
        </div>
    </form>
</x-layouts.app>
