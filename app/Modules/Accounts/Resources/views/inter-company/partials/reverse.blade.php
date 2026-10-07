{{-- ⭐ দুই পাশ একসাথে উল্টানো — কারণসহ (গ১২, ৪ অক্টোবর ২০২৬; [[InterCompanyService::reverse()]]) --}}
@if (in_array($transfer->status, [\App\Core\Support\DocumentStatus::CONFIRMED, \App\Core\Support\DocumentStatus::DRAFT], true))
    <form method="POST" action="{{ route('accounts.inter_company.reverse', $transfer) }}" class="flex items-center gap-1">
        @csrf
        <input type="text" name="cancel_reason" required minlength="3" maxlength="500"
               aria-label="{{ __('accounts::field.inter_company_reverse_reason') }}"
               placeholder="{{ __('accounts::field.inter_company_reverse_reason') }}"
               class="h-(--spacing-field-dense) w-28 rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-2 text-xs">
        <button type="submit"
                class="rounded-(--radius-field) bg-(--color-danger) px-2 py-1 text-xs font-semibold text-white hover:bg-(--color-danger-hover)">
            {{ __('accounts::field.inter_company_reverse') }}
        </button>
    </form>
@else
    <span class="text-2xs text-(--color-ink-muted)">{{ __('accounts::field.inter_company_reversed') }}</span>
@endif
