{{--
    সিদ্ধান্তের ঘর — গ্রহণ বা প্রত্যাখ্যান, সারি থেকেই।

    দুইটা আলাদা ফর্ম, কারণ দুইটা আলাদা রুট আর আলাদা ঘর লাগে। সারির
    ভেতরে ফর্ম বসানো যায় (`<td>`-র ভেতরে `<form>` বৈধ), আর সেটাই
    দরকার: দিনে বিশটা দাবিতে যাওয়া-আসা করলে কেউ তালিকাটা খুলত না।
--}}
{{-- ⭐ স্লিপ দেখে তবেই সিদ্ধান্ত — ১ অক্টোবর ২০২৬ ([[DepositSlip]]) --}}
@if (app(\App\Modules\Sales\Services\DepositSlip::class)->of($claim) !== null)
    <a href="{{ route('sales.claim.slip', $claim) }}" target="_blank" data-slip-link
       class="mb-1 inline-block text-sm text-(--color-brand-500) hover:underline">{{ __('sales::slip.view') }}</a>
@endif
{{-- ⭐ ব্যাংকের কথা — মিলেছে, না এখনো পাওয়া যায়নি; খাতায় উঠলে মানি রিসিট (জমার খবর ধাপ গ, ১৮ নভেম্বর ২০২৬) --}}
<span class="mb-1 flex flex-wrap items-center gap-2 text-2xs">
    @include('sales::claim.partials.stage', ['claim' => $claim])
    @if ($claim->isOpen() && $claim->statement_line_id === null)
        @can('sales.claim.match')
            <a href="{{ route('sales.claim.match.index') }}" class="text-(--color-brand-500) hover:underline" data-go-match>{{ __('sales::deposit.go_match') }}</a>
        @endcan
    @endif
    {{-- ⓘ নতুন খবরে রসিদ ভাউচার, ২০ নভেম্বর ২০২৬-এর আগের খবরে আদায় — দুটোরই মানি রিসিট --}}
    @if ($claim->isAccepted() && ($claim->voucher_id !== null || $claim->collection_id !== null))
        <a href="{{ route('sales.claim.receipt', $claim) }}" target="_blank" class="text-(--color-brand-500) hover:underline" data-deposit-receipt>{{ __('sales::deposit.receipt_title') }}</a>
    @endif
</span>
{{-- ⭐ খোলা মানে পাঠানো বা যাচাই চলছে — দুটো থেকেই সিদ্ধান্ত (টাকার পরিকল্পনা ১, ৭ অক্টোবর ২০২৬) --}}
@if ($claim->isOpen() && $claim->statement_line_id !== null)
    @include('sales::claim.partials.confirm', ['claim' => $claim])
@elseif ($claim->isOpen())
    @can('sales.claim.decide')
        <div class="flex flex-wrap items-end gap-2">
            @if ($claim->isPending())
                <form method="POST" action="{{ route('sales.claim.verify', $claim) }}" data-start-verifying>
                    @csrf
                    <x-ui.button type="submit">{{ __('sales::portal.start_verifying') }}</x-ui.button>
                </form>
            @endif

            <form method="POST" action="{{ route('sales.claim.accept', $claim) }}"
                  class="flex flex-wrap items-end gap-2">
                @csrf

                {{-- গ্রাহক যা বলেছেন সেটাই ডিফল্ট, কিন্তু বদলানো যায়:
                     ব্যাংক চার্জ কেটে নিলে অঙ্কটা আলাদা হয়। --}}
                <input type="number" step="0.01" name="amount" value="{{ $claim->amount }}"
                       title="{{ __('sales::portal.received') }}"
                       class="num h-(--spacing-field-compact) w-28 rounded-(--radius-field) border
                              border-(--color-border) bg-(--color-surface-app) px-2 text-end">

                {{-- ⭐ ব্যাংক যা কেটে রেখেছে — নিজের খাতে যায়, ডিলারের পাওনা কমে "এসেছে + চার্জ" অঙ্কে (টাকার পরিকল্পনা ৫) --}}
                <input type="number" step="0.01" min="0" name="bank_charge" value="0" data-bank-charge
                       title="{{ __('sales::field.bank_charge') }}" aria-label="{{ __('sales::field.bank_charge') }}"
                       placeholder="{{ __('sales::field.bank_charge') }}"
                       class="num h-(--spacing-field-compact) w-24 rounded-(--radius-field) border
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
