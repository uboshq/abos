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
    {{-- ⛔ এখানে আগে কেবল খাত বাছার ঘরটা ছিল।

         ব্যাংক বাছলে সার্ভার লেনদেন নম্বর চাইত
         ([[VoucherService::assertBankReferenceIsFree()]]), অথচ সেটা
         লেখার কোনো ঘর পর্দায় ছিল না — টাকাটা ঢোকানোই যেত না। এখন
         ঘর দুইটা একসাথে আসে, আর নম্বরটা চাওয়া হয় কেবল যখন লাগে। --}}
    <form method="POST" action="{{ route('finance.capital.post', $entry) }}"
          class="flex items-start gap-1">
        @csrf

        <div class="min-w-0 flex-1">
            <x-ui.money-account name="received_into_account_id" :accounts="$accounts" compact required />
        </div>

        <x-ui.button type="submit" tone="primary">{{ __('finance::action.money_arrived') }}</x-ui.button>
    </form>
@endif
