{{--
    নতুন মিলকরণ — নিজের পাতায়।

    ⓘ এটা তিন ধাপের প্রথমটা: এখানে অধিবেশনটা **খোলা** হয় (কোন হিসাব,
    কোন তারিখের বিবরণী, আর বিবরণীর শেষ জেরটা কত), তারপর সেভ করামাত্র
    কাজের পর্দায় (`show`) পৌঁছে লাইন ধরে টিক দেওয়া শুরু হয়।

    তাই এই ফর্মে টিকের কিছু নেই, আর থাকার কথাও নয় — কোন লাইনগুলো
    বিবরণীতে আছে সেটা জানার আগে বিবরণীটাই খোলা লাগে।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('accounts::recon.open_title') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('accounts::recon.open_title')"
                          :subtitle="__('accounts::recon.subtitle')" />
    </x-slot:header>

    @if ($errors->any())
        <div role="alert"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm
                    text-(--color-badge-danger-ink)">
            <ul class="list-inside list-disc">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('accounts.reconciliation.store') }}"
          class="grid gap-3 rounded-(--radius-card) border border-(--color-border)
                 bg-(--color-surface-card) p-4 md:grid-cols-2 lg:grid-cols-4">
        @csrf

        <label class="flex flex-col gap-1">
            <span class="text-sm font-medium">{{ __('accounts::recon.bank_account') }}</span>
            <select name="bank_account_id" required
                    class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border)
                           bg-(--color-surface-app) px-2">
                @foreach ($banks as $bank)
                    <option value="{{ $bank->id }}">{{ $bank->label() }}</option>
                @endforeach
            </select>
        </label>

        <label class="flex flex-col gap-1">
            <span class="text-sm font-medium">{{ __('accounts::recon.statement_date') }}</span>
            <x-ui.date name="statement_date" :required="true"
                       :value="old('statement_date', now()->toDateString())" />
        </label>

        <label class="flex flex-col gap-1">
            <span class="text-sm font-medium">{{ __('accounts::recon.statement_balance') }}</span>
            <input type="number" step="0.01" name="statement_balance" required
                   value="{{ old('statement_balance') }}"
                   class="num h-(--spacing-field) rounded-(--radius-field) border border-(--color-border)
                          bg-(--color-surface-app) px-2 text-end">
        </label>

        <label class="flex flex-col gap-1">
            <span class="text-sm font-medium">{{ __('accounts::recon.narration') }}</span>
            <input type="text" name="narration" value="{{ old('narration') }}"
                   class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border)
                          bg-(--color-surface-app) px-2">
        </label>

        <div class="flex flex-wrap items-end gap-2 md:col-span-2 lg:col-span-4">
            <x-ui.button type="submit" tone="primary">
                {{ __('accounts::recon.open_action') }}
            </x-ui.button>

            <x-ui.button tone="secondary" :href="route('accounts.reconciliation.index')">
                {{ __('core.action.cancel') }}
            </x-ui.button>
        </div>
    </form>
</x-layouts.app>
