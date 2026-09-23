{{--
    নতুন নোট — মানচিত্র §৭।

    ── ⚠️ ফর্মটা ইচ্ছাকৃতভাবে ছোট ───────────────────────────────────
    সারি ধরে নয়, একটাই অঙ্ক। ⓘ কারণ নোট একটা **সংশোধন**, তালিকা নয়:
    "বিলটার দাম ১২০০ বেশি বসেছিল" — এক বাক্য, এক সংখ্যা। ⛔ সারি ধরে
    করলে মানুষ মূল বিলটা আবার টাইপ করতে বসতেন, আর ভুল সেখানেই বাড়ত।
--}}
@php
    use App\Modules\Accounts\Models\Note;

    $isCredit = $direction === Note::CREDIT;
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $isCredit ? __('accounts::note.new_credit') : __('accounts::note.new_debit') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$isCredit ? __('accounts::note.new_credit') : __('accounts::note.new_debit')"
                          :subtitle="$isCredit ? __('accounts::note.credit_hint') : __('accounts::note.debit_hint')" />
    </x-slot:header>

    @if ($errors->any())
        <div role="alert" class="mb-3 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2
                                 text-sm text-(--color-badge-danger-ink)">
            <ul class="list-inside list-disc">
                @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    {{-- ⚠️ সীমানাটা ফর্মেই লেখা — মানুষ নোট আর ফেরত নিয়মিত গুলিয়ে ফেলেন --}}
    <p class="mb-4 max-w-screen-2xl rounded-(--radius-field) bg-(--color-surface-sunken) px-3 py-2
              text-sm text-(--color-ink-muted)">
        {{ __('accounts::note.no_goods_move') }}
    </p>

    <form method="POST" action="{{ route('accounts.note.store') }}"
          class="max-w-screen-2xl rounded-(--radius-card) border border-(--color-border)
                 bg-(--color-surface-card) p-4">
        @csrf

        <input type="hidden" name="direction" value="{{ $direction }}">

        <div class="grid gap-4 sm:grid-cols-2">
            <label class="block">
                <span class="mb-1 block text-2xs text-(--color-ink-muted)">{{ __('accounts::note.party') }}</span>
                <select name="party_id" required
                        class="h-(--spacing-field) w-full rounded-(--radius-field) border border-(--color-border)
                               bg-(--color-surface-card) px-2 text-sm">
                    <option value="">—</option>
                    @foreach ($parties as $party)
                        <option value="{{ $party['id'] }}" @selected(old('party_id') == $party['id'])>
                            {{ $party['label'] }}
                        </option>
                    @endforeach
                </select>
            </label>

            <x-ui.field name="trx_date" type="date"
                        :label="__('accounts::field.date')"
                        :value="old('trx_date', now()->toDateString())" required />

            <x-ui.field name="amount" type="number" step="0.01"
                        :label="__('accounts::note.amount')"
                        :value="old('amount')" required />

            <x-ui.field name="tax_amount" type="number" step="0.01"
                        :label="__('accounts::note.tax_amount')"
                        :value="old('tax_amount')" />

            <label class="block">
                <span class="mb-1 block text-2xs text-(--color-ink-muted)">{{ __('accounts::note.reason') }}</span>
                <select name="reason" required
                        class="h-(--spacing-field) w-full rounded-(--radius-field) border border-(--color-border)
                               bg-(--color-surface-card) px-2 text-sm">
                    @foreach ($reasons as $reason)
                        <option value="{{ $reason }}" @selected(old('reason') === $reason)>
                            {{ __('accounts::note.reason_'.$reason) }}
                        </option>
                    @endforeach
                </select>
            </label>

            {{-- ⓘ মূল কাগজের নম্বরটা ঐচ্ছিক, কিন্তু প্রায় সবসময় থাকে — আর
                 ওটাই পরে "কোন বিলের সংশোধন" প্রশ্নের একমাত্র উত্তর --}}
            <x-ui.field name="against_no"
                        :label="__('accounts::note.against_no')"
                        :value="old('against_no')" />
        </div>

        <div class="mt-4">
            <x-ui.field name="narration"
                        :label="__('accounts::field.narration')"
                        :value="old('narration')" />
        </div>

        <div class="mt-4">
            <x-ui.button type="submit" tone="primary">{{ __('core.action.save') }}</x-ui.button>
        </div>
    </form>
</x-layouts.app>
