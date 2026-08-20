{{--
    নতুন ঋণ।

    ── কেন একটাই ফর্ম, দুইটা নয় ────────────────────────────────────────
    টার্ম লোন আর CC-র প্রশ্নগুলো প্রায় পুরোটাই এক: কে দিল, কত, কত সুদ,
    কী জামানত, কোন খাতে বসবে। আলাদা হয় শুধু শেষ তিনটা ঘর — মেয়াদ,
    সুদের পদ্ধতি আর প্রথম কিস্তির তারিখ, যা CC-তে অর্থহীন। তাই ঘরগুলো
    ধরন অনুযায়ী দেখা যায়, আর যাচাইও সার্ভারে ঠিক একইভাবে শর্তসাপেক্ষ
    (LoanController::store) — পর্দায় যে ঘর নেই, সার্ভার সেটা চায় না।

    ── ফর্মটা টাকাও ঢোকায় ──────────────────────────────────────────────
    টার্ম লোনে টাকাটা একবারেই আসে, তাই "কোথায় ঢুকল" এখানেই জিজ্ঞেস করা
    হয় — সংরক্ষণের সাথেই দায় আর টাকা দুইটাই খতিয়ানে বসে। CC-তে সেটা
    চাওয়া হয় না: সীমা মঞ্জুর হওয়া মানে টাকা আসা নয়, তোলা হলে তবেই।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('accounts::action.new_loan') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('accounts::action.new_loan')" />
    </x-slot:header>

    {{-- kind ধরে ঘরগুলো দেখানো-লুকানো। বাংলা লেখা x-data-র ভেতরে
         রাখা হয়নি ইচ্ছে করেই: একটা উদ্ধৃতি চিহ্ন পুরো অ্যাট্রিবিউটটা
         বন্ধ করে দেয় (AlpineAttributesAreWellFormedTest এই ভুলটাই
         ধরে, ক্রয়ের পর্দা একবার এভাবেই মরেছিল)। --}}
    <form method="POST" action="{{ route('accounts.loan.store') }}"
          x-data="{ kind: '{{ old('kind', \App\Modules\Accounts\Models\Loan::TERM) }}', busy: false }"
          @submit="busy ? $event.preventDefault() : (busy = true)"
          class="max-w-3xl space-y-4">
        @csrf

        @if ($errors->any())
            <div role="alert"
                 class="rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm
                        text-(--color-badge-danger-ink)">
                <ul class="list-inside list-disc">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <section class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="mb-3 font-semibold">{{ __('accounts::section.identity') }}</h2>

            <div class="grid gap-3 sm:grid-cols-2">
                <x-ui.field name="lender" :label="__('accounts::field.lender')"
                            :value="old('lender')" required />

                <x-ui.field name="account_no" :label="__('accounts::field.loan_account_no')"
                            :value="old('account_no')" />
            </div>

            <fieldset class="mt-3">
                <legend class="mb-1 block text-sm font-medium">{{ __('accounts::field.loan_kind') }}</legend>

                <div class="flex flex-wrap gap-4">
                    <label class="flex min-h-(--spacing-touch) items-center gap-2 text-sm">
                        <input type="radio" name="kind" value="{{ \App\Modules\Accounts\Models\Loan::TERM }}"
                               x-model="kind" class="size-4">
                        {{ __('accounts::field.loan_term') }}
                    </label>

                    <label class="flex min-h-(--spacing-touch) items-center gap-2 text-sm">
                        <input type="radio" name="kind" value="{{ \App\Modules\Accounts\Models\Loan::CC }}"
                               x-model="kind" class="size-4">
                        {{ __('accounts::field.loan_cc') }}
                    </label>

                    <label class="flex min-h-(--spacing-touch) items-center gap-2 text-sm">
                        <input type="radio" name="kind" value="{{ \App\Modules\Accounts\Models\Loan::HAND }}"
                               x-model="kind" class="size-4">
                        {{ __('accounts::field.loan_hand') }}
                    </label>

                    {{-- FD ও DPS টাকা রাখা, নেওয়া নয় — তাই দিকের ঘরটা
                         ওদের জন্য দেখানো হয় না, সংজ্ঞাতেই বসে আছে। --}}
                    <label class="flex min-h-(--spacing-touch) items-center gap-2 text-sm">
                        <input type="radio" name="kind" value="{{ \App\Modules\Accounts\Models\Loan::FD }}"
                               x-model="kind" class="size-4">
                        {{ __('accounts::field.loan_fd') }}
                    </label>

                    <label class="flex min-h-(--spacing-touch) items-center gap-2 text-sm">
                        <input type="radio" name="kind" value="{{ \App\Modules\Accounts\Models\Loan::DPS }}"
                               x-model="kind" class="size-4">
                        {{ __('accounts::field.loan_dps') }}
                    </label>
                </div>
            </fieldset>

            {{--
                দিক — কেবল হাতধারে।

                ব্যাংক ঋণ সবসময় নেওয়া; কেউ ব্যাংককে ধার দেয় না। তাই
                ঘরটা ওখানে দেখানোই হয় না, আর দেখালে প্রতিবার একটা
                অর্থহীন সিদ্ধান্ত চাইত।
            --}}
            <fieldset class="mt-3" x-show="kind === 'hand'" x-cloak>
                <legend class="mb-1 block text-sm font-medium">{{ __('accounts::field.loan_direction') }}</legend>

                <div class="flex flex-wrap gap-4">
                    <label class="flex min-h-(--spacing-touch) items-center gap-2 text-sm">
                        <input type="radio" name="direction" value="{{ \App\Modules\Accounts\Models\Loan::TAKEN }}"
                               @checked(old('direction', \App\Modules\Accounts\Models\Loan::TAKEN) === \App\Modules\Accounts\Models\Loan::TAKEN)
                               class="size-4">
                        {{ __('accounts::field.loan_taken') }}
                    </label>

                    <label class="flex min-h-(--spacing-touch) items-center gap-2 text-sm">
                        <input type="radio" name="direction" value="{{ \App\Modules\Accounts\Models\Loan::GIVEN }}"
                               @checked(old('direction') === \App\Modules\Accounts\Models\Loan::GIVEN)
                               class="size-4">
                        {{ __('accounts::field.loan_given') }}
                    </label>
                </div>
            </fieldset>
        </section>

        <section class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="font-semibold">{{ __('accounts::section.loan_terms') }}</h2>
            <p class="mt-0.5 mb-3 max-w-(--spacing-prose-max) text-sm text-(--color-ink-muted)"
               x-show="kind === 'cc'" x-cloak>
                {{ __('accounts::message.cc_interest_note') }}
            </p>

            <div class="grid gap-3 sm:grid-cols-2">
                {{-- এক ঘর, দুই নাম: টার্ম লোনে মঞ্জুরিকৃত অঙ্ক, CC-তে সীমা --}}
                <label class="block">
                    <span class="mb-1 block text-sm font-medium">
                        <span x-show="kind === 'term'">{{ __('accounts::field.sanctioned') }}</span>
                        <span x-show="kind === 'cc'" x-cloak>{{ __('accounts::field.cc_limit') }}</span>
                    </span>
                    <input type="number" name="sanctioned" step="0.01" inputmode="decimal" required
                           value="{{ old('sanctioned') }}"
                           class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                  border-(--color-border) bg-(--color-surface-card) px-3 text-right
                                  font-(family-name:--font-numeric)">
                </label>

                <x-ui.field name="interest_rate" type="number" step="0.01" inputmode="decimal"
                            :label="__('accounts::field.interest_rate')"
                            :value="old('interest_rate')" required numeric />

                <x-ui.field name="start_date" type="date"
                            :label="__('accounts::field.starts_on')"
                            :value="old('start_date', now()->toDateString())" required />

                <div x-show="kind === 'term'">
                    <x-ui.field name="tenure_months" type="number" step="1" inputmode="numeric"
                                :label="__('accounts::field.tenure_months')"
                                :value="old('tenure_months')" numeric />
                </div>

                <div x-show="kind === 'term'">
                    <x-ui.field name="first_instalment_on" type="date"
                                :label="__('accounts::field.first_instalment_on')"
                                :value="old('first_instalment_on')" />
                </div>

                {{--
                    হাতধারের একমাত্র সময়সীমা।

                    কিস্তির সূচি নেই বলে দেরি ধরার আর কোনো উপায় নেই।
                    খালি রাখা যায়: কেউ তারিখ না বললে কথা ভাঙার প্রশ্নও
                    ওঠে না।
                --}}
                <div x-show="kind === 'hand'" x-cloak>
                    <x-ui.field name="due_on" type="date"
                                :label="__('accounts::field.loan_due_on')"
                                :value="old('due_on')" />
                </div>

                {{-- মেয়াদ শেষের তারিখ — প্রতিশ্রুতি নয়, চুক্তি। --}}
                <div x-show="kind === 'fd' || kind === 'dps'" x-cloak>
                    <x-ui.field name="matures_on" type="date"
                                :label="__('accounts::field.loan_matures_on')"
                                :value="old('matures_on')" />
                </div>

                {{--
                    এই FD কোন ঋণের পেছনে বাঁধা।

                    খালি রাখলে FD-টা হাতের টাকা। বাঁধা থাকলে তালিকায়
                    "আছে" দেখাবে ঠিকই, কিন্তু ভাঙানো যাবে না — আর ওই
                    টাকার উপর ভরসা করে নেওয়া সিদ্ধান্তই সবচেয়ে দামি ভুল।
                --}}
                <label class="block" x-show="kind === 'fd'" x-cloak>
                    <span class="mb-1 block text-sm font-medium">
                        {{ __('accounts::field.loan_pledged_against') }}
                    </span>
                    <select name="pledged_against_id"
                            class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                   border-(--color-border) bg-(--color-surface-card) px-3">
                        <option value="">{{ __('accounts::field.loan_not_pledged') }}</option>
                        @foreach ($openLoans as $open)
                            <option value="{{ $open->id }}"
                                    @selected(old('pledged_against_id') == $open->id)>
                                {{ $open->lender }} — {{ $open->document_no }}
                            </option>
                        @endforeach
                    </select>
                </label>

                <label class="block" x-show="kind === 'term'">
                    <span class="mb-1 block text-sm font-medium">{{ __('accounts::field.interest_method') }}</span>
                    <select name="interest_method"
                            class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                   border-(--color-border) bg-(--color-surface-card) px-3">
                        {{-- দুইটাই থাকে, কারণ দেশের ব্যাংকে দুইটাই চলে —
                             আর একই টাকায় একই হারে ফ্ল্যাট পদ্ধতিতে সুদ
                             প্রায় দ্বিগুণ হয়। কোনটা তা ঋণের কাগজে লেখা
                             থাকে; আমরা অনুমান করি না। --}}
                        <option value="{{ \App\Modules\Accounts\Services\LoanSchedule::REDUCING }}"
                                @selected(old('interest_method') === \App\Modules\Accounts\Services\LoanSchedule::REDUCING)>
                            {{ __('accounts::field.method_reducing') }}
                        </option>
                        <option value="{{ \App\Modules\Accounts\Services\LoanSchedule::FLAT }}"
                                @selected(old('interest_method') === \App\Modules\Accounts\Services\LoanSchedule::FLAT)>
                            {{ __('accounts::field.method_flat') }}
                        </option>
                    </select>
                </label>
            </div>

            <div class="mt-3">
                <x-ui.field name="security" :label="__('accounts::field.security')"
                            :value="old('security')" />
            </div>
        </section>

        <section class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="mb-3 font-semibold">{{ __('accounts::section.loan_accounts') }}</h2>

            <div class="grid gap-3 sm:grid-cols-2">
                <label class="block">
                    <span class="mb-1 block text-sm font-medium">{{ __('accounts::field.liability_account') }}</span>
                    <select name="principal_account_id" required
                            class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                   border-(--color-border) bg-(--color-surface-card) px-3">
                        <option value="">—</option>
                        @foreach ($principalAccounts as $account)
                            <option value="{{ $account->id }}"
                                    @selected(old('principal_account_id') == $account->id)>
                                {{ $account->code }} — {{ $account->name() }}
                            </option>
                        @endforeach
                    </select>
                </label>

                <label class="block">
                    <span class="mb-1 block text-sm font-medium">{{ __('accounts::field.interest_account') }}</span>
                    <select name="interest_account_id" required
                            class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                   border-(--color-border) bg-(--color-surface-card) px-3">
                        <option value="">—</option>
                        @foreach ($interestAccounts as $account)
                            <option value="{{ $account->id }}"
                                    @selected(old('interest_account_id') == $account->id)>
                                {{ $account->code }} — {{ $account->name() }}
                            </option>
                        @endforeach
                    </select>
                </label>

                {{-- টার্ম লোনেই কেবল: টাকাটা এখনই ঢোকে --}}
                {{--
                    টাকাটা কোথায় ঢুকল, বা কোথা থেকে বেরোল।

                    হাতধারেও লাগে: ওখানেও পুরো টাকা একবারেই নড়ে। ঘরটা
                    না থাকলে দাখিলাই বসত না — ধারটা খাতায় থাকত অথচ
                    টাকাটা কোথাও নড়ত না, আর নগদ মিলত না।

                    CC-তে লাগে না, কারণ সীমা মঞ্জুর হওয়া আর টাকা তোলা
                    দুইটা আলাদা ঘটনা।
                --}}
                <label class="block" x-show="kind === 'term' || kind === 'hand'">
                    <span class="mb-1 block text-sm font-medium">{{ __('accounts::field.into_account') }}</span>
                    <select name="into_account_id"
                            class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                   border-(--color-border) bg-(--color-surface-card) px-3">
                        <option value="">—</option>
                        @foreach ($moneyAccounts as $account)
                            <option value="{{ $account->id }}"
                                    @selected(old('into_account_id') == $account->id)>
                                {{ $account->code }} — {{ $account->name() }}
                            </option>
                        @endforeach
                    </select>
                </label>
            </div>

            <div class="mt-3">
                <x-ui.field name="narration" :label="__('accounts::field.note')"
                            :value="old('narration')" />
            </div>
        </section>

        <div class="flex gap-2">
            <x-ui.button type="submit" tone="primary" x-bind:disabled="busy">
                {{ __('accounts::action.save_loan') }}
            </x-ui.button>

            <x-ui.button tone="ghost" :href="route('accounts.loan.index')">
                {{ __('core.action.cancel') }}
            </x-ui.button>
        </div>
    </form>
</x-layouts.app>
