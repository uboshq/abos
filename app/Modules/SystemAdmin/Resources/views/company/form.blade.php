{{--
    কোম্পানি — খোলা ও সম্পাদনা।

    ── নতুন কোম্পানির পাতায় শাখা ও অর্থবছরও থাকে, আর সেটাই আসল কথা ────
    একটা কোম্পানি শুধু একটা নাম নয়। শাখা ছাড়া কোনো লেনদেন কোথায় বসবে
    তা বলা যায় না, আর অর্থবছর ছাড়া কোনো তারিখই বৈধ নয়। দুইটা পরে
    চাইলে মানুষ কোম্পানিটা খুলে চলে যেতেন, আর প্রথম বিল লিখতে গিয়ে
    আবিষ্কার করতেন কিছুই কাজ করছে না।

    তাই তিনটা একসাথে — একবারে ভরে দিলে কোম্পানিটা সত্যিই চালু হয়ে যায়।

    ── সম্পাদনায় ওই দুইটা থাকে না ─────────────────────────────────────
    অর্থবছর ততদিনে চালু, তার তারিখ বদলানো মানে বসে যাওয়া লেনদেনগুলোর
    ভিত নাড়ানো — সেটা Accounts-এর Year End পর্দার কাজ। শাখা যোগ করা
    যায়, নিচে।
--}}
@php
    $isNew = ! $company->exists;
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $isNew ? __('core.action.create') : $company->name() }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header
            :title="$isNew ? __('core.action.create') : $company->name()"
            :subtitle="__('system_admin::menu.companies')" />
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

    <form method="POST"
          action="{{ $isNew ? route('system_admin.company.store') : route('system_admin.company.update', $company->id) }}"
          class="space-y-4">
        @csrf
        @unless ($isNew) @method('PUT') @endunless

        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="mb-3 font-semibold">{{ __('master_data::section.identity') }}</h2>

            <div class="grid gap-3 sm:grid-cols-2">
                @if ($isNew)
                    {{-- কোডটা কেবল খোলার সময়। ছাপা কাগজে, রপ্তানি ফাইলে
                         আর ব্যাংকের বিবরণীতে ওটা বসে যায় — পরে বদলালে
                         পুরনো কাগজ আর নতুন খাতা দুইটা আলাদা প্রতিষ্ঠানের
                         মতো দেখাত। --}}
                    <x-ui.field name="code" :label="__('master_data::field.code')"
                                :value="old('code')"
                                :placeholder="__('core.create.code_auto')"
                                :hint="__('core.create.code_auto_hint')" />
                @endif

                <x-ui.field name="name_en" :label="__('master_data::field.name_en')"
                            :value="old('name_en', $company->name_en)" required />

                <x-ui.field name="name_bn" :label="__('master_data::field.name_bn')"
                            :value="old('name_bn', $company->name_bn)" />

                <x-ui.field name="legal_name" :label="__('system_admin::field.legal_name')"
                            :value="old('legal_name', $company->legal_name)" />

                <x-ui.field name="phone" :label="__('core.print.phone')"
                            :value="old('phone', $company->phone)" />

                <x-ui.field name="email" type="email" :label="__('system_admin::field.email')"
                            :value="old('email', $company->email)" />

                <x-ui.field name="bin" :label="__('system_admin::field.bin')"
                            :value="old('bin', $company->bin)" />

                <x-ui.field name="tin" :label="__('system_admin::field.tin')"
                            :value="old('tin', $company->tin)" />
            </div>

            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                <x-ui.field name="address_en" :label="__('system_admin::field.address_en')"
                            :value="old('address_en', $company->address_en)" />

                <x-ui.field name="address_bn" :label="__('system_admin::field.address_bn')"
                            :value="old('address_bn', $company->address_bn)" />
            </div>
        </section>

        @if ($isNew)
            <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                <h2 class="mb-1 font-semibold">{{ __('system_admin::field.main_branch') }}</h2>
                <p class="mb-3 max-w-(--spacing-prose-max) text-sm text-(--color-ink-muted)">
                    {{ __('system_admin::message.main_branch_note') }}
                </p>

                <div class="grid gap-3 sm:grid-cols-3">
                    <x-ui.field name="branch_code" :label="__('master_data::field.code')"
                                :value="old('branch_code', 'MAIN')" required />

                    <x-ui.field name="branch_name_en" :label="__('master_data::field.name_en')"
                                :value="old('branch_name_en')" required />

                    <x-ui.field name="branch_name_bn" :label="__('master_data::field.name_bn')"
                                :value="old('branch_name_bn')" />
                </div>
            </section>

            <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                <h2 class="mb-1 font-semibold">{{ __('system_admin::field.financial_year') }}</h2>
                <p class="mb-3 max-w-(--spacing-prose-max) text-sm text-(--color-ink-muted)">
                    {{ __('system_admin::message.financial_year_note') }}
                </p>

                <div class="grid gap-3 sm:grid-cols-3">
                    <x-ui.field name="year_name" :label="__('master_data::field.name')"
                                :value="old('year_name', $year['name'])" required />

                    <x-ui.field name="year_starts_on" type="date" :label="__('core.table.from_date')"
                                :value="old('year_starts_on', $year['starts_on'])" required />

                    <x-ui.field name="year_ends_on" type="date" :label="__('core.table.to_date')"
                                :value="old('year_ends_on', $year['ends_on'])" required />
                </div>
            </section>
        @endif

        <div class="flex flex-wrap gap-2">
            <x-ui.button type="submit" tone="primary">{{ __('core.action.save') }}</x-ui.button>
            <x-ui.button tone="secondary" :href="route('system_admin.company.index')">
                {{ __('core.action.cancel') }}
            </x-ui.button>
        </div>
    </form>

    @unless ($isNew)
        {{-- শাখাগুলো কোম্পানির পাতাতেই — আলাদা পাতায় রাখলে প্রথম
             প্রশ্নটাই হত "কোন কোম্পানির শাখা?" --}}
        <section data-boxed class="mt-4 rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card) p-4">
            <h2 class="mb-3 font-semibold">{{ __('system_admin::menu.branches') }}</h2>

            <ul class="mb-4 divide-y divide-(--color-border) text-sm">
                @foreach ($branches as $branch)
                    <li class="flex items-center justify-between py-2">
                        <span>
                            <span class="font-medium">{{ $branch->code }}</span>
                            <span class="text-(--color-ink-muted)"> — {{ $branch->name() }}</span>
                        </span>

                        @if ($branch->is_default)
                            <x-ui.badge tone="info">{{ __('master_data::field.is_default') }}</x-ui.badge>
                        @endif
                    </li>
                @endforeach
            </ul>

            <form method="POST" action="{{ route('system_admin.company.branch.store', $company->id) }}"
                  class="grid gap-3 sm:grid-cols-4">
                @csrf

                <x-ui.field name="code" :label="__('master_data::field.code')" required />
                <x-ui.field name="name_en" :label="__('master_data::field.name_en')" required />
                <x-ui.field name="name_bn" :label="__('master_data::field.name_bn')" />

                <div class="flex items-end">
                    <x-ui.button type="submit" tone="secondary">
                        {{ __('core.action.create') }}
                    </x-ui.button>
                </div>
            </form>
        </section>
    @endunless
</x-layouts.app>
