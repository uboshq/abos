{{-- বছরের অবস্থা — রং একা কিছু বলে না, লেখাটাই বলে।

     ⭐ শেষ বন্ধ বছরটার পাশে "আবার খুলুন" — ২০ সেপ্টেম্বর ২০২৬। মালিক
     লাইভে বছর বন্ধ করে দেখলেন ফেরার কোনো পথ নেই: *"অর্থবছরগুলো বন্ধ
     korechi calur option nai keno. super admin er kache seta thakte hobe"*।

     ⓘ বোতামটা কেবল সুপার অ্যাডমিনের চোখে, আর কেবল **সবচেয়ে পরে বন্ধ হওয়া**
     বছরের পাশে — পুরনো কোনো বছর খুললে তার পরের সমাপনীগুলো ভিত্তিহীন হত।
     ⛔ মেনুতে লুকানো আর দরজায় তালা এক নয়, তাই আসল পাহারা
     [[YearEndService::reopen()]]-এ। --}}
@if ($year->is_closed)
    <x-ui.badge tone="draft">{{ __('accounts::state.year_closed') }}</x-ui.badge>
    <span class="ms-2 text-2xs text-(--color-ink-muted)">
        {{ \App\Core\Support\DateFormat::format($year->closed_at) }}
    </span>

    @if (($canReopen ?? false) && ($reopenableId ?? null) === $year->id)
        {{-- নামটা হুবহু লিখতে হয়, বন্ধ করার মতোই — ভুল করে চাপা ঠেকাতে --}}
        <form method="POST" action="{{ route('accounts.year_end.reopen', $year) }}"
              class="mt-2 flex flex-wrap items-center gap-2">
            @csrf
            <input type="text" name="confirm" required
                   placeholder="{{ $year->name }}"
                   aria-label="{{ __('accounts::field.confirm_year', ['name' => $year->name]) }}"
                   class="h-(--spacing-field) w-32 rounded-(--radius-field) border border-(--color-border)
                          bg-(--color-surface-app) px-2 text-sm">

            <x-ui.button type="submit" tone="secondary">
                {{ __('accounts::action.reopen_year') }}
            </x-ui.button>

            <span class="text-2xs text-(--color-ink-muted)">{{ __('accounts::message.reopen_note') }}</span>
        </form>
    @endif
@elseif ($year->is_current)
    <x-ui.badge tone="success">{{ __('accounts::state.year_current') }}</x-ui.badge>
@else
    <x-ui.badge tone="info">{{ __('accounts::state.year_open') }}</x-ui.badge>
@endif
