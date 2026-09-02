{{--
    স্কিমের তালিকা — কোন নিয়মে কে কত পায়।

    ── কেন তালিকায় ধাপের সংখ্যা দেখানো হয় ──────────────────────────────
    একটা স্কিম চালু আছে অথচ তার কোনো ধাপ নেই — সেটা দেখতে পুরোপুরি
    স্বাভাবিক লাগে, আর কিছুই দেয় না। সংখ্যাটা সারিতে থাকলে শূন্যটা
    চোখে পড়ে।

    ── আর মেয়াদ পেরোনোটাও ──────────────────────────────────────────────
    হিসাবে ভুল হয় না ([[Scheme::isLiveOn()]] তারিখ দেখে), কিন্তু সারিটা
    "চালু" লেখা থাকলে কেউ ধরে নেন স্কিমটা চলছে — তারপর গ্রাহককে সেই কথা
    দিয়ে বসেন।
--}}
@php
    $columns = [
        ['key' => 'code', 'label' => __('sales::field.scheme_code'), 'width' => '9rem',
         'render' => fn ($s) => new \Illuminate\Support\HtmlString(
             '<a class="text-(--color-link) hover:underline" href="'
             . e(route('sales.scheme.show', $s)) . '">' . e($s->code) . '</a>')],
        ['key' => 'name', 'label' => __('core.table.name'),
         'render' => fn ($s) => $s->name],
        ['key' => 'basis', 'label' => __('sales::field.scheme_basis'), 'width' => '8rem',
         'render' => fn ($s) => __('sales::basis.' . $s->basis)],
        ['key' => 'applies_to', 'label' => __('sales::field.scheme_applies_to'), 'width' => '10rem',
         'render' => fn ($s) => __('sales::applies.' . $s->applies_to)],
        ['key' => 'valid', 'label' => __('sales::field.scheme_valid'), 'width' => '13rem',
         'render' => fn ($s) => \App\Core\Support\DateFormat::format($s->valid_from)
             . ' — ' . \App\Core\Support\DateFormat::format($s->valid_to)],
        ['key' => 'rules_count', 'label' => __('sales::field.scheme_bands'),
         'numeric' => true, 'width' => '7rem',
         'render' => fn ($s) => $s->rules_count],
        ['key' => 'status', 'label' => __('accounts::field.state'), 'width' => '11rem',
         'render' => fn ($s) => view('sales::scheme.partials.state', ['scheme' => $s])],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('sales::menu.schemes') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('sales::menu.schemes')"
                          :subtitle="__('sales::message.scheme_note')" />
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

    @can('sales.scheme.manage')
        {{-- নতুন স্কিম — উপরে, কারণ ধাপগুলো বসানো হয় স্কিমটা তৈরি
             হওয়ার পর, তার নিজের পাতায়। --}}
        <section data-boxed class="mb-5 rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card) p-4">
            <h2 class="mb-3 font-semibold">{{ __('sales::action.new_scheme') }}</h2>

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

                <div class="flex items-end">
                    <x-ui.button type="submit" tone="primary" class="w-full">
                        {{ __('core.action.save') }}
                    </x-ui.button>
                </div>
            </form>
        </section>
    @endcan

    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                bg-(--color-surface-card)">
        <form method="GET" class="contents">
            <x-ui.toolbar :title="__('sales::menu.schemes')"
                          :columns="$columns"
                          :search-placeholder="__('sales::field.scheme_search')"
                          :sort="$sortOptions">
                {{-- অবস্থা ধরে ছাঁকনি — খসড়াগুলো আলাদা করে দেখা লাগে,
                     কারণ ওগুলোই এখনো কিছু দেয় না। --}}
                <label class="flex min-h-(--spacing-touch) items-center gap-2 text-sm">
                    <select name="status"
                            class="h-(--spacing-field) rounded-(--radius-field) border
                                   border-(--color-border) bg-(--color-surface-card) px-3">
                        <option value="">{{ __('sales::scheme_state.all') }}</option>
                        @foreach ([
                            \App\Modules\Sales\Models\Scheme::DRAFT,
                            \App\Modules\Sales\Models\Scheme::ACTIVE,
                            \App\Modules\Sales\Models\Scheme::CANCELLED,
                        ] as $state)
                            <option value="{{ $state }}" @selected($status === $state)>
                                {{ __('sales::scheme_state.' . $state) }}
                            </option>
                        @endforeach
                    </select>
                </label>
            </x-ui.toolbar>
        </form>

        <x-ui.table :rows="$schemes" :columns="$columns"
                    :empty="$q ? __('core.empty.no_results') : __('sales::message.no_scheme')" />
    </div>

    {{ $schemes->links() }}
</x-layouts.app>
