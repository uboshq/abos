{{--
    টাকা হস্তান্তর শুরু করা।

    দুইজন মানুষের নাম বাধ্যতামূলক নয় ফর্মে, কিন্তু প্রায় সবসময়ই ভরা
    হবে — কারণ ওই দুইটা নামই স্লিপের দুইটা সই। নাম ছাড়া স্লিপ ছাপালে
    টাকা হারালে কার কাছে তা বলার উপায় থাকে না।

    গন্তব্য দুই রকম: আরেকটা কাউন্টার, অথবা ব্যাংক। দিনশেষে ব্যাংকে
    জমাটাই সবচেয়ে বেশি হয়, তাই দুইটাই একই তালিকায় — আলাদা করলে
    ব্যবহারকারীকে আগে ঠিক করতে হত কোন ধরনের হস্তান্তর, তারপর ফর্ম।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('accounts::action.new_transfer') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('accounts::action.new_transfer')"
                          :subtitle="__('accounts::message.transfer_note')" />
    </x-slot:header>

    <form method="POST" action="{{ route('accounts.transfer.store') }}"
          x-data="{ busy: false }"
          @submit="busy ? $event.preventDefault() : (busy = true)"
          class="max-w-3xl space-y-4">
        @csrf

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
            <div class="grid gap-3 sm:grid-cols-2">
                <x-ui.field name="trx_date" type="date" :label="__('accounts::field.date')"
                            :value="old('trx_date', now()->format('Y-m-d'))" required />

                <x-ui.field name="amount" type="number" step="0.01" inputmode="decimal"
                            :label="__('accounts::field.amount')"
                            :value="old('amount')" required numeric />
            </div>
        </section>

        <section class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="mb-3 font-semibold">{{ __('accounts::section.hand_over') }}</h2>

            <div class="grid gap-3 sm:grid-cols-2">
                <label class="block">
                    <span class="mb-1 block text-sm font-medium">
                        {{ __('accounts::field.moved_from') }}
                        <span class="text-(--color-danger)" aria-hidden="true">*</span>
                    </span>
                    <select name="from_till_id" required
                            class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                   border-(--color-border) bg-(--color-surface-card) px-3">
                        <option value="">—</option>
                        @foreach ($tills as $till)
                            <option value="{{ $till->id }}" @selected(old('from_till_id') == $till->id)>
                                {{ $till->code }} — {{ $till->name() }}
                                ({{ \App\Core\Support\Money::format($till->balance()) }})
                            </option>
                        @endforeach
                    </select>
                </label>

                <label class="block">
                    <span class="mb-1 block text-sm font-medium">{{ __('accounts::field.given_by') }}</span>
                    <select name="given_by"
                            class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                   border-(--color-border) bg-(--color-surface-card) px-3">
                        <option value="">—</option>
                        @foreach ($people as $person)
                            <option value="{{ $person->id }}"
                                    @selected(old('given_by', auth()->id()) == $person->id)>
                                {{ $person->name }}
                            </option>
                        @endforeach
                    </select>
                </label>
            </div>
        </section>

        <section class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="mb-3 font-semibold">{{ __('accounts::section.receive') }}</h2>

            <div class="grid gap-3 sm:grid-cols-2">
                <label class="block">
                    <span class="mb-1 block text-sm font-medium">
                        {{ __('accounts::field.moved_to') }}
                        <span class="text-(--color-danger)" aria-hidden="true">*</span>
                    </span>
                    {{-- একটাই ঘর, দুই ধরনের গন্তব্য। মান দুই রকম
                         ("till:3" বা "account:12"), আর কন্ট্রোলার সেটা
                         আলাদা করে — ব্যবহারকারীকে আগে ধরন বাছতে বলার
                         চেয়ে এটা এক ধাপ কম। --}}
                    <select name="destination" required
                            class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                   border-(--color-border) bg-(--color-surface-card) px-3">
                        <option value="">—</option>

                        <optgroup label="{{ __('accounts::menu.cash_tills') }}">
                            @foreach ($tills as $till)
                                <option value="till:{{ $till->id }}"
                                        @selected(old('destination') === 'till:' . $till->id)>
                                    {{ $till->code }} — {{ $till->name() }}
                                </option>
                            @endforeach
                        </optgroup>

                        @if ($bankAccounts->isNotEmpty())
                            <optgroup label="{{ __('accounts::field.is_bank') }}">
                                @foreach ($bankAccounts as $account)
                                    <option value="account:{{ $account->id }}"
                                            @selected(old('destination') === 'account:' . $account->id)>
                                        {{ $account->label() }}
                                    </option>
                                @endforeach
                            </optgroup>
                        @endif
                    </select>
                </label>

                <label class="block">
                    <span class="mb-1 block text-sm font-medium">{{ __('accounts::field.received_by') }}</span>
                    <select name="received_by"
                            class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                   border-(--color-border) bg-(--color-surface-card) px-3">
                        <option value="">—</option>
                        @foreach ($people as $person)
                            <option value="{{ $person->id }}" @selected(old('received_by') == $person->id)>
                                {{ $person->name }}
                            </option>
                        @endforeach
                    </select>
                </label>
            </div>

            <label class="mt-3 block">
                <span class="mb-1 block text-sm font-medium">{{ __('core.table.narration') }}</span>
                <input type="text" name="narration" value="{{ old('narration') }}"
                       class="h-(--spacing-field) w-full rounded-(--radius-field) border
                              border-(--color-border) bg-(--color-surface-card) px-3">
            </label>
        </section>

        <div class="flex flex-wrap gap-2">
            <x-ui.button type="submit" tone="primary"
                         ::class="busy && 'pointer-events-none opacity-70'">
                {{ __('accounts::action.hand_over') }}
            </x-ui.button>

            <x-ui.button tone="secondary" :href="route('accounts.transfer.index')">
                {{ __('core.action.cancel') }}
            </x-ui.button>
        </div>
    </form>
</x-layouts.app>
