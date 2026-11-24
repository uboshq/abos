{{-- বীমার তালিকার দুই জিনিস — ABOS রূপে মাথার নিচে, বাকি রূপে আগের মতো তালিকার উপরে ([[AbosHead]]) --}}
{{-- ⭐ মাস শেষের অগ্রিম বীমা — দেওয়া প্রিমিয়ামের না-আসা মেয়াদ খরচ থেকে অগ্রিমে (অর্থ-মডিউলের পরিকল্পনা ৬.৩) --}}
@can('finance.insurance.manage')
    <form method="POST" action="{{ route('finance.insurance.prepay') }}" data-insurance-prepaid
          class="mb-3 flex flex-wrap items-end gap-2 rounded-(--radius-card) border border-(--color-border)
                 bg-(--color-surface-card) px-3 py-2">
        @csrf
        <label class="block">
            <span class="block text-2xs text-(--color-ink-muted)">{{ __('finance::insurance.prepaid_month') }}</span>
            <x-ui.month name="month" required
                        :value="old('month', now()->subMonthNoOverflow()->format('Y-m'))"
                        :max="now()->subMonthNoOverflow()->format('Y-m')"
                        class="min-h-(--spacing-touch) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm" />
        </label>
        <x-ui.button type="submit" tone="secondary">{{ __('finance::insurance.prepaid_run') }}</x-ui.button>
        @unless ($abosHead)<span class="text-2xs text-(--color-ink-muted)">{{ __('finance::insurance.prepaid_note') }}</span>@endunless
        @error('month')
            <span class="text-2xs text-(--color-badge-danger-ink)" role="alert">{{ $message }}</span>
        @enderror
    </form>
@endcan

@if (($counts['due'] ?? 0) > 0 && $tab !== 'due')
    <a href="{{ route('finance.insurance.index', ['tab' => 'due']) }}" role="alert"
       class="mb-3 block rounded-(--radius-field) bg-(--color-badge-pending-bg) px-3 py-2 text-sm
              text-(--color-badge-pending-ink) underline-offset-2 hover:underline">
        {{ __('finance::insurance.due_banner', ['count' => $counts['due']]) }}
    </a>
@endif
