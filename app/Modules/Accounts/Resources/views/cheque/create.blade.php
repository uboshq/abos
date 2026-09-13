{{--
    নতুন চেক — নিজের পাতায়।

    আগে ফর্মটা তালিকার নিচে গোঁজা ছিল, আর উপরে কোনো বোতাম ছিল না।
    এখন বিক্রয় বিলের মতোই: তালিকার উপরে "+ নতুন চেক", আর বসানোর কাজটা
    এই পাতায় — একটাই পথ, তাই একদিন একটা বদলে অন্যটা পুরনো থেকে যাওয়ার
    উপায় নেই।

    ⓘ জমা · পাশ · ফেরত — তিনটাই সারির কাজ, আর সেগুলো তালিকার পাতাতেই
    আছে। দিনে দশটা চেকে প্রতিটা সিদ্ধান্তের জন্য আলাদা পাতা খোলা অসহ্য।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('accounts::action.new_cheque') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('accounts::action.new_cheque')"
                          :subtitle="__('accounts::message.cheque_note')" />
    </x-slot:header>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    @if ($errors->any())
        <div role="alert"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm
                    text-(--color-badge-danger-ink)">
            <ul class="list-inside list-disc">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('accounts.cheque.store') }}"
          class="grid gap-3 rounded-(--radius-card) border border-(--color-border)
                 bg-(--color-surface-card) p-4 md:grid-cols-3 lg:grid-cols-6">
        @csrf

        <label class="flex flex-col gap-1">
            <span class="text-sm font-medium">{{ __('accounts::field.cheque_direction') }}</span>
            <select name="direction"
                    class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border)
                           bg-(--color-surface-app) px-2">
                <option value="received">{{ __('accounts::field.cheque_received') }}</option>
                <option value="issued">{{ __('accounts::field.cheque_issued') }}</option>
            </select>
        </label>

        <label class="flex flex-col gap-1">
            <span class="text-sm font-medium">{{ __('accounts::field.cheque_no') }}</span>
            <input type="text" name="cheque_no" required value="{{ old('cheque_no') }}"
                   class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border)
                          bg-(--color-surface-app) px-2">
        </label>

        <label class="flex flex-col gap-1">
            <span class="text-sm font-medium">{{ __('accounts::field.bank_name') }}</span>
            <input type="text" name="bank_name" value="{{ old('bank_name') }}"
                   class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border)
                          bg-(--color-surface-app) px-2">
        </label>

        {{--
            চেকের নিজের তারিখ — এন্ট্রির তারিখ নয়।

            আগাম তারিখের চেক (PDC) বাংলাদেশে রোজকার, আর ওটাই এই
            খাতার প্রাণ: "আগামী সপ্তাহে কত টাকার চেক পাশ হবে"।
        --}}
        <label class="flex flex-col gap-1">
            <span class="text-sm font-medium">{{ __('accounts::field.cheque_date') }}</span>
            <x-ui.date name="cheque_date" :required="true"
                       :value="old('cheque_date', now()->toDateString())" />
        </label>

        <label class="flex flex-col gap-1">
            <span class="text-sm font-medium">{{ __('accounts::field.amount') }}</span>
            <input type="number" step="0.01" min="0" name="amount" required value="{{ old('amount') }}"
                   class="num h-(--spacing-field) rounded-(--radius-field) border border-(--color-border)
                          bg-(--color-surface-app) px-2 text-end">
        </label>

        <label class="flex flex-col gap-1">
            <span class="text-sm font-medium">{{ __('accounts::field.party') }}</span>
            <select name="party"
                    class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border)
                           bg-(--color-surface-app) px-2">
                <option value="">—</option>
                @foreach ($parties as $group)
                    <optgroup label="{{ $group['label'] }}">
                        @foreach ($group['options'] as $party)
                            <option value="{{ $group['type'] }}:{{ $party['id'] }}">{{ $party['label'] }}</option>
                        @endforeach
                    </optgroup>
                @endforeach
            </select>
        </label>

        {{--
            আমাদের কোন ব্যাংক হিসাবে টাকাটা বসবে।

            উপরের "ব্যাংকের নাম" ঘরটা আলাদা জিনিস — ওটা চেকটা **কোন
            ব্যাংকের**, অর্থাৎ যিনি চেক দিয়েছেন তাঁর ব্যাংক। দুইটাতেই
            একই লেবেল বসানো ছিল, আর পর্দায় পাশাপাশি দুইটা "ব্যাংকের
            নাম" দেখে বোঝার উপায় ছিল না কোনটা কী।
        --}}
        <label class="flex flex-col gap-1 md:col-span-2">
            <span class="text-sm font-medium">{{ __('accounts::field.deposit_into') }}</span>
            <select name="bank_account_id"
                    class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border)
                           bg-(--color-surface-app) px-2">
                <option value="">—</option>
                @foreach ($banks as $bank)
                    <option value="{{ $bank->id }}">{{ $bank->label() }}</option>
                @endforeach
            </select>
        </label>

        <label class="flex flex-col gap-1 md:col-span-2 lg:col-span-3">
            <span class="text-sm font-medium">{{ __('core.table.narration') }}</span>
            <input type="text" name="narration" value="{{ old('narration') }}"
                   class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border)
                          bg-(--color-surface-app) px-2">
        </label>

        <div class="flex flex-wrap items-end gap-2 md:col-span-3 lg:col-span-6">
            <x-ui.button type="submit" tone="primary">
                {{ __('core.action.save') }}
            </x-ui.button>

            <x-ui.button tone="secondary" :href="route('accounts.cheque.index')">
                {{ __('core.action.cancel') }}
            </x-ui.button>
        </div>
    </form>
</x-layouts.app>
