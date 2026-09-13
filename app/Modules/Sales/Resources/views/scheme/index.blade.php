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

    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                bg-(--color-surface-card)">
        <form method="GET" class="contents">
            <x-ui.toolbar :title="__('sales::menu.schemes')"
                          :columns="$columns"
                          :search-placeholder="__('sales::field.scheme_search')"
                          :sort="$sortOptions">
                {{-- খালি পড়ে থাকা actions স্লটটাই এখন বসানোর পথ — শর্তটা
                     হুবহু সেটাই যেটায় আগে উপরের ফর্মটা দেখা যেত। --}}
                <x-slot:actions>
                    @can('sales.scheme.manage')
                        <x-ui.button tone="primary" icon="plus" :href="route('sales.scheme.create')">
                            {{ __('sales::action.new_scheme') }}
                        </x-ui.button>
                    @endcan
                </x-slot:actions>

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
