{{--
    নতুন স্কিম — নিজের পাতায়।

    ধাপগুলো (কে কোন হারে পায়) এখানে নেই, আর ইচ্ছাকৃতভাবে: ওগুলো বসে
    স্কিমটা তৈরি হওয়ার পর, তার নিজের পাতায়। সেভ করামাত্র সেখানেই
    পৌঁছে দেওয়া হয়।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('sales::action.new_scheme') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('sales::action.new_scheme')"
                          :subtitle="__('sales::message.scheme_note')" />
    </x-slot:header>

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

    <section data-boxed class="rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card) p-4">
        <form method="POST" action="{{ route('sales.scheme.store') }}"
              x-data="{ appliesTo: '{{ old('applies_to', \App\Modules\Sales\Models\Scheme::ALL) }}' }"
              class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            @csrf

            <x-ui.field name="code" :label="__('sales::field.scheme_code')"
                        :placeholder="__('core.create.code_auto')"
                        :value="old('code')" />

            <x-ui.field name="name" :label="__('core.table.name')" required
                        :value="old('name')" />

            <x-ui.select name="basis" :label="__('sales::field.scheme_basis')" required
                         :options="collect([
                             \App\Modules\Sales\Models\Scheme::VALUE,
                             \App\Modules\Sales\Models\Scheme::VOLUME,
                             \App\Modules\Sales\Models\Scheme::SLAB,
                         ])->mapWithKeys(fn ($b) => [$b => __('sales::basis.' . $b)])"
                         :selected="old('basis', \App\Modules\Sales\Models\Scheme::VALUE)"
                         :hint="__('sales::message.scheme_basis_hint')" />

            <x-ui.select name="applies_to" :label="__('sales::field.scheme_applies_to')" required
                         x-model="appliesTo"
                         :options="collect([
                             \App\Modules\Sales\Models\Scheme::ALL,
                             \App\Modules\Sales\Models\Scheme::PRODUCT,
                             \App\Modules\Sales\Models\Scheme::CATEGORY,
                             \App\Modules\Sales\Models\Scheme::BRAND,
                             \App\Modules\Sales\Models\Scheme::TERRITORY,
                             \App\Modules\Sales\Models\Scheme::DEALER_TIER,
                         ])->mapWithKeys(fn ($a) => [$a => __('sales::applies.' . $a)])"
                         :selected="old('applies_to', \App\Modules\Sales\Models\Scheme::ALL)" />

            {{-- লক্ষ্যের ঘরটা একটাই, আর ভেতরের তালিকা বদলায়।

                 পাঁচটা ড্রপডাউন একসাথে দেখালে চারটা অপ্রাসঙ্গিক ঘর
                 প্রতিবার চোখের সামনে থাকত, আর কোনটা ভরতে হবে তা
                 বোঝা যেত না। --}}
            @foreach (\App\Modules\Sales\Http\Controllers\SchemeController::targets() as $kind => $options)
                <div x-cloak x-show="appliesTo === '{{ $kind }}'">
                    <x-ui.select name="target_id" :label="__('sales::applies.' . $kind)"
                                 :options="$options"
                                 :placeholder="__('sales::field.choose')"
                                 :selected="old('target_id')" />
                </div>
            @endforeach

            <x-ui.field name="valid_from" type="date" :label="__('sales::field.valid_from')" required
                        :value="old('valid_from', now()->toDateString())" />

            {{-- শেষ তারিখও আবশ্যক — খোলা রাখা স্কিম চিরকাল চলে, আর
                 দুই সপ্তাহের ঈদের অফার পরের বছরও টাকা দিতে থাকে। --}}
            <x-ui.field name="valid_to" type="date" :label="__('sales::field.valid_to')" required
                        :value="old('valid_to', now()->endOfMonth()->toDateString())" />

            <div class="sm:col-span-2 xl:col-span-2">
                <x-ui.field name="notes" :label="__('sales::field.notes')" :value="old('notes')" />
            </div>

            <div class="flex flex-wrap items-end gap-2 sm:col-span-2 xl:col-span-3">
                <x-ui.button type="submit" tone="primary">
                    {{ __('core.action.save') }}
                </x-ui.button>

                <x-ui.button tone="secondary" :href="route('sales.scheme.index')">
                    {{ __('core.action.cancel') }}
                </x-ui.button>
            </div>
        </form>
    </section>
</x-layouts.app>
