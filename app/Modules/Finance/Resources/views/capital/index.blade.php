{{--
    মূলধন ও বিনিয়োগ — ব্যবসার প্রথম পাতা।

    ── কেন উপরে "কে কোথায় দাঁড়িয়ে" ────────────────────────────────────
    সারির তালিকাটা ইতিহাস; ওটা কেউ রোজ পড়ে না। যেটা রোজ জানার দরকার তা
    হলো কার কত জমা আছে — বিশেষ করে অংশীদারি ব্যবসায়, যেখানে ওই সংখ্যাটা
    নিয়েই ঝগড়া হয়।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('finance::menu.capital') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('finance::menu.capital')"
                          :subtitle="__('finance::message.capital_note')" />
    </x-slot:header>

    @if (session('saved'))
        <p role="alert" class="mb-3 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2
                               text-sm text-(--color-badge-success-ink)">{{ session('saved') }}</p>
    @endif

    @if ($errors->any())
        <div role="alert" class="mb-3 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2
                                 text-sm text-(--color-badge-danger-ink)">
            <ul class="list-inside list-disc">
                @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    {{-- ── কে কোথায় দাঁড়িয়ে ─────────────────────────────────────────── --}}
    <section data-boxed class="mb-4 overflow-hidden rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card)">
        <h2 class="border-b border-(--color-border) bg-(--color-section-head) px-4 py-3 font-semibold">
            {{ __('finance::field.where_each_stands') }}
        </h2>

        <x-ui.table
            :empty="__('finance::message.no_capital_yet')"
            :rows="$positions"
            :columns="[
                ['key' => 'name', 'label' => __('finance::field.who'),
                 'render' => fn ($p) => $p['name']],
                ['key' => 'type', 'label' => __('finance::field.as'),
                 'render' => fn ($p) => __('finance::who.'.$p['type'])],
                ['key' => 'contributed', 'label' => __('finance::field.put_in'), 'numeric' => true,
                 'render' => fn ($p) => \App\Core\Support\Money::format($p['contributed'])],
                ['key' => 'withdrawn', 'label' => __('finance::field.taken_out'), 'numeric' => true,
                 'render' => fn ($p) => \App\Core\Support\Money::format($p['withdrawn'])],
                ['key' => 'net', 'label' => __('finance::field.stands_at'), 'numeric' => true,
                 'render' => fn ($p) => \App\Core\Support\Money::format($p['net'])],
                ['key' => 'share', 'label' => __('finance::field.share'), 'numeric' => true,
                 'render' => fn ($p) => $p['share'] === null ? '—' : rtrim(rtrim($p['share'], '0'), '.').'%'],
            ]" />
    </section>

    {{-- ── নতুন সারি ──────────────────────────────────────────────────
         ছয়টা ঘর একটা সারিতে: এটা একটা ফর্ম নয়, একটা লাইন লেখা। --}}
    <section data-boxed class="mb-4 rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card) p-4">
        <h2 class="mb-3 font-semibold">{{ __('finance::field.record_a_contribution') }}</h2>

        <form method="POST" action="{{ route('finance.capital.store') }}"
              class="grid gap-3 sm:grid-cols-3 xl:grid-cols-6">
            @csrf

            <x-ui.field name="contributor_name" :label="__('finance::field.who')" required
                        :value="old('contributor_name')" />

            <x-ui.select name="contributor_type" :label="__('finance::field.as')" required
                         :options="collect(\App\Modules\Finance\Models\CapitalEntry::WHO)
                             ->mapWithKeys(fn ($w) => [$w => __('finance::who.'.$w)])"
                         :selected="old('contributor_type', 'owner')" />

            <x-ui.select name="entry_type" :label="__('finance::field.kind')" required
                         :options="collect(\App\Modules\Finance\Models\CapitalEntry::KINDS)
                             ->mapWithKeys(fn ($k) => [$k => __('finance::kind.'.$k)])"
                         :selected="old('entry_type', 'contribution')" />

            <x-ui.field name="trx_date" type="date" :label="__('finance::field.date')" required
                        :value="old('trx_date', now()->toDateString())" />

            <x-ui.field name="amount" type="number" step="0.01" numeric required
                        :label="__('finance::field.amount')" :value="old('amount')" />

            <x-ui.field name="share_percent" type="number" step="0.01" numeric
                        :label="__('finance::field.share')" :value="old('share_percent')" />

            <div class="sm:col-span-2 xl:col-span-5">
                <x-ui.field name="narration" :label="__('finance::field.what_for')"
                            :value="old('narration')" />
            </div>

            <div class="flex items-end">
                <x-ui.button type="submit" tone="primary" class="w-full">
                    {{ __('core.action.save') }}
                </x-ui.button>
            </div>
        </form>

        <p class="mt-2 text-2xs text-(--color-ink-muted)">
            {{ __('finance::message.recorded_then_posted') }}
        </p>
    </section>

    {{-- ── সারিগুলো ─────────────────────────────────────────────────── --}}
    <section data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card)">
        <h2 class="border-b border-(--color-border) bg-(--color-section-head) px-4 py-3 font-semibold">
            {{ __('finance::field.contributions') }}
        </h2>

        <x-ui.table
            :empty="__('finance::message.no_capital_yet')"
            :rows="$entries"
            :columns="[
                ['key' => 'trx_date', 'label' => __('finance::field.date'), 'width' => '8rem',
                 'render' => fn ($e) => \App\Core\Support\DateFormat::format($e->trx_date)],
                ['key' => 'document_no', 'label' => __('core.print.document_no'), 'width' => '9rem'],
                ['key' => 'contributor_name', 'label' => __('finance::field.who')],
                ['key' => 'entry_type', 'label' => __('finance::field.kind'), 'width' => '8rem',
                 'render' => fn ($e) => __('finance::kind.'.$e->entry_type)],
                ['key' => 'amount', 'label' => __('finance::field.amount'), 'numeric' => true, 'width' => '10rem',
                 'render' => fn ($e) => \App\Core\Support\Money::format($e->amount)],
                ['key' => 'status', 'label' => __('finance::field.state'), 'width' => '15rem',
                 'render' => fn ($e) => view('finance::capital.partials.state',
                     ['entry' => $e, 'accounts' => $accounts])],
            ]" />

        <x-ui.pager :rows="$entries" />
    </section>
</x-layouts.app>
