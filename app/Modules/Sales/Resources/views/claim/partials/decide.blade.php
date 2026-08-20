{{--
    সিদ্ধান্তের ঘর — গ্রহণ বা প্রত্যাখ্যান, সারি থেকেই।

    দুইটা আলাদা ফর্ম, কারণ দুইটা আলাদা রুট আর আলাদা ঘর লাগে। সারির
    ভেতরে ফর্ম বসানো যায় (`<td>`-র ভেতরে `<form>` বৈধ), আর সেটাই
    দরকার: দিনে বিশটা দাবিতে যাওয়া-আসা করলে কেউ তালিকাটা খুলত না।
--}}
@if ($claim->isPending())
    @can('sales.claim.decide')
        <div class="flex flex-wrap items-end gap-2">
            <form method="POST" action="{{ route('sales.claim.accept', $claim) }}"
                  class="flex flex-wrap items-end gap-2">
                @csrf

                {{-- ডিলার যা বলেছেন সেটাই ডিফল্ট, কিন্তু বদলানো যায়:
                     ব্যাংক চার্জ কেটে নিলে অঙ্কটা আলাদা হয়। --}}
                <input type="number" step="0.01" name="amount" value="{{ $claim->amount }}"
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

                <x-ui.button type="submit" tone="primary">{{ __('sales::portal.accept') }}</x-ui.button>
            </form>

            <form method="POST" action="{{ route('sales.claim.reject', $claim) }}"
                  class="flex flex-wrap items-end gap-2">
                @csrf
                <input type="text" name="decision_reason" required
                       placeholder="{{ __('sales::portal.reason') }}"
                       class="h-(--spacing-field-compact) w-40 rounded-(--radius-field) border
                              border-(--color-border) bg-(--color-surface-app) px-2">
                <x-ui.button type="submit">{{ __('sales::portal.reject') }}</x-ui.button>
            </form>
        </div>
    @endcan
@elseif ($claim->decision_reason)
    <span class="text-2xs text-(--color-ink-muted)">{{ $claim->decision_reason }}</span>
@endif
