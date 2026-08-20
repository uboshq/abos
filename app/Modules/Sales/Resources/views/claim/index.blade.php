{{--
    ডিপোর দিক — ডিলারদের তোলা দাবি।

    ডিফল্টে কেবল অপেক্ষমাণ, কারণ এই পাতাটার একটাই কাজ: আজ কোনগুলো
    যাচাই করা বাকি। গৃহীত ও প্রত্যাখ্যাত দাবি ইতিহাস, আর ইতিহাস রোজ
    চোখের সামনে থাকলে আজকের কাজটা হারিয়ে যায়।

    সিদ্ধান্ত সারি থেকেই — দিনে বিশটা দাবিতে যাওয়া-আসা করলে কেউ আর
    তালিকাটা খুলত না।
--}}
@php
    $tone = fn (string $status) => match ($status) {
        'accepted' => 'success',
        'rejected' => 'danger',
        default => 'pending',
    };
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('sales::portal.desk_title') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('sales::portal.desk_title')"
                          :subtitle="__('sales::portal.desk_subtitle')" />
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

    <div class="mb-4 flex gap-2">
        <a href="{{ route('sales.claim.index') }}"
           @class([
               'rounded-(--radius-field) border px-3 py-1.5 text-sm',
               'border-(--color-brand-500) text-(--color-brand-500)' => $status === 'pending',
               'border-(--color-border)' => $status !== 'pending',
           ])>
            {{ __('sales::portal.only_pending') }} ({{ $pendingCount }})
        </a>
        <a href="{{ route('sales.claim.index', ['status' => 'all']) }}"
           @class([
               'rounded-(--radius-field) border px-3 py-1.5 text-sm',
               'border-(--color-brand-500) text-(--color-brand-500)' => $status === 'all',
               'border-(--color-border)' => $status !== 'all',
           ])>
            {{ __('sales::portal.show_all') }}
        </a>
    </div>

    @if ($claims->isEmpty())
        <x-ui.empty-state :message="__('sales::portal.empty')" />
    @else
        <x-ui.table :columns="[
            ['label' => __('sales::portal.dealer')],
            ['label' => __('sales::portal.claimed_on')],
            ['label' => __('sales::portal.claimed'), 'numeric' => true],
            ['label' => __('sales::portal.reference')],
            ['label' => __('sales::portal.status')],
            ['label' => ''],
        ]">
            @foreach ($claims as $claim)
                <tr class="border-t border-(--color-border)">
                    <td class="px-3 align-middle" data-label="{{ __('sales::portal.dealer') }}">
                        {{ $claim->customer?->name_bn ?: $claim->customer?->name_en }}
                        <span class="block text-2xs text-(--color-ink-muted)">{{ $claim->customer?->code }}</span>
                    </td>
                    <td class="px-3 align-middle" data-label="{{ __('sales::portal.claimed_on') }}">
                        {{ $claim->claimed_on?->format('d M Y') }}
                    </td>
                    <td class="px-3 text-end align-middle num" data-label="{{ __('sales::portal.claimed') }}">
                        <x-ui.amount :value="$claim->amount" />
                    </td>
                    <td class="px-3 align-middle" data-label="{{ __('sales::portal.reference') }}">
                        {{ $claim->reference ?? '—' }}
                    </td>
                    <td class="px-3 align-middle" data-label="{{ __('sales::portal.status') }}">
                        <x-ui.badge :tone="$tone($claim->status)">
                            {{ __('sales::portal.'.$claim->status) }}
                        </x-ui.badge>
                    </td>
                    <td class="px-3 align-middle">
                        @if ($claim->isPending())
                            @can('sales.claim.decide')
                                <div class="flex flex-wrap items-end gap-2">
                                    <form method="POST" action="{{ route('sales.claim.accept', $claim) }}"
                                          class="flex flex-wrap items-end gap-2">
                                        @csrf

                                        {{-- ডিলার যা বলেছেন সেটাই ডিফল্ট, কিন্তু বদলানো যায়:
                                             ব্যাংক চার্জ কেটে নিলে অঙ্কটা আলাদা হয়। --}}
                                        <input type="number" step="0.01" name="amount"
                                               value="{{ $claim->amount }}"
                                               title="{{ __('sales::portal.received') }}"
                                               class="num h-(--spacing-field-compact) w-28 rounded-(--radius-field) border
                                                      border-(--color-border) bg-(--color-surface-app) px-2 text-end">

                                        <select name="account_id" required
                                                class="h-(--spacing-field-compact) rounded-(--radius-field) border
                                                       border-(--color-border) bg-(--color-surface-app) px-2">
                                            @foreach ($moneyAccounts as $account)
                                                <option value="{{ $account->id }}"
                                                        @selected($claim->bank_account_id === $account->id)>
                                                    {{ $account->label() }}
                                                </option>
                                            @endforeach
                                        </select>

                                        <x-ui.button type="submit" tone="primary">
                                            {{ __('sales::portal.accept') }}
                                        </x-ui.button>
                                    </form>

                                    <form method="POST" action="{{ route('sales.claim.reject', $claim) }}"
                                          class="flex flex-wrap items-end gap-2">
                                        @csrf
                                        <input type="text" name="decision_reason" required
                                               placeholder="{{ __('sales::portal.reason') }}"
                                               class="h-(--spacing-field-compact) w-40 rounded-(--radius-field) border
                                                      border-(--color-border) bg-(--color-surface-app) px-2">
                                        <x-ui.button type="submit">
                                            {{ __('sales::portal.reject') }}
                                        </x-ui.button>
                                    </form>
                                </div>
                            @endcan
                        @elseif ($claim->decision_reason)
                            <span class="text-2xs text-(--color-ink-muted)">{{ $claim->decision_reason }}</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-ui.table>

        {{ $claims->links() }}
    @endif
</x-layouts.app>
