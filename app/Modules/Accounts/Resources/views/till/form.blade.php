{{--
    নগদ কাউন্টার তৈরি ও সম্পাদনা।

    কোডটা তৈরির পর বদলানো যায়, কিন্তু খাতের কোড তখন আর বদলায় না —
    ছকে খাতটা যে নামে জন্মেছে সেই নামেই থাকে। বদলাতে দিলে পুরনো
    রিপোর্টের কোডগুলো আর কোনো খাতের সাথে মিলত না।
--}}
@php $isNew = ! $till->exists; @endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $isNew ? __('accounts::action.new_till') : $till->name() }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header
            :title="$isNew ? __('accounts::action.new_till') : __('accounts::action.edit_till')"
            :subtitle="$isNew ? null : $till->code" />
    </x-slot:header>

    <form method="POST"
          action="{{ $isNew ? route('accounts.till.store') : route('accounts.till.update', $till) }}"
          x-data="{ busy: false }"
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
                            :value="old('code', $till->code)" required
                            :hint="$isNew ? __('accounts::message.till_code_hint') : null" />

                <x-ui.field name="name_en" :label="__('accounts::field.name_en')"
                            :value="old('name_en', $till->name_en)" required />

                <x-ui.field name="name_bn" :label="__('accounts::field.name_bn')"
                            :value="old('name_bn', $till->name_bn)" />
            </div>
        </section>

        <section class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="font-semibold">{{ __('accounts::section.custody') }}</h2>
            <p class="mt-0.5 mb-3 max-w-(--spacing-prose-max) text-sm text-(--color-ink-muted)">
                {{ __('accounts::message.holder_note') }}
            </p>

            <div class="grid gap-3 sm:grid-cols-2">
                <label class="block">
                    <span class="mb-1 block text-sm font-medium">{{ __('accounts::field.holder') }}</span>
                    <select name="holder_id"
                            class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                   border-(--color-border) bg-(--color-surface-card) px-3">
                        {{-- ফাঁকা মানে প্রতিষ্ঠানের, কারও ব্যক্তিগত নয় --}}
                        <option value="">{{ __('accounts::field.no_holder') }}</option>
                        @foreach ($holders as $holder)
                            <option value="{{ $holder->id }}"
                                    @selected(old('holder_id', $till->holder_id) == $holder->id)>
                                {{ $holder->name }}
                            </option>
                        @endforeach
                    </select>
                </label>

                <label class="block">
                    <span class="mb-1 block text-sm font-medium">{{ __('core.company.branch') }}</span>
                    <select name="branch_id"
                            class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                   border-(--color-border) bg-(--color-surface-card) px-3">
                        <option value="">—</option>
                        @foreach ($branches as $branch)
                            <option value="{{ $branch->id }}"
                                    @selected(old('branch_id', $till->branch_id) == $branch->id)>
                                {{ $branch->name() }}
                            </option>
                        @endforeach
                    </select>
                </label>

                <x-ui.field name="limit_amount" type="number" step="0.01" inputmode="decimal"
                            :label="__('accounts::field.limit')"
                            :value="old('limit_amount', $till->limit_amount ?? 0)"
                            :hint="__('accounts::message.limit_hint')" numeric />
            </div>

            <label class="mt-3 flex min-h-(--spacing-touch) items-center gap-2 text-sm">
                <input type="checkbox" name="is_primary" value="1"
                       @checked(old('is_primary', $till->is_primary)) class="size-4">
                <span>
                    {{ __('accounts::field.primary') }}
                    <span class="block text-2xs text-(--color-ink-muted)">
                        {{ __('accounts::message.primary_hint') }}
                    </span>
                </span>
            </label>
        </section>

        @if ($isNew)
            <section class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                <h2 class="font-semibold">{{ __('accounts::section.opening') }}</h2>
                <p class="mt-0.5 mb-3 max-w-(--spacing-prose-max) text-sm text-(--color-ink-muted)">
                    {{ __('accounts::message.till_opening_note') }}
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
                         :href="$isNew ? route('accounts.till.index') : route('accounts.till.show', $till)">
                {{ __('core.action.cancel') }}
            </x-ui.button>
        </div>
    </form>
</x-layouts.app>
