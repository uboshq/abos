{{--
    গ্রাহক তৈরি ও সম্পাদনা — একটাই ফর্ম, দুই কাজে (One Form Standard,
    সেকশন ১৫.২৪)। আলাদা create ও edit ফর্ম রাখলে একটায় ফিল্ড যোগ করে
    অন্যটায় ভুলে যাওয়া নিশ্চিত।

    ঐচ্ছিক ফিল্ডগুলো Control Panel-এর সুইচ মানে (নিয়ম ৭): ক্রেডিট লিমিট
    বন্ধ থাকলে ঘরটা কোথাও নেই — এখানেও না, প্রিন্টেও না।
--}}
@php
    $isNew = ! $customer->exists;

    /*
     * ⭐ কোন ধরনটা "পরিবেশক" — আর সেটাই পয়েন্টের ঘরটাকে বাধ্যতামূলক করে।
     *
     * ⓘ মালিকের নিয়ম: *"গ্রাহকের ধরন যদি পরিবেশক হয় তাহলে পয়েন্ট
     * বাধ্যতামূলক"*, কারণ **এক এলাকায় একজনই পরিবেশক হয়** — আর এলাকাটা
     * না জানলে ঐ নিয়মটা কীসের উপর দাঁড়াবে?
     *
     * ⚠️ এখানে তারাটা কেবল **চোখের জন্য**। আসল পাহারা
     * [[CustomerService::assertOnlyOneDistributorPerPoint()]]-এ, কারণ
     * গ্রাহক তিনটা দরজা দিয়ে ঢোকে — ফর্ম, ইমপোর্ট, মোবাইল সিংক।
     */
    $distributorTypeId = $partyTypes->first(fn ($t) => $t->isDistributor())?->id;

    $typeNow = old('party_type_id',
        $customer->party_type_id ?? $partyTypes->firstWhere('is_default', true)?->id);

    /*
     * ⛔ শর্তটা এক জায়গায়, আর তিনজন ওটাই দেখে, ১৫ সেপ্টেম্বর ২০২৬।
     *
     * ⚠️ আগে তারাটা সার্ভার থেকে স্থির আঁকা হত, আর `required`-টা
     * Alpine থেকে। ফল: ধরন বদলে "খুচরা বিক্রেতা" করলেও **তারাটা থেকে
     * যেত**, আর ফাঁকা সারিটা `disabled` থাকায় পয়েন্টটা খালিও করা যেত না।
     *
     * ⛔ অর্থাৎ পর্দা বলত "ঐচ্ছিক", আর ঘরটা ছাড়ত না — মালিক ঠিক এটাই
     * ধরেছেন। ⓘ এখন তারা, `required` আর ফাঁকা সারি — তিনটাই এই একটা
     * শর্ত থেকে আসে, তাই ওরা কোনোদিন আলাদা কথা বলতে পারে না।
     *
     * ⓘ পরিবেশক ধরনটা না থাকলে (কেউ মুছে দিলে) শর্তটা `false` — অর্থাৎ
     * পয়েন্ট কারো জন্যই বাধ্যতামূলক নয়, আর সেটাই নিরাপদ ডিফল্ট।
     */
    $pointRule = filled($distributorTypeId)
        ? "partyType === '{$distributorTypeId}'"
        : 'false';
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $isNew ? __('customer::action.new') : $customer->name() }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header
            :title="$isNew ? __('customer::action.new') : __('customer::action.edit')"
            :subtitle="$isNew ? __('customer::message.code_auto') : $customer->code" />
    </x-slot:header>

    <form method="POST"
          action="{{ $isNew ? route('customer.store') : route('customer.update', $customer) }}"
          x-data="{ busy: false, partyType: '{{ $typeNow }}' }"
          @submit="busy ? $event.preventDefault() : (busy = true)"
          {{-- ⛔ কোনো `max-w-*` নেই, আর সেটা ইচ্ছাকৃত — ১৬ সেপ্টেম্বর ২০২৬।

               আগে এখানে `max-w-3xl` ছিল, তাই চওড়া পর্দায় ডান পাশের
               প্রায় অর্ধেকটা খালি পড়ে থাকত — অথচ নিচের বাক্সগুলো দেখতে
               স্ক্রল করতে হত। মালিক ঠিক এটাই ধরেছেন।

               ⚠️ একবার `max-w-7xl` বসিয়েছিলাম, আর সেটা **কিছুই বদলায়নি**:
               ক্লাসটা এই প্রকল্পে আর কোথাও নেই, তাই কম্পাইল করা CSS-এ
               ওটার কোনো নিয়মও নেই। ⛔ ওভাবে রেখে দিলে যেদিন CSS নতুন করে
               বানানো হত সেদিন পাতাটা হঠাৎ সরু হয়ে যেত — আর কেউ বুঝত না
               কেন, কারণ ব্লেডে কোনো বদল নেই।

               ⓘ তাই সীমাটা তোলাই থাকল: সারিগুলো এখন চার-চার করে সাজানো,
               আর ওরা জায়গাটা সত্যিই কাজে লাগায়। --}}
          class="space-y-4">
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

        {{-- ── ⭐ আন্তর্জাতিক গ্রাহক-কার্ডের সাজ — মালিক, ৬ অক্টোবর ২০২৬: *"এটা ভালো করে আন্তর্জাতিক মান অনুযায়ী সাজিয়ে দাও"* ──

             ⓘ ছাঁচ SAP / Dynamics-এর গ্রাহক-কার্ড: বাঁয়ে (দুই ভাগ চওড়া) **কে** আর **কোথায়** —
             সাধারণ তথ্য, তারপর ঠিকানা ও এলাকা; ডানে (এক ভাগ) **টাকার কথা** — বিক্রয় ও বাকি, তারপর খোলা ব্যালেন্স।
             ⛔ আগে প্রথম সারিতে পাঁচটা ঘর চার কলামে বসত, তাই পয়েন্ট একা দ্বিতীয় সারিতে ঝুলত, আর পথের লম্বা
             ইঙ্গিত সারির উচ্চতা ভেঙে দিত। এখন প্রতিটা সারিতে যতটা ঘর, ততটাই কলাম।
             ⓘ খোলা ব্যালেন্স এখন অঙ্ক + দিক (দেবে Dr / পাবে Cr) — ঋণাত্মক অঙ্ক লিখে আগাম বোঝানো ভুলপ্রবণ ছিল
             ([[CustomerRequest::prepareForValidation()]])।
             ⚠️ কেবল সেই Tailwind ক্লাস, যেগুলো বানানো CSS-এ আছে (lg:grid-cols-3, lg:col-span-2, sm:grid-cols-2/3, sm:col-span-2)। --}}
        <div class="grid items-start gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="mb-3 font-semibold">{{ __('customer::section.general') }}</h2>

            {{-- ── সারি ১ — কোড · ধরন · মালিক। ⓘ ধরনটাই পরের নিয়ম বদলায় (পরিবেশক হলে পয়েন্ট বাধ্যতামূলক)।
                 ধরন মাস্টার তালিকা থেকে; নতুন গ্রাহকে ডিফল্ট ধরনটা আগে থেকে বাছা। দোকানের নাম আর মালিকের নাম এক নয় —
                 কাগজে দোকান ছাপা হয়, ফোনে ধরতে হয় মালিককে। --}}
            <div class="grid gap-3 sm:grid-cols-3">
                <x-ui.field name="code" :label="__('customer::field.code')"
                                   :value="old('code', $customer->code)"
                                   :hint="$isNew ? __('customer::message.code_auto') : null" />

                <x-ui.select name="party_type_id" :label="__('customer::field.type')"
                             :options="$partyTypes->mapWithKeys(fn ($t) => [$t->id => $t->name()])"
                             :selected="$typeNow"
                             :hint="__('customer::message.type_hint')"
                             placeholder="—"
                             x-model="partyType" />

                <x-ui.field name="owner_name" :label="__('customer::field.owner_name')"
                            :value="old('owner_name', $customer->owner_name)" />
            </div>

            {{-- ── সারি ২ — গ্রাহকের নাম, দুই ভাষায় ── --}}
            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                <x-ui.field name="name_en" :label="__('customer::field.customer_name_en')"
                                   :value="old('name_en', $customer->name_en)" required />

                <x-ui.field name="name_bn" :label="__('customer::field.customer_name_bn')"
                                   :value="old('name_bn', $customer->name_bn)"
                                   :required="$requireBangla"
                                   :hint="__('customer::message.bn_name_hint')" />
            </div>
            <x-ui.duplicate-confirm />

            {{-- ── সারি ৩ — মোবাইল · ইমেইল। ⓘ type="tel" — ফোনে সংখ্যার কী-বোর্ড (সেকশন ২০.৫)। --}}
            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                <x-ui.field name="phone" type="tel" :label="__('customer::field.phone')"
                                   :value="old('phone', $customer->phone)" />

                <x-ui.field name="email" type="email" :label="__('customer::field.email')"
                                   :value="old('email', $customer->email)" />
            </div>
        </section>

        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="mb-3 font-semibold">{{ __('customer::section.address_area') }}</h2>

            {{-- ── সারি ১ — শাখা · পয়েন্ট (দুই ভাগ চওড়া, খোঁজার ঘর)।

                 ⛔ পয়েন্টের তারাটা ধরন দেখে ওঠে-নামে; পরিবেশক ছাড়া বাকিদের জন্য ঐচ্ছিক। সার্ভারেও একই নিয়ম
                 ([[CustomerService::assertOnlyOneDistributorPerPoint()]]) — তারাটা ভদ্রতা, পাহারা নয়।
                 ⭐ খোঁজা যায় — মালিক, ৬ অক্টোবর ২০২৬: *"নতুন গ্রাহক পেজে পয়েন্ট * সার্চ বক্স দাও"*। ⓘ লম্বা
                 `<select>`-এর বদলে [[x-ui.party-search]]: নাম (দুই ভাষা) বা কোড লিখে খোঁজা, ফর্ম আগের নামেই
                 (`location_id`) আগের মান পাঠায়। ঐচ্ছিক ঘর, তাই মাথায় "—"। --}}
            <div class="grid gap-3 sm:grid-cols-3">
                <x-ui.select name="branch_id" :label="__('core.company.branch')"
                             :options="$branches->mapWithKeys(fn ($b) => [$b->id => $b->name()])"
                             :selected="old('branch_id', $customer->branch_id)"
                             placeholder="—" />

                <div class="sm:col-span-2">
                    <span id="location_id-label" class="mb-1 block text-sm font-medium">
                        {{ __('customer::field.point') }}
                        <span class="text-(--color-danger)" aria-hidden="true"
                              x-cloak x-show="{{ $pointRule }}">*</span>
                        <span class="sr-only" x-cloak x-show="{{ $pointRule }}">({{ __('core.form.required') }})</span>
                    </span>
                    <x-ui.party-search name="location_id" clearable wide
                                       :aria-label="__('customer::field.point')"
                                       :placeholder="__('customer::message.point_search')"
                                       :selected="old('location_id', $customer->location_id)"
                                       :options="$locations->map(fn ($l) => [
                                           'id' => $l->id,
                                           'label' => $l->name(),
                                           'hint' => (string) $l->code,
                                           'find' => mb_strtolower($l->code.' '.$l->name_en.' '.$l->name_bn),
                                       ])->all()" />
                    <p class="mt-1 text-2xs text-(--color-ink-muted)">{{ __('customer::message.point_hint') }}</p>
                </div>
            </div>

            {{-- ── সারি ২ — ঠিকানা, দুই ভাষায় ── --}}
            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                <x-ui.field name="address_en" :label="__('customer::field.address_en')"
                                   :value="old('address_en', $customer->address_en)" />
                <x-ui.field name="address_bn" :label="__('customer::field.address_bn')"
                                   :value="old('address_bn', $customer->address_bn)" />
            </div>
        </section>
        </div>{{-- বাঁ দিক শেষ — কে ও কোথায় --}}

        <div class="space-y-4">
        @php
            // বিক্রয়ের পথ — নতুন গ্রাহকে ডিফল্ট পথটা আগে থেকে বাছা; বন্ধ হয়ে যাওয়া নিজের পথটা তালিকায় থাকে,
            // নাহলে সেভ চাপলে ঘরটা নীরবে খালি হয়ে যেত।
            $channelChoices = $salesChannels;
            if ($customer->channel && ! $channelChoices->contains('id', $customer->channel_id)) {
                $channelChoices = $channelChoices->push($customer->channel);
            }

            // ⓘ "0.0000" নয় — টাকার ঘরে দুই দশমিক (মালিক, ৬ অক্টোবর ২০২৬)
            $limitNow = old('credit_limit', $customer->credit_limit !== null ? \App\Core\Support\Money::round($customer->credit_limit) : null);

            $mayOpen = $isNew && auth()->user()?->can(\App\Modules\Customer\Services\CustomerService::OPENING_KEY);

            // ⓘ ফর্মে অঙ্ক সব সময় ধনাত্মক, দিক আলাদা — পুরনো ঋণাত্মক মান ফিরলে "পাবে (Cr)"
            $openingRaw = trim((string) old('opening_balance', ''));
            $openingSide = old('opening_side', str_starts_with($openingRaw, '-') ? 'cr' : 'dr');
            $openingShown = ltrim($openingRaw, '-');
        @endphp

        {{-- ── বিক্রয় ও বাকি — পথ সব সময়; সীমা আর মেয়াদ কেবল Control Panel-এর সুইচ চালু থাকলে (নিয়ম ৭) ── --}}
        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="mb-3 font-semibold">{{ __('customer::section.sales_credit') }}</h2>

            <x-ui.select name="channel_id" :label="__('customer::channel.field')"
                         :options="$channelChoices->mapWithKeys(fn ($c) => [$c->id => $c->name()])"
                         :selected="old('channel_id', $customer->channel_id ?? ($customer->exists ? null : $salesChannels->firstWhere('is_default', true)?->id))"
                         :hint="__('customer::channel.hint')"
                         placeholder="—" />

            @if ($creditLimitOn)
                {{-- ⓘ "বাকীর সীমা" টাকা, "বাকীর মেয়াদ" দিন — নামেই বলা, কারণ দুইটা পাশাপাশি বসে।
                     ⛔ ০ বা খালি মানে বাকি নেই, কেবল নগদ (মালিক, ১ অক্টোবর ২০২৬) — আগের ইঙ্গিত "০ মানে সীমা নেই" উল্টো বলত। --}}
                <div class="mt-3 grid gap-3 sm:grid-cols-2">
                    <x-ui.field name="credit_limit" type="number" step="0.01" inputmode="decimal"
                                       :label="__('customer::field.credit_limit')"
                                       :value="$limitNow"
                                       :hint="__('customer::message.zero_means_unlimited')" numeric />

                    <x-ui.field name="credit_days" type="number" inputmode="numeric"
                                       :label="__('customer::field.credit_days')"
                                       :hint="__('customer::message.credit_days_hint')"
                                       :value="old('credit_days', $customer->credit_days)" numeric />
                </div>
            @endif
        </section>

        {{-- ── খোলা ব্যালেন্স — কেবল তৈরির সময়, আর নিজের চাবি থাকলে ([[CustomerService::assertMayOpenABalance()]]) ── --}}
        @if ($mayOpen)
            <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                <h2 class="mb-3 font-semibold">{{ __('customer::section.opening') }}</h2>

                <div class="grid gap-3 sm:grid-cols-2">
                    <x-ui.field name="opening_balance" type="number" step="0.01" min="0" inputmode="decimal"
                                       :label="__('customer::field.opening_balance')"
                                       :value="$openingShown" placeholder="0.00" numeric />

                    <x-ui.select name="opening_side" :label="__('customer::field.opening_side')"
                                 :options="['dr' => __('customer::field.opening_side_dr'), 'cr' => __('customer::field.opening_side_cr')]"
                                 :selected="$openingSide" />

                    <div class="sm:col-span-2">
                        <x-ui.field name="opening_date" type="date"
                                           :label="__('customer::field.opening_date')"
                                           :value="old('opening_date')" />
                    </div>
                </div>

                <p class="mt-3 text-2xs text-(--color-ink-muted)">{{ __('customer::message.opening_note') }}</p>
            </section>
        @endif

        {{-- কোম্পানির নিজের যোগ করা ঘরগুলো — কিছু না বানালে কিছুই আঁকা হয় না --}}
        <x-ui.custom-fields :record="$customer" />
        </div>{{-- ডান দিক শেষ — টাকার কথা --}}
        </div>{{-- গ্রিড শেষ --}}

        {{-- ⓘ বোতাম দুইটা গ্রিডের বাইরে — পুরো চওড়ায়, বাঁ দিক ঘেঁষে।
             ⚠️ ডান কলামের ভিতরে রাখলে "সংরক্ষণ" পর্দার ডান পাশে চলে
             যেত, আর চোখ ফর্মের শেষ ঘর থেকে ওখানে খুঁজতে যেত। --}}
        <div class="flex flex-wrap gap-2">
            <x-ui.button type="submit" tone="primary"
                         ::class="busy && 'pointer-events-none opacity-70'">
                {{ __('core.action.save') }}
            </x-ui.button>

            <x-ui.button tone="secondary"
                         :href="$isNew ? route('customer.index') : route('customer.show', $customer)">
                {{ __('core.action.cancel') }}
            </x-ui.button>
        </div>
    </form>
</x-layouts.app>
