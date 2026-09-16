{{--
    সরবরাহকারী তৈরি ও সম্পাদনা — একটাই ফর্ম, দুই কাজে (One Form Standard,
    সেকশন ১৫.২৪)।

    গ্রাহকের ফর্মের সাথে দুইটা আসল পার্থক্য: ধরনটা এখানে ড্রপডাউন (মাস্টার
    তালিকা থেকে, মুক্ত লেখা নয়), আর ক্রেডিট সীমার ঘরটা কখনো লুকায় না —
    সেটা তথ্য, নিয়ম নয়, তাই বন্ধ করার সুইচও নেই।
--}}
@php
    $isNew = ! $supplier->exists;
@endphp

<x-layouts.app :menu="$menu">
    @php
        /*
         * ⭐ কাগজটা এক, কিন্তু নামটা প্রসঙ্গ মানে — মালিকের নির্দেশ,
         * ১৬ সেপ্টেম্বর ২০২৬।
         *
         * ⓘ যিনি সেবাদাতার তালিকা থেকে "নতুন" চাপলেন, তাঁর পর্দায়
         * "নতুন সরবরাহকারী" লেখা থাকলে মনে হত ভুল বোতামে চাপ পড়েছে।
         *
         * ⚠️ সম্পাদনার বেলায় প্রশ্নটা আসে **সারি থেকে**, ঠিকানা থেকে নয়:
         * যাঁর ধরন সরবরাহকারী নয়, তিনি সেবাদাতা — যে পথ ধরেই আসুন।
         * ⛔ ঠিকানার উপর ভরসা করলে কেউ লিংক কপি করে পাঠালে নামটা ভুল হত।
         */
        $isService = $isNew
            ? ($kind ?? null) === 'service'
            : ($supplier->partyType !== null
                && $supplier->partyType->code !== \App\Modules\Supplier\Models\Supplier::VENDOR_CODE);

        $newLabel = $isService ? __('supplier::action.new_service') : __('supplier::action.new');
        $editLabel = $isService ? __('supplier::action.edit_service') : __('supplier::action.edit');
    @endphp

    <x-slot:title>{{ $isNew ? $newLabel : $supplier->name() }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header
            :title="$isNew ? $newLabel : $editLabel"
            :subtitle="$isNew ? __('supplier::message.code_auto') : $supplier->code" />
    </x-slot:header>

    <form method="POST"
          action="{{ $isNew ? route('supplier.store') : route('supplier.update', $supplier) }}"
          x-data="{ busy: false }"
          @submit="busy ? $event.preventDefault() : (busy = true)"
          class="max-w-6xl space-y-4">
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

        {{-- ⭐ দুই কলাম — মালিকের নির্দেশ, ১৬ সেপ্টেম্বর ২০২৬।

             মালিক ছবিতে দাগিয়ে দেখালেন পর্দার **ডান অর্ধেকটা পুরো ফাঁকা**,
             আর সব ঘর পেতে নিচে স্ক্রল করতে হয়। ⓘ কারণ ছিল ফর্মের
             `max-w-3xl` (৭৬৮px), অথচ পর্দা ১৪৭০px।

             ⭐ ভাগটা মালিকের নিজের, আর ওটার একটা স্পষ্ট অর্থ আছে:

               বাঁয়ে — **তারা কারা** : পরিচয় · ঠিকানা · যোগাযোগ
               ডানে  — **আমাদের সাথে সম্পর্ক** : কর · বাকি · জের

             ⓘ বোতাম দুইটাও ডানে, সবার নিচে — সিদ্ধান্তের জায়গা একটাই,
             আর ওটা শর্তগুলোর ঠিক পরে।

             ⚠️ `lg:` থেকে, তার নিচে এক কলাম — ট্যাব বা ফোনে দুই কলামে
             ঘরগুলো টেপাটেপি হয়ে যেত।

             ⚠️ `items-start` — নাহলে দুই কলাম সমান উঁচু হতে গিয়ে
             ছোট কার্ডগুলো টেনে লম্বা হয়ে যেত, আর ভেতরে ফাঁকা জমিন
             পড়ে থাকত। --}}
        <div class="grid gap-4 lg:grid-cols-2 lg:items-start">
            <div class="space-y-4">
        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="mb-3 font-semibold">{{ __('supplier::section.identity') }}</h2>

            {{-- ── সারি ১ — কোড · শাখা · ধরন ──────────────────────────
                 মালিকের সাজানো, ১৫ সেপ্টেম্বর ২০২৬।

                 ⓘ তিনটাই **ছোট উত্তর**: একটা সংখ্যা আর দুইটা বাছাই। তাই
                 তিন কলামে পাশাপাশি বসে, আর এক সারিতেই পুরো শ্রেণিবিন্যাস
                 চোখে পড়ে — এই সরবরাহকারী কোন শাখার, কী ধরনের।

                 ⚠️ শাখা আগে **ঠিকানার ঘরে** ছিল, আর সেটা ভুল জায়গা:
                 শাখা সরবরাহকারীর ঠিকানা নয়, **আমাদের** কোন শাখার খাতায়
                 তিনি বসবেন সেটা। ⓘ দুইটা আলাদা প্রশ্ন, আর একসাথে থাকায়
                 পড়তে গিয়ে গুলিয়ে যেত। --}}
            <div class="grid gap-3 sm:grid-cols-3">
                <x-ui.field name="code" :label="__('supplier::field.code')"
                            :value="old('code', $supplier->code)"
                            :hint="$isNew ? __('supplier::message.code_auto') : null" />

                <x-ui.select name="branch_id" :label="__('supplier::field.branch')"
                             :options="$branches->mapWithKeys(fn ($b) => [$b->id => $b->name()])"
                             :selected="$supplier->branch_id"
                             placeholder="—" />

                {{-- ধরন মাস্টার তালিকা থেকে — প্রতিষ্ঠান নিজেই নতুন ধরন
                     যোগ করতে পারে, তাই এখানে কোনো স্থির তালিকা নেই। --}}
                <x-ui.select name="party_type_id" :label="__('supplier::field.party_type')"
                             :options="$partyTypes->mapWithKeys(fn ($t) => [$t->id => $t->name()])"
                             :selected="$supplier->party_type_id"
                             placeholder="—" />
            </div>

            {{-- ── সারি ২ — দুই ভাষায় নাম ─────────────────────────────
                 ⓘ নাম দুইটা **একই প্রশ্নের দুই রূপ**, তাই পাশাপাশি।
                 ⚠️ তিন কলামে ঢোকালে নামের ঘর সরু হয়ে যেত, অথচ এখানেই
                 সবচেয়ে লম্বা লেখা বসে। --}}
            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                <x-ui.field name="name_en" :label="__('supplier::field.supplier_name_en')"
                            :value="old('name_en', $supplier->name_en)" required />

                <x-ui.field name="name_bn" :label="__('supplier::field.supplier_name_bn')"
                            :value="old('name_bn', $supplier->name_bn)"
                            :required="$requireBangla"
                            :hint="__('supplier::message.bn_name_hint')" />
            </div>
        </section>

        {{-- ── সারি ৩ — ঠিকানা ────────────────────────────────────────
             মালিকের ক্রম, ১৫ সেপ্টেম্বর ২০২৬: ঠিকানা যোগাযোগের **আগে**।

             ⓘ যুক্তিটা কাগজের ক্রম: পরিচয়ের পর প্রশ্ন আসে "কোথায়",
             তারপর "কীভাবে ধরব"। ⚠️ আগে উল্টো ছিল।

             ⭐ শাখার ঘরটা এখান থেকে উপরে গেছে — ওটা সরবরাহকারীর ঠিকানা
             নয়, আমাদের খাতার ভাগ। --}}
        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="mb-3 font-semibold">{{ __('supplier::section.address') }}</h2>

            <div class="grid gap-3 sm:grid-cols-2">
                <x-ui.field name="address_en" :label="__('supplier::field.address_en')"
                            :value="old('address_en', $supplier->address_en)" />
                <x-ui.field name="address_bn" :label="__('supplier::field.address_bn')"
                            :value="old('address_bn', $supplier->address_bn)" />
            </div>
        </section>

        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="mb-1 font-semibold">{{ __('supplier::section.contact') }}</h2>
            <p class="mb-3 max-w-(--spacing-prose-max) text-sm text-(--color-ink-muted)">
                {{ __('supplier::message.contact_hint') }}
            </p>

            <div class="grid gap-3 sm:grid-cols-2">
                {{-- মোবাইলে সঠিক কী-বোর্ড আসার জন্য type ঠিক দিতে হয়
                     (সেকশন ২০.৫)। --}}
                <x-ui.field name="phone" type="tel" :label="__('supplier::field.phone')"
                            :value="old('phone', $supplier->phone)" />

                <x-ui.field name="email" type="email" :label="__('supplier::field.email')"
                            :value="old('email', $supplier->email)" />

                <x-ui.field name="contact_person" :label="__('supplier::field.contact_person')"
                            :value="old('contact_person', $supplier->contact_person)" />

                <x-ui.field name="contact_phone" type="tel" :label="__('supplier::field.contact_phone')"
                            :value="old('contact_phone', $supplier->contact_phone)" />
            </div>
        </section>

            </div>

            <div class="space-y-4">
        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="mb-1 font-semibold">{{ __('supplier::section.tax') }}</h2>
            <p class="mb-3 max-w-(--spacing-prose-max) text-sm text-(--color-ink-muted)">
                {{ __('supplier::message.bin_hint') }}
            </p>

            <div class="grid gap-3 sm:grid-cols-2">
                <x-ui.field name="bin" :label="__('supplier::field.bin')"
                            :value="old('bin', $supplier->bin)"
                            :required="$requireBin" />

                <x-ui.field name="tin" :label="__('supplier::field.tin')"
                            :value="old('tin', $supplier->tin)" />
            </div>
        </section>

        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="mb-3 font-semibold">{{ __('supplier::section.credit') }}</h2>

            {{-- ⭐ তিনটাই এক সারিতে — মালিকের নির্দেশ, ১৬ সেপ্টেম্বর ২০২৬।

                 ⓘ তিনটা একই প্রশ্নের তিন অংশ: **কখন দিতে হবে** (শর্ত),
                 **কতদিনে** (মেয়াদ), আর **কত পর্যন্ত** (সীমা)। এক সারিতে
                 থাকলে বাকির গোটা নিয়মটা এক নজরে পড়া যায়।

                 ⚠️ দুই কলামে থাকায় সীমার ঘরটা একা নিচে নেমে যেত, আর
                 দেখতে লাগত যেন ওটা আলাদা কোনো বিষয়। --}}
            <div class="grid gap-3 sm:grid-cols-3">
                {{-- শর্ত থাকলে সেটাই শেষ তারিখ ঠিক করে; না থাকলে
                     বাকীর মেয়াদ। দুইটাই রাখা আছে, কারণ ছোট সরবরাহকারীর
                     জন্য আলাদা শর্ত খোলার মানে হয় না। --}}
                <x-ui.select name="payment_term_id" :label="__('supplier::field.payment_term')"
                             :options="$paymentTerms->mapWithKeys(fn ($t) => [$t->id => $t->name()])"
                             :selected="$supplier->payment_term_id"
                             placeholder="—" />

                <x-ui.field name="credit_days" type="number" inputmode="numeric"
                            :label="__('supplier::field.credit_days')"
                            :value="old('credit_days', $supplier->credit_days)" numeric />

                {{-- inputmode="decimal" — টাকার ঘরে ফোনে সংখ্যার কী-বোর্ড --}}
                <x-ui.field name="credit_limit" type="number" step="0.01" inputmode="decimal"
                            :label="__('supplier::field.credit_limit')"
                            :value="old('credit_limit', $supplier->credit_limit)"
                            :hint="__('supplier::message.credit_limit_hint')" numeric />
            </div>
        </section>

        @if ($isNew)
            <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                <h2 class="mb-1 font-semibold">{{ __('supplier::section.opening') }}</h2>
                {{-- খোলা ব্যালেন্স শুধু তৈরির সময়: পরে বদলালে লেজার ও
                     তালিকা দুই রকম বলত। বদলাতে হলে জাবেদা ভাউচার। --}}
                <p class="mb-3 max-w-(--spacing-prose-max) text-sm text-(--color-ink-muted)">
                    {{ __('supplier::message.opening_note') }}
                </p>

                <div class="grid gap-3 sm:grid-cols-2">
                    <x-ui.field name="opening_balance" type="number" step="0.01" inputmode="decimal"
                                :label="__('supplier::field.opening_balance')"
                                :value="old('opening_balance', 0)" numeric />
                    <x-ui.field name="opening_date" type="date"
                                :label="__('supplier::field.opening_date')"
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
                         :href="$isNew ? route('supplier.index') : route('supplier.show', $supplier)">
                {{ __('core.action.cancel') }}
            </x-ui.button>
        </div>
            </div>
        </div>
    </form>
</x-layouts.app>
