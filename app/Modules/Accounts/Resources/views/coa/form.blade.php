{{--
    খাত তৈরি ও সম্পাদনা — একটাই ফর্ম, দুই কাজে (One Form Standard, ১৫.২৪)।

    ধরনের ঘরটা বাবা বাছার সাথে সাথে নিষ্ক্রিয় হয়ে যায়: বাবা থাকলে ধরন
    বাবার থেকেই আসে, আর দুই জায়গায় দুই রকম বাছতে দিলে ব্যবহারকারী ভাবত
    তার বাছাটা টিকবে। সার্ভারেও একই নিয়ম — এটা শুধু চোখে দেখানো, যাচাই নয়।
--}}
@php
    $isNew = ! $account->exists;
    $locked = $account->is_system;
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $isNew ? __('accounts::action.new_account') : $account->name() }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header
            :title="$isNew ? __('accounts::action.new_account') : __('accounts::action.edit_account')"
            :subtitle="$isNew ? null : $account->label()" />
    </x-slot:header>

    @if ($locked)
        <div role="status"
             class="mb-4 max-w-3xl rounded-(--radius-field) bg-(--color-badge-warning-bg) px-3 py-2 text-sm
                    text-(--color-badge-warning-ink)">
            {{ __('accounts::validation.system_account_locked', ['name' => $account->name()]) }}
        </div>
    @endif

    {{--
        ফর্মের অবস্থা — তিনটে জিনিস, আর তিনটেই কেন তা এখানে লেখা।

        ⚠️ ব্যাখ্যাগুলো `x-data`-এর **ভিতরে** ছিল, আর একটা পাহারা সেটা
        ধরেছে ([[AQuoteInsideAnAttributeEndsItEarlyTest]])। কারণটা ভালো:
        অ্যাট্রিবিউটের ভিতরের লেখা ব্রাউজারে যায়, আর ভিতরে একটা ডবল
        কোট পড়লে অ্যাট্রিবিউটটা ওখানেই শেষ হয়ে যায় — ⛔ পাতাটা তবু
        ২০০ দেয় আর দেখতে ঠিক লাগে, কেবল JavaScript-টা নীরবে মরে থাকে।
        `{{--  --}}` ব্রাউজারে যায় না, তাই এখানে যা খুশি লেখা চলে।

        ── `kind` — এই খাতটা কোন ধরনের টাকা ধরে ─────────────────────────
        `''`, `cash`, `bank` বা `mfs`। ⛔ এটা ব্যবহারকারীর বাছা নয়,
        বাবার খাতের ফল। আগে এখানে দুইটা টিক ছিল, আর "ব্যাংক" মাথার নিচে
        বসিয়ে "নগদ খাত" টিক দেওয়া যেত — এক প্রশ্নের দুইটা উত্তর।

        ── `suggested` — পরের খালি কোডটা কী ────────────────────────────
        সার্ভারকে জিজ্ঞেস করে জানা। ⚠️ নিজে গুনে নেওয়া যেত, কিন্তু তাহলে
        নিয়মটা দুই জায়গায় থাকত — এখানে আর [[CodeSuggester]]-এ — আর একদিন
        দুইটা আলাদা উত্তর দিত। পর্দা যা দেখায় আর সেভ যা করে দুইটা আলাদা
        হলে ব্যবহারকারী সেটা টের পান সেভ করার পরে।

        ── `preview()` ─────────────────────────────────────────────────
        সম্পাদনায় কোড বদলায় না, কিন্তু ধরনটা তখনো জানা দরকার — বাবা
        বদলালে ব্যাংক ও MFS-এর ঘরগুলো আসা-যাওয়া করে। ⓘ ডাকটা ব্যর্থ হলে
        চুপ: ঘরটা খালি রাখলে সার্ভার নিজেই নম্বর বসায়, তাই পূর্বরূপ না
        দেখানো কিছু ভাঙে না।
    --}}
    <form method="POST"
          action="{{ $isNew ? route('accounts.coa.store') : route('accounts.coa.update', $account) }}"
          x-data="{
              busy: false,
              parent: '{{ old('parent_id', $preselectedParent) }}',
              isGroup: {{ old('is_group', $account->is_group) ? 'true' : 'false' }},
              isNew: {{ $isNew ? 'true' : 'false' }},
              kind: '{{ old('money_kind', $account->money_kind) }}',
              suggested: '',
              async preview() {
                  const wantsCode = this.isNew;
                  const url = new URL('{{ route('accounts.coa.next-code') }}', window.location.origin);
                  url.searchParams.set('parent', this.parent);
                  url.searchParams.set('group', this.isGroup ? '1' : '0');
                  try {
                      const response = await fetch(url, { headers: { 'Accept': 'application/json' } });
                      if (! response.ok) { this.suggested = ''; return; }
                      const body = await response.json();
                      this.suggested = wantsCode ? (body.code ?? '') : '';
                      this.kind = body.money_kind ?? '';
                  } catch {
                      this.suggested = '';
                  }
              },
          }"
          x-init="preview()"
          @submit="busy ? $event.preventDefault() : (busy = true)"
          class="max-w-3xl space-y-4">
        @csrf
        @unless ($isNew) @method('PUT') @endunless

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

        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="mb-3 font-semibold">{{ __('accounts::section.identity') }}</h2>

            <div class="grid gap-3 sm:grid-cols-2">
                {{-- `required` নেই — ফাঁকা রাখলে অভিভাবক খাতের নিচে পরের
                     খালি নম্বর বসে ([[CodeSuggester::underParent()]])। ১০১০
                     একটা ঠিকানা, তাই সিরিজ নয়, কাঠামো।

                     ⭐ কিন্তু "আপনাআপনি বসবে" পড়ে কেউ জানতেন না **কী**
                     বসবে, আর পরের কোডটা নিজে খুঁজে বের করাও কঠিন। তাই
                     মাথা বাছার সাথে সাথে নম্বরটা ঘরেই ফিকে করে দেখা যায়
                     — টাইপ করলে সেটা সরে যায়, অর্থাৎ পুরনো খাতার কোড
                     রাখার পথটা বন্ধ হয় না। --}}
                <x-ui.field name="code" :label="__('accounts::field.code')"
                                   :value="old('code', $account->code)"
                                   :placeholder="__('core.create.code_auto')"
                                   x-bind:placeholder="suggested || '{{ __('core.create.code_auto') }}'"
                                   :hint="__('core.create.code_auto_hint')"
                                   :readonly="$locked" numeric />

                <x-ui.field name="name_en" :label="__('accounts::field.name_en')"
                                   :value="old('name_en', $account->name_en)" required />

                <x-ui.field name="name_bn" :label="__('accounts::field.name_bn')"
                                   :value="old('name_bn', $account->name_bn)" />
            </div>
        </section>

        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="font-semibold">{{ __('accounts::section.placement') }}</h2>
            <p class="mt-0.5 mb-3 max-w-(--spacing-prose-max) text-sm text-(--color-ink-muted)">
                {{ __('accounts::message.parent_sets_type') }}
            </p>

            <div class="grid gap-3 sm:grid-cols-2">
                <label class="block">
                    <span class="mb-1 block text-sm font-medium">{{ __('accounts::field.parent') }}</span>
                    <select name="parent_id" x-model="parent" @change="preview()" @if ($locked) disabled @endif
                            class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                   border-(--color-border) bg-(--color-surface-card) px-3">
                        <option value="">— {{ __('accounts::field.type') }} —</option>
                        @foreach ($parents as $parent)
                            <option value="{{ $parent->id }}"
                                    @selected(old('parent_id', $preselectedParent) == $parent->id)>
                                {{ str_repeat('　', $parent->ancestors()->count()) }}{{ $parent->label() }}
                            </option>
                        @endforeach
                    </select>
                </label>

                <label class="block">
                    <span class="mb-1 block text-sm font-medium">{{ __('accounts::field.type') }}</span>
                    <select name="type"
                            :disabled="parent !== ''"
                            @if ($locked) disabled @endif
                            class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                   border-(--color-border) bg-(--color-surface-card) px-3
                                   disabled:bg-(--color-surface-app) disabled:text-(--color-ink-muted)">
                        @foreach (\App\Modules\Accounts\Models\Account::TYPES as $type)
                            <option value="{{ $type }}" @selected(old('type', $account->type) === $type)>
                                {{ __('accounts::type.' . $type) }}
                            </option>
                        @endforeach
                    </select>
                </label>

                {{-- ⛔ "প্রকৃতি" (ডেবিট/ক্রেডিট) ঘরটা এখানে ছিল, আর তুলে
                     দেওয়া হয়েছে — মালিকের প্রশ্নের উত্তরে: *"খাতে তো
                     ডেবিট ক্রেডিট দুইটাই হয়, তাহলে এটা জিজ্ঞেস করা হয়
                     কেন?"* কথাটা ঠিক। ⓘ প্রকৃতি মানে **কোন দিকে বাড়ে**,
                     কোন দিকে বসানো যায় তা নয় — আর সেটা ধরন থেকেই জানা:
                     সম্পদ ও খরচ ডেবিটে বাড়ে, দায় ও মূলধন ও আয় ক্রেডিটে
                     ([[Account::defaultNatureFor()]])।

                     ⚠️ কলামটা থেকে যাচ্ছে, কারণ দুইটা সত্যিকারের
                     ব্যতিক্রম আছে — ১২৯০ সঞ্চিত অবচয় (সম্পদ, অথচ
                     ক্রেডিট) আর ৩২০০ উত্তোলন (মূলধন, অথচ ডেবিট)। ⭐ দুইটাই
                     প্রমিত ছকে হাতে লেখা, ব্যবহারকারীর টিকের উপর নির্ভর
                     করে না। অর্থাৎ ঘরটা ৯৯% ব্যবহারকারীর কাছে একটা
                     প্রশ্ন ছিল যার ভুল উত্তর দিলে খোলা জেরটা উল্টো দিকে
                     বসত ([[OpeningBalanceService:201]]) — আর সেটা চোখে
                     পড়ত অনেক পরে। --}}
            </div>

            <div class="mt-3 space-y-2">
                <label class="flex min-h-(--spacing-touch) items-center gap-2 text-sm">
                    <input type="checkbox" name="is_group" value="1" x-model="isGroup"
                           @change="preview()"
                           @checked(old('is_group', $account->is_group)) @if ($locked) disabled @endif
                           class="size-4">
                    <span>
                        {{ __('accounts::field.is_group') }}
                        <span class="block text-2xs text-(--color-ink-muted)">
                            {{ __('accounts::message.group_hint') }}
                        </span>
                    </span>
                </label>

            </div>

            {{-- ⭐ টাকার ধরন — জিজ্ঞেস নয়, জানানো।

                 আগে এখানে দুইটা টিক ছিল। সেগুলো তুলে দেওয়ার কারণ
                 মাইগ্রেশনে লেখা আছে (`bank_and_mfs_wore_the_same_flag`),
                 এক বাক্যে: ছকে নগদ · ব্যাংক · MFS তিনটা আলাদা মাথা আগে
                 থেকেই ছিল, তাই টিক দুইটা একই প্রশ্নের দ্বিতীয় উত্তর দিত,
                 আর দুইটা আলাদা হলে টাকা আটকে যেত। --}}
            <template x-if="kind">
                <p class="mt-3 rounded-(--radius-field) bg-(--color-surface-app) px-3 py-2 text-sm">
                    <span x-show="kind === 'cash'">{{ __('accounts::message.holds_cash') }}</span>
                    <span x-show="kind === 'bank'">{{ __('accounts::message.holds_bank') }}</span>
                    <span x-show="kind === 'mfs'">{{ __('accounts::message.holds_mfs') }}</span>
                </p>
            </template>

        </section>

        {{-- টাকার ঘরগুলো — ধরন অনুযায়ী, আর দুইটা ধরন আলাদা।

             ⛔ আগে এখানে একটাই বাক্স ছিল, শিরোনাম "ব্যাংকের তথ্য", আর
             শর্ত `! isGroup` — অর্থাৎ গ্রুপ ছাড়া **প্রতিটা** খাতে বাক্সটা
             খুলত: বেতনের খরচ, দোকান ভাড়া, বিক্রয় — সবখানে। ঠিক উপরে
             মন্তব্যে লেখা ছিল "শুধু ব্যাংক খাতে", অর্থাৎ মন্তব্য একটা
             নিয়ম বলত আর কোড আরেকটা করত।

             ⭐ আর ভেতরের ঘরগুলো ব্যাংক ও MFS দুইটার জন্য একই ছিল, যদিও
             বিকাশের কোনো **শাখা** নেই আর কোনো **রাউটিং নম্বর** নেই।
             ব্যবহারকারী হয় খালি রাখতেন, নয় কিছু একটা লিখতেন — আর সেই
             "কিছু একটা" পরে জমা স্লিপে ছাপা হত। --}}
        {{-- ⚠️ `x-show` নয়, `x-if` — আর কারণটা নীরব ভুল।

             দুইটা বাক্সেই `bank_name` নামের ঘর আছে (ব্যাংকে "ব্যাংকের
             নাম", MFS-এ "সেবাদাতা")। `x-show` কেবল চোখ থেকে লুকায়,
             ঘরটা DOM-এ থেকে যায় আর জমার সাথে **যায়ও** — ফলে লুকানো
             খালি ঘরটা ভরা ঘরটাকে মুছে দিত, আর ব্যবহারকারী সেভ করে দেখতেন
             নামটা উধাও। `x-if` ঘরটা সত্যিই সরিয়ে দেয়। --}}
        <template x-if="kind === 'bank'">
        <section data-boxed
                 class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="mb-3 font-semibold">{{ __('accounts::section.bank') }}</h2>

            <div class="grid gap-3 sm:grid-cols-2">
                <x-ui.field name="bank_name" :label="__('accounts::field.bank_name')"
                                   :value="old('bank_name', $account->bank_name)" />
                <x-ui.field name="branch_name" :label="__('accounts::field.branch_name')"
                                   :value="old('branch_name', $account->branch_name)" />

                {{-- ⭐ হিসাবের নাম — মালিক ধরেছেন এটা ছিল না।
                     ব্যাংকে টাকা পাঠানোর সময় হিসাব নম্বরের সাথে নামটাও
                     মিলতে হয়; না মিললে ব্যাংক ফেরত পাঠায়। নামটা খাতের
                     নামের সমান নয় — খাত "ইসলামী ব্যাংক চলতি", হিসাবের
                     নাম "মেসার্স আদি এন্টারপ্রাইজ"। --}}
                <x-ui.field name="account_title" :label="__('accounts::field.account_title')"
                                   :value="old('account_title', $account->account_title)" />
                <x-ui.field name="account_number" :label="__('accounts::field.account_number')"
                                   :value="old('account_number', $account->account_number)" numeric />

                {{-- ⭐ রাউটিং নম্বর — নয় অঙ্কের, শাখা চেনায়। এটা ছাড়া
                     EFT বা RTGS-এর কোনো ফাইল বানানো যায় না, আর চেক
                     জমা দিতে গেলে ব্যাংক এটাই জানতে চায়। --}}
                <x-ui.field name="routing_no" :label="__('accounts::field.routing_no')"
                                   :hint="__('accounts::message.routing_no_hint')"
                                   :value="old('routing_no', $account->routing_no)" numeric />
            </div>
        </section>
        </template>

        {{-- MFS — বিকাশ, নগদ, রকেট, উপায়।

             ⓘ ঘরগুলো ইচ্ছাকৃতভাবে কম: শাখা নেই, রাউটিং নেই, হিসাবের
             নামও নেই — MFS-এ নম্বরটাই পরিচয়। ⚠️ কলামগুলো ব্যাংকের
             সাথে ভাগ করা (`bank_name` → সেবাদাতা, `account_number` →
             নম্বর), কারণ দুইটার জন্য আলাদা কলাম রাখলে "কোনটায় লেখা
             আছে" প্রশ্নটা প্রতিটা রিপোর্টে ফিরে আসত। --}}
        <template x-if="kind === 'mfs'">
        <section data-boxed
                 class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="mb-3 font-semibold">{{ __('accounts::section.mfs') }}</h2>

            <div class="grid gap-3 sm:grid-cols-2">
                <x-ui.field name="bank_name" :label="__('accounts::field.mfs_provider')"
                                   :hint="__('accounts::message.mfs_provider_hint')"
                                   :value="old('bank_name', $account->bank_name)" />
                <x-ui.field name="account_number" :label="__('accounts::field.mfs_wallet')"
                                   :value="old('account_number', $account->account_number)" numeric />
            </div>
        </section>
        </template>

        @if ($isNew)
            <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4"
                     x-show="! isGroup" x-cloak>
                <h2 class="font-semibold">{{ __('accounts::section.opening') }}</h2>
                <p class="mt-0.5 mb-3 max-w-(--spacing-prose-max) text-sm text-(--color-ink-muted)">
                    {{ __('accounts::message.opening_note') }}
                </p>

                <div class="grid gap-3 sm:grid-cols-2">
                    <x-ui.field name="opening_balance" type="number" step="0.01" inputmode="decimal"
                                       :label="__('accounts::field.opening_balance')"
                                       :value="old('opening_balance', 0)" numeric />
                    <x-ui.field name="opening_date" type="date"
                                       :label="__('accounts::field.opening_date')"
                                       :value="old('opening_date')" />
                </div>
            </section>
        @endif

        <div class="flex flex-wrap gap-2">
            <x-ui.button type="submit" tone="primary"
                         ::class="busy && 'pointer-events-none opacity-70'">
                {{ __('core.action.save') }}
            </x-ui.button>

            <x-ui.button tone="secondary"
                         :href="$isNew ? route('accounts.coa.index') : route('accounts.coa.show', $account)">
                {{ __('core.action.cancel') }}
            </x-ui.button>
        </div>
    </form>
</x-layouts.app>
