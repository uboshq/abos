{{--
    ⓘ খোলা দায়ে "করা হয়েছে" — চালান বা সনদের নম্বর এখানেই চাওয়া হয়; নম্বর ছাড়া জমা নয় ([[ComplianceObligations::markDone()]])।
    করা হয়ে গেলে কে, কবে, কোন নম্বরে; বাতিল হলে কারণ।
--}}
@if ($o->isOpen())
    @can('accounts.obligation.update')
        <form method="POST" action="{{ route('accounts.obligation.done', $o->id) }}" class="flex flex-wrap items-center justify-end gap-2" data-obligation-done-form>
            @csrf
            <input type="text" name="ref_no" required maxlength="64"
                   aria-label="{{ __('accounts::obligation.ref_no') }}" placeholder="{{ __('accounts::obligation.ref_no') }}"
                   class="h-(--spacing-field) w-48 rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-2">
            <x-ui.button type="submit" tone="primary">{{ __('accounts::obligation.mark_done') }}</x-ui.button>
        </form>
    @endcan
@elseif ($o->done_at !== null)
    <span class="text-xs text-(--color-ink-muted)" data-obligation-done>
        {{ __('accounts::obligation.done_line', ['who' => $o->doneBy?->name ?? '—', 'when' => $o->done_at->format('d-m-Y'), 'ref' => $o->ref_no]) }}
    </span>
@else
    <span class="text-xs text-(--color-ink-muted)" data-obligation-cancelled>{{ __('accounts::obligation.cancelled_line', ['reason' => $o->cancel_reason ?? '—']) }}</span>
@endif
