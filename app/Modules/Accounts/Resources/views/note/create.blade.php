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

    {{-- ── ধাপ ১ · পক্ষের ধরন — মালিক, ৩ অক্টোবর ২০২৬: *"সব পক্ষেই ডেবিট ক্রেডিট হয়, দুই পক্ষেরই লাগে"* ──
         ⓘ সাধারণ লিংক — ধরন বদলালে কেবল ঐ ধরনের পক্ষ ([[NoteAccounts::partyOptions()]]); নতুন JS নেই। --}}
    <nav class="mb-4 flex flex-wrap gap-2" aria-label="{{ __('accounts::note.kind') }}" data-note-kinds>
        @foreach (array_keys(Note::KINDS) as $option)
            @php($on = $kind === $option)
            <a href="{{ route('accounts.note.create', ['direction' => $direction, 'kind' => $option]) }}"
               @if ($on) aria-current="page" @endif
               class="rounded-(--radius-field) border px-3 py-1.5 text-sm
                      {{ $on ? 'border-(--color-brand-600) bg-(--color-brand-600) font-semibold text-white'
                             : 'border-(--color-border) bg-(--color-surface-card) text-(--color-ink) hover:bg-(--color-surface-hover)' }}">
                {{ __('accounts::note.kind_'.$option) }}
            </a>
        @endforeach
    </nav>

    @if ($party === null)
        {{-- ── ধাপ ২ · পক্ষ — কেবল বাছা ধরনের; খোঁজা যায় (নাম, কোড, মোবাইল, পয়েন্ট — [[x-ui.party-search]]) ── --}}
        <form method="GET" action="{{ route('accounts.note.create') }}" data-note-pick-party
              class="max-w-screen-2xl rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <input type="hidden" name="direction" value="{{ $direction }}">
            <input type="hidden" name="kind" value="{{ $kind }}">

            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.party-search name="party_id"
                                   :label="__('accounts::note.party')"
                                   :options="$parties"
                                   :selected="old('party_id')"
                                   required />
            </div>

            <div class="mt-4">
                <x-ui.button type="submit" tone="primary">{{ __('accounts::note.next_step') }}</x-ui.button>
            </div>
        </form>
    @else
        {{-- ── ধাপ ৩ · খাত আর অঙ্ক — পক্ষের চলতি খাত আর অন্য পাশ, আগে থেকে বাছা ([[NoteAccounts]]) ── --}}
        <form method="POST" action="{{ route('accounts.note.store') }}"
              class="max-w-screen-2xl rounded-(--radius-card) border border-(--color-border)
                     bg-(--color-surface-card) p-4">
            @csrf

            <input type="hidden" name="direction" value="{{ $direction }}">
            <input type="hidden" name="party_kind" value="{{ $kind }}">
            <input type="hidden" name="party_id" value="{{ $party['id'] }}">

            <div class="mb-4 flex flex-wrap items-baseline gap-3 text-sm" data-note-party>
                <span class="text-2xs text-(--color-ink-muted)">{{ __('accounts::note.party') }}</span>
                <span class="font-semibold">{{ $party['label'] }}</span>
                <a href="{{ route('accounts.note.create', ['direction' => $direction, 'kind' => $kind]) }}"
                   class="text-(--color-brand-600) underline-offset-2 hover:underline">{{ __('accounts::note.change_party') }}</a>
            </div>

            @if ($controls->isEmpty())
                <p role="alert" class="mb-4 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm text-(--color-badge-danger-ink)">
                    {{ __('accounts::note.control_none') }}
                </p>
            @endif

            <div class="grid gap-4 sm:grid-cols-2">
                <label class="block">
                    <span class="mb-1 block text-2xs text-(--color-ink-muted)">{{ __('accounts::note.control_account') }}</span>
                    <select name="control_account_id" required data-note-control
                            class="h-(--spacing-field) w-full rounded-(--radius-field) border border-(--color-border)
                                   bg-(--color-surface-card) px-2 text-sm">
                        @if ($controls->count() > 1)
                            <option value="">{{ __('accounts::note.choose_account') }}</option>
                        @endif
                        @foreach ($controls as $account)
                            <option value="{{ $account->id }}" @selected((int) old('control_account_id', $defaultControl) === (int) $account->id)>
                                {{ $account->code }} — {{ $account->name() }}
                            </option>
                        @endforeach
                    </select>
                </label>

                <label class="block">
                    <span class="mb-1 block text-2xs text-(--color-ink-muted)">{{ __('accounts::note.other_account') }}</span>
                    <select name="other_account_id" required data-note-other
                            class="h-(--spacing-field) w-full rounded-(--radius-field) border border-(--color-border)
                                   bg-(--color-surface-card) px-2 text-sm">
                        @if ($others->count() > 1)
                            <option value="">{{ __('accounts::note.choose_account') }}</option>
                        @endif
                        @foreach ($others as $account)
                            <option value="{{ $account->id }}" @selected((int) old('other_account_id', $defaultOther) === (int) $account->id)>
                                {{ $account->code }} — {{ $account->name() }}
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

                {{-- ⓘ ভ্যাট কেবল কেনা-বেচার পক্ষে — সেবাদাতা আর ব্যক্তির সমন্বয়ে ভ্যাটের খাত নেই --}}
                @if (in_array($kind, [Note::KIND_CUSTOMER, Note::KIND_SUPPLIER], true))
                    <x-ui.field name="tax_amount" type="number" step="0.01"
                                :label="__('accounts::note.tax_amount')"
                                :value="old('tax_amount')" />
                @endif

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
    @endif
</x-layouts.app>
