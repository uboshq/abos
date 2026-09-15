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

        {{-- ── ⭐ দুই কলাম — বাঁয়ে পরিচয়, ডানে টাকার কথা, ১৬ সেপ্টেম্বর ২০২৬ ──

             মালিকের সাজানো: পরিচয়ের ঘরগুলো বাঁ পাশে, আর ক্রেডিট ও খোলা
             ব্যালেন্স ডান পাশে — যাতে এক পর্দাতেই সব ধরে, স্ক্রল না লাগে।

             ── ⛔ প্রথমবার এটা করেছিলাম আর কিছুই হয়নি, কারণটা লিখে রাখি ──
             লিখেছিলাম `xl:grid-cols-2`। ⚠️ ক্লাসটা এই প্রকল্পে আর কোথাও
             ব্যবহার হয় না, তাই কম্পাইল করা CSS-এ ওটার কোনো নিয়মই নেই —
             ব্লেড বদলেছে, পর্দা বদলায়নি, আর কোনো ভুলবার্তাও আসেনি।

             ⛔ এটাই সবচেয়ে বাজে ধরনের ব্যর্থতা: **নীরব**। ⓘ শেখাটা হলো —
             নতুন Tailwind ক্লাস বসানোর আগে দেখে নিতে হবে ওটা সত্যিই
             তৈরি আছে কি না, নইলে CSS নতুন করে বানানোর আগ পর্যন্ত ওটা
             নিছক একটা বানান।

             ⭐ তাই এখানে কেবল সেই ক্লাসগুলো, যেগুলো গুনে দেখেছি CSS-এ
             আছে: `lg:grid-cols-2` ✓ · `items-start` ✓ · `gap-4` ✓

             ⓘ `items-start` — নইলে ছোট ডান কলামটা বাঁ পাশের উচ্চতায়
             টেনে লম্বা হয়ে যেত, আর ভিতরে ফাঁকা সাদা পড়ে থাকত। --}}
        <div class="grid items-start gap-4 lg:grid-cols-2">
        <div class="space-y-4">
        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="mb-3 font-semibold">{{ __('customer::section.identity') }}</h2>

            {{-- ── সারি ১ — কোড · শাখা · ধরন · পয়েন্ট ──────────────────────

                 ⭐ মালিকের সাজানো ক্রম, ১৫ সেপ্টেম্বর ২০২৬। ⓘ চারটাই
                 "এই গ্রাহকটা কে নয়, **কোথায় ও কোন শ্রেণিতে বসে**" —
                 নামের আগেই ঠিক হওয়া দরকার, কারণ ধরনটাই পরের ঘরগুলোর
                 নিয়ম বদলে দেয় (পরিবেশক হলে পয়েন্ট বাধ্যতামূলক)। --}}
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <x-ui.field name="code" :label="__('customer::field.code')"
                                   :value="old('code', $customer->code)"
                                   :hint="$isNew ? __('customer::message.code_auto') : null" />

                <x-ui.select name="branch_id" :label="__('core.company.branch')"
                             :options="$branches->mapWithKeys(fn ($b) => [$b->id => $b->name()])"
                             :selected="old('branch_id', $customer->branch_id)"
                             placeholder="—" />

                {{-- ধরন মাস্টার তালিকা থেকে, মুক্ত লেখা নয় — প্রতিষ্ঠান
                     নিজেই নতুন ধরন যোগ করতে পারে, তাই এখানে কোনো স্থির
                     তালিকাও নেই। আগে এটা খোলা ইনপুট ছিল, আর তাতে একই
                     ধরন "পাইকারি", "পাইকারী", "wholesale" তিন বানানে
                     জমা হত — তারপর ধরন ধরে রিপোর্ট করা যেত না। --}}
                {{-- নতুন গ্রাহকে ঘরটা খালি খোলে না — ডিফল্ট ধরনটা (পরিবেশক)
                     আগে থেকে বাছা থাকে; নাহলে কেউ ভুলে খালি রেখে সেভ করতেন
                     আর গ্রাহক ভুল শ্রেণিতে বসতেন। সম্পাদনায় নিজের ধরনটাই থাকে। --}}
                <x-ui.select name="party_type_id" :label="__('customer::field.type')"
                             :options="$partyTypes->mapWithKeys(fn ($t) => [$t->id => $t->name()])"
                             :selected="$typeNow"
                             :hint="__('customer::message.type_hint')"
                             placeholder="—"
                             x-model="partyType" />

                {{-- ⛔ পয়েন্টের তারাটা ধরন দেখে ওঠে-নামে।

                     ⓘ পরিবেশক ছাড়া বাকি সবার জন্য পয়েন্ট ঐচ্ছিক — নতুন
                     দোকান বসানোর সময় এলাকা ভাগ এখনো ঠিক না-ও থাকতে পারে।
                     ⚠️ কিন্তু পরিবেশকের পুরো সংজ্ঞাটাই এলাকা ধরে।

                     ⛔ সার্ভারের দিকেও একই নিয়ম আলাদা করে বসানো আছে —
                     এই তারাটা কেবল ভদ্রতা, পাহারা নয়। --}}
                <x-ui.select name="location_id" :label="__('customer::field.point')"
                             :options="$locations->mapWithKeys(fn ($l) => [$l->id => $l->name()])"
                             :selected="old('location_id', $customer->location_id)"
                             placeholder="-"
                             :hint="__('customer::message.point_hint')"
                             :required-when="$pointRule" />
            </div>

            {{-- ── সারি ২ — গ্রাহকের নাম, দুই ভাষায় ──────────────────── --}}
            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                <x-ui.field name="name_en" :label="__('customer::field.customer_name_en')"
                                   :value="old('name_en', $customer->name_en)" required />

                <x-ui.field name="name_bn" :label="__('customer::field.customer_name_bn')"
                                   :value="old('name_bn', $customer->name_bn)"
                                   :required="$requireBangla"
                                   :hint="__('customer::message.bn_name_hint')" />
            </div>

            {{-- ── সারি ৩ — মালিক · মোবাইল · ইমেইল ─────────────────────

                 দোকানের নাম আর মালিকের নাম এক নয়। "মায়ের দোয়া স্টোর"
                 কাগজে ছাপা হয়, আর ফোনে ধরতে হয় "রফিকুল ইসলাম"-কে। এক
                 ঘরে দুইটা লিখলে চালানে ভুল নাম যেত। --}}
            <div class="mt-3 grid gap-3 sm:grid-cols-3">
                <x-ui.field name="owner_name" :label="__('customer::field.owner_name')"
                            :value="old('owner_name', $customer->owner_name)" />

                {{-- মোবাইলে সঠিক কী-বোর্ড আসার জন্য type ঠিক দিতে হয়
                     (সেকশন ২০.৫) — ফোনের ঘরে অক্ষরের কী-বোর্ড এলে ফিল্ড
                     সেলসম্যানের প্রতিটা এন্ট্রি ধীর হয়। --}}
                <x-ui.field name="phone" type="tel" :label="__('customer::field.phone')"
                                   :value="old('phone', $customer->phone)" />

                <x-ui.field name="email" type="email" :label="__('customer::field.email')"
                                   :value="old('email', $customer->email)" />
            </div>

            {{-- ── সারি ৪ — ঠিকানা, দুই ভাষায় ─────────────────────────── --}}
            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                <x-ui.field name="address_en" :label="__('customer::field.address_en')"
                                   :value="old('address_en', $customer->address_en)" />
                <x-ui.field name="address_bn" :label="__('customer::field.address_bn')"
                                   :value="old('address_bn', $customer->address_bn)" />
            </div>
        </section>

        {{-- ── ⭐ চারটা ঘর এক লাইনে, ১৬ সেপ্টেম্বর ২০২৬ ────────────────────

             মালিকের নির্দেশ: বাকীর সীমা · বাকীর মেয়াদ · খোলা ব্যালেন্স ·
             খোলার তারিখ — চারটাই **এক সারিতে**।

             ⛔ আগে এরা দুইটা আলাদা বাক্সে দুই সারিতে বসত, আর প্রতিটা
             সারিতে মাত্র দুইটা ঘর — অর্থাৎ ডান পাশের অর্ধেকটা খালি, আর
             নিচে স্ক্রল। ⓘ নতুন গ্রাহক বসানো রোজকার কাজ; দিনে বিশবার
             স্ক্রল মানে বিশবার হাত কি-বোর্ড ছেড়ে মাউসে যাওয়া।

             ⚠️ দুইটা বাক্স এক হলো, কিন্তু **শর্ত দুইটাই আলাদা রইল** —
             ক্রেডিটের ঘর দুইটা Control Panel-এর সুইচ মানে, আর খোলা
             ব্যালেন্স কেবল তৈরির সময়। ⛔ শর্ত দুইটাকেও এক করে দিলে
             সুইচ বন্ধ থাকা প্রতিষ্ঠানে খোলা ব্যালেন্সের ঘরটাও উধাও হত। --}}
        </div>{{-- বাঁ কলাম শেষ — পরিচয় --}}

        <div class="space-y-4">
        @if ($creditLimitOn || $isNew)
            <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                <h2 class="mb-3 font-semibold">
                    @if ($creditLimitOn && $isNew)
                        {{ __('customer::section.credit_and_opening') }}
                    @elseif ($creditLimitOn)
                        {{ __('customer::section.credit') }}
                    @else
                        {{ __('customer::section.opening') }}
                    @endif
                </h2>

                {{-- ⓘ ডান কলামে চারটা ঘর এক সারিতে বসত না — কলামটা
                     পর্দার অর্ধেক, আর চারটা ঘর তখন এত সরু হত যে অঙ্কই
                     দেখা যেত না। ⭐ তাই দুই-দুই করে, দুই সারিতে। --}}
                <div class="grid gap-3 sm:grid-cols-2">
                    @if ($creditLimitOn)
                        {{-- inputmode="decimal" — টাকার ঘরে ফোনে সংখ্যার কী-বোর্ড --}}
                        <x-ui.field name="credit_limit" type="number" step="0.01" inputmode="decimal"
                                           :label="__('customer::field.credit_limit')"
                                           :value="old('credit_limit', $customer->credit_limit)"
                                           :hint="__('customer::message.zero_means_unlimited')" numeric />

                        {{-- ⭐ নামটা মালিক বদলিয়েছেন, ১৫ সেপ্টেম্বর ২০২৬।

                             ⓘ "ক্রেডিট দিন" পড়ে বোঝা যেত না দিনটা কীসের —
                             বাকী শুরুর দিন, না শেষের? ⚠️ দুইটা ঘর পাশাপাশি
                             বসে (সীমা ও মেয়াদ), তাই নামেই বলা দরকার কোনটা
                             টাকা আর কোনটা দিন। --}}
                        <x-ui.field name="credit_days" type="number" inputmode="numeric"
                                           :label="__('customer::field.credit_days')"
                                           :hint="__('customer::message.credit_days_hint')"
                                           :value="old('credit_days', $customer->credit_days)" numeric />
                    @endif

                    @if ($isNew)
                        <x-ui.field name="opening_balance" type="number" step="0.01" inputmode="decimal"
                                           :label="__('customer::field.opening_balance')"
                                           :value="old('opening_balance', 0)" numeric />
                        <x-ui.field name="opening_date" type="date"
                                           :label="__('customer::field.opening_date')"
                                           :value="old('opening_date')" />
                    @endif
                </div>

                {{-- ⓘ খোলা ব্যালেন্সের কথাটা এখন ঘরগুলোর **নিচে**।

                     ⚠️ উপরে থাকলে ওটা পুরো সারিটার ব্যাখ্যা বলে মনে হত,
                     অথচ কথাটা কেবল শেষ দুইটা ঘরের। --}}
                @if ($isNew)
                    <p class="mt-3 max-w-(--spacing-prose-max) text-sm text-(--color-ink-muted)">
                        {{ __('customer::message.opening_note') }}
                    </p>
                @endif
            </section>
        @endif

        {{-- কোম্পানির নিজের যোগ করা ঘরগুলো — কিছু না বানালে কিছুই আঁকা হয় না --}}
        <x-ui.custom-fields :record="$customer" />
        </div>{{-- ডান কলাম শেষ — টাকার কথা --}}
        </div>{{-- দুই কলামের গ্রিড শেষ --}}

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
