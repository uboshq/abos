{{-- খসড়া হলে "টাকা গেছে" বোতাম, বসে গেলে কোথা থেকে গেল সেটা।

     ── কেন খাতটা এখানে জিজ্ঞেস করা হয়, লেখার সময় নয় ──────────────────
     লেখার দিন জানা ছিল না টাকাটা সিন্দুক থেকে যাবে না ব্যাংক থেকে।
     ধরে নিলে ব্যাংক থেকে যাওয়া টাকা সিন্দুকে দেখাত, আর মাস শেষে
     ক্যাশ বই মিলত না — মূলধনের পর্দাতেও ঠিক এই কারণেই একই ব্যবস্থা। --}}
@if ($row->isPosted())
    <span class="inline-flex items-center gap-1">
        <x-ui.badge tone="success">{{ __('finance::state.posted') }}</x-ui.badge>

        <span class="text-2xs text-(--color-ink-muted)">{{ $row->moneyAccount?->name() }}</span>

        @if ($row->voucher)
            <a href="{{ route('accounts.voucher.show', $row->voucher) }}"
               class="num text-2xs text-(--color-link) hover:underline">
                {{ $row->voucher->document_no }}
            </a>
        @endif
    </span>
@elseif (auth()->user()?->can('finance.withdrawal.post'))
    <form method="POST" action="{{ route('finance.withdrawal.post', $row) }}"
          class="flex items-center gap-1">
        @csrf

        <select name="money_account_id" required
                class="h-(--spacing-field-compact) min-w-0 flex-1 rounded-(--radius-field)
                       border border-(--color-border) bg-(--color-surface-card) px-2 text-2xs">
            <option value="" disabled selected>{{ __('finance::field.money_account') }}</option>
            @foreach ($accounts as $account)
                <option value="{{ $account->id }}">{{ $account->name() }}</option>
            @endforeach
        </select>

        <x-ui.button type="submit" tone="primary">{{ __('finance::action.money_taken') }}</x-ui.button>
    </form>
@else
    <x-ui.badge tone="draft">{{ __('core.status.draft') }}</x-ui.badge>
@endif
