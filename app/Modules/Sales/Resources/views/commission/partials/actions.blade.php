@if ($claim->isPending())
    @can('sales.commission.manage')
        <div class="flex flex-wrap items-center justify-end gap-2">
            <form method="POST" action="{{ route('sales.commission.settle', $claim) }}">
                @csrf
                <x-ui.button type="submit" tone="primary">
                    {{ __('sales::action.commission_settle') }}
                </x-ui.button>
            </form>

            {{--
                না মানার কারণটা এখানেই চাওয়া হয়।

                একটা পাওনা খরচ হয়ে যাওয়া মানে টাকাটা আর আসবে না; ছয় মাস
                পরে "কেন" প্রশ্নের উত্তর কেবল এই ঘরেই থাকে।
            --}}
            <form method="POST" action="{{ route('sales.commission.reject', $claim) }}"
                  class="flex items-center gap-2">
                @csrf
                <input type="text" name="decision_reason" required minlength="3"
                       placeholder="{{ __('sales::field.commission_reject_reason') }}"
                       class="h-(--spacing-field) w-48 rounded-(--radius-field) border
                              border-(--color-border) bg-(--color-surface-app) px-2">
                <x-ui.button type="submit" tone="secondary">
                    {{ __('sales::action.commission_reject') }}
                </x-ui.button>
            </form>
        </div>
    @endcan
@else
    <span class="text-2xs text-(--color-ink-muted)">{{ $claim->decided_on?->format('d M Y') }}</span>
@endif
