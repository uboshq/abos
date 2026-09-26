{{--
    নতুন অফার — স্পেকের §৬ ধাপ ১ (মৌলিক তথ্য)।

    ⓘ শর্ত আর সুবিধা এখানে নেই — ওগুলো অফারটা বানানোর পরের ধাপ
    (§৬ ধাপ ৩ ও ৪)। ⚠️ এক পাতায় সব রাখলে মানুষ শর্ত বসিয়ে সংরক্ষণ
    চাপতেন আর তারিখের একটা ভুলে পুরো পাতাটা হারাতেন।

    ⛔ এই পাতায় কোনো `@php` ব্লক নেই, আর সেটা ইচ্ছাকৃত: তালিকার পাতায়
    `@php`-র ভিতরে একটা Blade মন্তব্য গোটা পাতাটা ৫০০ করেছিল।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('promotion::action.new') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('promotion::action.new')" />
    </x-slot:header>

    <x-ui.errors />

    <form method="POST" action="{{ route('promotion.store') }}" class="max-w-2xl space-y-4">
        @csrf

        <div data-boxed class="space-y-4 rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card) p-4">

            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.field name="name_bn" :label="__('promotion::field.name_bn')"
                            :value="old('name_bn')" maxlength="255" />

                <x-ui.field name="name_en" :label="__('promotion::field.name_en')"
                            :value="old('name_en')" required maxlength="255" />
            </div>

            <x-ui.field name="summary" :label="__('promotion::field.summary')"
                        :value="old('summary')" maxlength="255" />

            {{--
                ⭐ ধরন — কেবল যেগুলো ইঞ্জিন সত্যিই চেনে।

                ⓘ স্পেকে বারোটা, আজ চলে তিনটা। ⛔ বাকি ন'টা এখানে দিলে
                অফার তৈরি হত, "চলছে" দেখাত, আর বিলে কিছুই করত না। ⚠️ আর
                ফর্ম বদলে পাঠালেও সেবা থামায় — এই তালিকাটা একমাত্র পাহারা নয়।
            --}}
            <x-ui.select name="type" :label="__('promotion::field.type')"
                         :options="collect($types)->mapWithKeys(fn ($t) => [$t->value => $t->label()])"
                         :value="old('type')" required />

            {{--
                ⭐ একাধিক অফার একসাথে — মালিকের সিদ্ধান্ত, ২৬ সেপ্টেম্বর।

                ⓘ "offer ghosonar somoyei tik korbe"। খালি রাখলে কোম্পানির
                শুরুর মানটা বসে — ⚠️ কিন্তু বসে এখনই, এই অফারের নিজের ঘরে।
                পরে সুইচ বদলালে এই অফারের আচরণ বদলায় না।
            --}}
            <x-ui.select name="combines" :label="__('promotion::field.combines')"
                         :options="collect($combines)->mapWithKeys(fn ($c) => [$c->value => $c->label()])"
                         placeholder="—" :value="old('combines')" />

            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.field name="starts_on" type="date" :label="__('promotion::field.starts_on')"
                            :value="old('starts_on')" required />

                <x-ui.field name="ends_on" type="date" :label="__('promotion::field.ends_on')"
                            :value="old('ends_on')" required />
            </div>

            {{--
                ⚠️ সময় — প্রথম দিনের শুরু আর শেষ দিনের শেষ, রোজকার নয়।

                ⓘ "৩০ জুন সন্ধ্যা ৬টায় শেষ" মানে ঠিক ওটাই। ⛔ প্রথম খসড়ায়
                এই ঘর দুইটা ছিল অথচ ইঞ্জিন পড়ত না, আর অফারটা রাত ১২টা
                পর্যন্ত চলত। "রোজ দুপুর ১২টা–৩টা" আলাদা জিনিস — শর্তের ধাপে।
            --}}
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.field name="starts_at" type="time" :label="__('promotion::field.starts_at')"
                            :value="old('starts_at')" />

                <x-ui.field name="ends_at" type="time" :label="__('promotion::field.ends_at')"
                            :value="old('ends_at')" />
            </div>

            <x-ui.field name="priority" type="number" :label="__('promotion::field.priority')"
                        :value="old('priority', 0)" min="0" max="1000" />

            <label class="block">
                <span class="mb-1 block text-2xs uppercase tracking-wide text-(--color-ink-muted)">
                    {{ __('promotion::field.terms') }}
                </span>
                <textarea name="terms" rows="4"
                          class="w-full rounded-(--radius-field) border border-(--color-border)
                                 bg-(--color-surface-app) px-2 py-1.5 text-sm"
                >{{ old('terms') }}</textarea>
            </label>
        </div>

        <x-ui.button type="submit" tone="primary">{{ __('core.action.save') }}</x-ui.button>
    </form>
</x-layouts.app>
