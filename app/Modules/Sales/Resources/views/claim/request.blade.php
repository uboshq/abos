{{--
    স্লিপসহ জমার অনুরোধ — কর্মীর হাতে (মালিক, ১ অক্টোবর ২০২৬, [[DepositRequestController]])।

    ⓘ এটা অনুরোধ, টাকা নয়: হিসাবরক্ষক স্লিপ মিলিয়ে গ্রহণ করলে তবেই আদায় — উপরের বাক্যটা সেটাই বলে,
    নাহলে SR ধরে নিতেন পাঠালেই বকেয়া কমে গেছে।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('sales::slip.title') }}</x-slot:title>

    <form method="POST" action="{{ route('sales.claim.request.store') }}" enctype="multipart/form-data"
          x-data="{ method: @js(old('method', 'bank')) }"
          class="mx-auto grid max-w-xl gap-3 rounded-(--radius-card) border border-(--color-border)
                 bg-(--color-surface-card) p-4">
        @csrf

        {{-- ⭐ ব্যাখ্যা নামের পাশে ⓘ-এ, লাইনটা "ABOS"/"Dynamic"-এ লুকায় ([[ui/page-info]]; মালিক, ১৯ নভেম্বর ২০২৬ রাত) —
             ফর্মের ভিতরে বলে foldPageNotes() এটা ধরত না --}}
        <h1 class="text-lg font-semibold">{{ __('sales::slip.title') }}<x-ui.page-info :text="__('sales::slip.hint')" /></h1>
        <p data-page-subtitle class="text-sm text-(--color-ink-muted)">{{ __('sales::slip.hint') }}</p>

        <x-ui.errors />

        @if (session('status'))
            <div role="status" class="rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm text-(--color-badge-success-ink)">{{ session('status') }}</div>
        @endif
        {{-- ⚠️ একই চেহারার স্লিপ — সতর্কতা, আটকানো নয় ([[DepositSlip::markLookalike()]]) --}}
        @if (session('warning'))
            <div role="alert" data-slip-lookalike class="rounded-(--radius-field) bg-(--color-badge-warning-bg) px-3 py-2 text-sm text-(--color-badge-warning-ink)">{{ session('warning') }}@if (session('warning_link')) <a href="{{ session('warning_link') }}" data-slip-seen-before class="ms-1 font-semibold underline">{{ __('core.attachment.slip_seen_open') }}</a>@endif</div>
        @endif

        <x-ui.select name="customer" :label="__('sales::slip.customer')"
                     :options="$customers->mapWithKeys(fn ($c) => [$c->id => $c->nameWithPoint()])"
                     :selected="old('customer', $picked)" placeholder="-" required />

        <x-ui.field name="claimed_on" type="date" :label="__('sales::portal.claimed_on')"
                    :value="old('claimed_on', request('claimed_on', now()->toDateString()))" required />

        <x-ui.field name="amount" type="number" step="0.01" inputmode="decimal" numeric required
                    :label="__('sales::portal.amount')" :value="old('amount', request('amount'))" />

        {{--
            ⭐ কোন বিলের বিপরীতে — ঐচ্ছিক (টাকার পরিকল্পনা ২, ৭ অক্টোবর ২০২৬; [[DepositClaimService::raise()]])।
            ⓘ "বিল দেখুন" ফর্মটাই GET-এ এই পাতায় পাঠায় — বাছা গ্রাহকের খোলা বিল আসে, জাভাস্ক্রিপ্ট ছাড়া।
        --}}
        <fieldset class="block" data-claim-bills>
            <legend class="mb-1 block text-sm font-medium">{{ __('sales::slip.bills') }}</legend>
            <p class="mb-2 text-2xs text-(--color-ink-muted)">{{ __('sales::slip.bills_hint') }}</p>
            @if ($openBills === null)
                <x-ui.button type="submit" tone="secondary" formaction="{{ route('sales.claim.request.create') }}"
                             formmethod="get" formnovalidate>{{ __('sales::slip.bills_show') }}</x-ui.button>
            @else
                @forelse ($openBills as $n => $bill)
                    <div class="mb-1 flex items-center gap-2 text-sm">
                        <span class="flex-1">{{ $bill->document_no }} <span class="text-2xs text-(--color-ink-muted)">{{ \App\Core\Support\DateFormat::format($bill->trx_date) }}</span></span>
                        <span class="num text-2xs text-(--color-ink-muted)">{{ __('sales::slip.bill_due') }} {{ \App\Core\Support\Money::format($bill->ownDue()) }}</span>
                        <input type="hidden" name="bills[{{ $n }}][invoice]" value="{{ $bill->public_id }}">
                        <input type="number" step="0.01" min="0" max="{{ bcadd($bill->ownDue(), '0', 2) }}"
                               name="bills[{{ $n }}][amount]" value="{{ old('bills.'.$n.'.amount') }}"
                               aria-label="{{ __('sales::slip.bill_pay') }} {{ $bill->document_no }}"
                               class="num h-(--spacing-field) w-32 shrink-0 rounded-(--radius-field) border
                                      border-(--color-border) bg-(--color-surface-app) px-2 text-end">
                    </div>
                @empty
                    <p class="text-sm text-(--color-ink-muted)">{{ __('sales::slip.bills_none') }}</p>
                @endforelse
            @endif
        </fieldset>

        <label class="block">
            <span class="mb-1 block text-sm font-medium">{{ __('sales::portal.method') }}</span>
            <select name="method" x-model="method"
                    class="h-(--spacing-field) w-full rounded-(--radius-field) border
                           border-(--color-border) bg-(--color-surface-app) px-3">
                <option value="bank">{{ __('sales::portal.bank') }}</option>
                <option value="mfs">{{ __('sales::portal.mfs') }}</option>
                <option value="cash">{{ __('sales::portal.cash') }}</option>
            </select>
        </label>

        <label class="block" x-show="method === 'bank'">
            <span class="mb-1 block text-sm font-medium">{{ __('sales::portal.bank_account') }}</span>
            <select name="bank_account_id"
                    class="h-(--spacing-field) w-full rounded-(--radius-field) border
                           border-(--color-border) bg-(--color-surface-app) px-3">
                <option value="">—</option>
                @foreach ($banks as $bank)
                    <option value="{{ $bank->id }}" @selected((string) old('bank_account_id') === (string) $bank->id)>{{ $bank->label() }}</option>
                @endforeach
            </select>
        </label>

        <label class="block" x-show="method !== 'cash'">
            <span class="mb-1 block text-sm font-medium">{{ __('sales::portal.reference') }}</span>
            <input type="text" name="reference" value="{{ old('reference') }}" maxlength="64"
                   class="h-(--spacing-field) w-full rounded-(--radius-field) border
                          border-(--color-border) bg-(--color-surface-app) px-3">
        </label>

        <div class="block">
            <span class="mb-1 block text-sm font-medium">{{ __('sales::slip.slip') }}</span>
            {{--
                ⛔ আগে `capture="environment"` ছিল — অনেক ফোনে সরাসরি ক্যামেরা খুলত, গ্যালারি নয়। ⭐ মালিক, ১৬ নভেম্বর ২০২৬: স্লিপ
                   হোয়াটসঅ্যাপে কয়েক হাত ঘুরে আসে — তাই ক্যামেরা আর গ্যালারি দুটোই। ছবি বাছলেই স্ক্যানার: কোণা, সোজা, পরিষ্কার।
            --}}
            <x-ui.file-input name="slip" accept="image/jpeg,image/png,image/webp,application/pdf"
                             data-slip-input
                             x-on:change="$store.scanner.begin($el, 'paper')" />
        </div>

        {{-- ⭐ টাকা কে জমা দিলেন — ঐচ্ছিক (মালিক, ১৬ নভেম্বর ২০২৬: স্লিপ কয়েক হাত ঘুরে আসে) --}}
        <x-ui.field name="depositor" :label="__('sales::slip.depositor')" :value="old('depositor')" maxlength="120"
                    :placeholder="__('sales::slip.depositor_hint')" />

        <x-ui.field name="note" :label="__('sales::portal.note')" :value="old('note')" />

        <x-ui.button type="submit" tone="primary">{{ __('sales::portal.send') }}</x-ui.button>
    </form>
</x-layouts.app>
