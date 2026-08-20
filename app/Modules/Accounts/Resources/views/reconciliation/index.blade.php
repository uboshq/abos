{{--
    মিলকরণের তালিকা।

    মাস শেষে প্রথম প্রশ্নটা "কোন হিসাবের কোন মাস মেলানো হয়েছে, আর কোনটা
    বাকি" — তাই তালিকাটাই প্রধান পর্দা, আর নতুন মিলকরণ শুরু করার ফর্মটা
    উপরে, চেকের খাতার মতোই।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('accounts::recon.title') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('accounts::recon.title')"
                          :subtitle="__('accounts::recon.subtitle')" />
    </x-slot:header>

    @if (session('status'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('status') }}
        </div>
    @endif

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

    @can('accounts.reconciliation.manage')
        <form method="POST" action="{{ route('accounts.reconciliation.store') }}"
              class="mb-5 grid gap-3 rounded-(--radius-card) border border-(--color-border)
                     bg-(--color-surface-card) p-4 md:grid-cols-2 lg:grid-cols-5">
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

            <div class="flex items-end">
                <x-ui.button type="submit" tone="primary" class="w-full">
                    {{ __('accounts::recon.open_action') }}
                </x-ui.button>
            </div>
        </form>
    @endcan

    @if ($reconciliations->isEmpty())
        <x-ui.empty-state :message="__('accounts::recon.empty')" />
    @else
        <x-ui.table :columns="[
            ['label' => __('accounts::recon.bank_account')],
            ['label' => __('accounts::recon.statement_date')],
            ['label' => __('accounts::recon.statement_balance'), 'numeric' => true],
            ['label' => __('accounts::recon.status')],
            ['label' => __('accounts::recon.confirmed_by')],
        ]">
            @foreach ($reconciliations as $recon)
                <tr class="border-t border-(--color-border)">
                    <td class="px-3 align-middle" data-label="{{ __('accounts::recon.bank_account') }}">
                        <a href="{{ route('accounts.reconciliation.show', $recon) }}"
                           class="text-(--color-brand-500) hover:underline">
                            {{ $recon->bankAccount?->label() }}
                        </a>
                    </td>
                    <td class="px-3 align-middle" data-label="{{ __('accounts::recon.statement_date') }}">
                        {{ $recon->statement_date?->format('d M Y') }}
                    </td>
                    <td class="px-3 text-end align-middle num"
                        data-label="{{ __('accounts::recon.statement_balance') }}">
                        <x-ui.amount :value="$recon->statement_balance" />
                    </td>
                    <td class="px-3 align-middle" data-label="{{ __('accounts::recon.status') }}">
                        <x-ui.badge :tone="$recon->isConfirmed() ? 'success' : 'neutral'">
                            {{ $recon->isConfirmed() ? __('accounts::recon.confirmed') : __('accounts::recon.draft') }}
                        </x-ui.badge>
                    </td>
                    <td class="px-3 align-middle" data-label="{{ __('accounts::recon.confirmed_by') }}">
                        {{ $recon->confirmer?->name ?? '—' }}
                    </td>
                </tr>
            @endforeach
        </x-ui.table>

        {{ $reconciliations->links() }}
    @endif
</x-layouts.app>
