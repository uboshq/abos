{{-- খসড়া হলে "টাকা এসেছে" বোতাম, পোস্ট হলে কোথায় এসেছে সেটা।

     ── কেন খাতটা এখানে জিজ্ঞেস করা হয়, লেখার সময় নয় ──────────────────
     লেখার দিন জানা ছিল না টাকাটা সিন্দুকে আসবে না ব্যাংকে — কথাটা হয়
     একদিন, টাকা আসে আরেকদিন। ধরে নিলে ব্যাংকে আসা টাকা সিন্দুকে
     দেখাত, আর মাস শেষে ক্যাশ বই মিলত না। --}}
@if ($entry->status === \App\Modules\Finance\Models\CapitalEntry::POSTED)
    <span class="inline-flex items-center gap-1">
        <span class="rounded-(--radius-field) bg-(--color-badge-success-bg) px-2 py-0.5 text-2xs
                     text-(--color-badge-success-ink)">
            {{ __('finance::state.posted') }}
        </span>

        <span class="text-2xs text-(--color-ink-muted)">{{ $entry->account?->name() }}</span>
    </span>
@else
    <form method="POST" action="{{ route('finance.capital.post', $entry) }}"
          class="flex items-center gap-1">
        @csrf

        <select name="received_into_account_id" required
                class="h-(--spacing-field-compact) min-w-0 flex-1 rounded-(--radius-field)
                       border border-(--color-border) bg-(--color-surface-card) px-2 text-2xs">
            <option value="" disabled selected>{{ __('finance::field.landed_in') }}</option>
            @foreach ($accounts as $account)
                <option value="{{ $account->id }}">{{ $account->name() }}</option>
            @endforeach
        </select>

        <x-ui.button type="submit" tone="primary">{{ __('finance::action.money_arrived') }}</x-ui.button>
    </form>
@endif
