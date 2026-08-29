{{--
    উত্তোলন — কে কত নিলেন, আর মাসে কতটা নিতে পারবেন।

    ── কেন উপরে "কে কোথায় দাঁড়িয়ে", তালিকা নিচে ────────────────────────
    সারির তালিকাটা ইতিহাস; কেউ রোজ পড়ে না। রোজ জানার দরকার একটাই জিনিস:
    **এই মাসে কার সীমায় কতটা বাকি** — কারণ ওটা না জানলে উত্তোলন লিখতে
    গিয়ে আটকাতে হয়, আর তখন কেউ ভাবে জিনিসটা নষ্ট।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('finance::menu.withdrawal') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('finance::menu.withdrawal')"
                          :subtitle="__('finance::message.withdrawal_note')" />
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

    {{-- ── কোন মাস ───────────────────────────────────────────────────
         সীমা মাসের হিসাব, তাই পর্দাটাও মাসের। --}}
    <form method="GET" class="mb-4 flex flex-wrap items-end gap-3 print-hide">
        <x-ui.field name="month" type="month" :label="__('finance::field.month')" :value="$month" />
        <x-ui.button type="submit" tone="secondary">{{ __('core.action.apply') }}</x-ui.button>
    </form>

    {{-- ── কে কোথায় দাঁড়িয়ে ─────────────────────────────────────────── --}}
    <section data-boxed class="mb-4 overflow-hidden rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card)">
        <h2 class="border-b border-(--color-border) bg-(--color-section-head) px-4 py-3 font-semibold">
            {{ __('finance::field.where_each_stands') }}
        </h2>

        <x-ui.table
            :empty="__('finance::message.no_withdrawal_yet')"
            :rows="$standing"
            :columns="[
                ['key' => 'name', 'label' => __('finance::field.who'),
                 'render' => fn ($r) => $r['name']],
                ['key' => 'cap', 'label' => __('finance::field.monthly_cap'), 'numeric' => true,
                 'width' => '10rem',
                 'render' => fn ($r) => $r['cap'] === null
                     ? __('finance::field.no_cap')
                     : \App\Core\Support\Money::format($r['cap'])],
                ['key' => 'this_month', 'label' => __('finance::field.taken_this_month'),
                 'numeric' => true, 'width' => '11rem',
                 'render' => fn ($r) => \App\Core\Support\Money::format($r['this_month'])],
                ['key' => 'left', 'label' => __('finance::field.cap_left'), 'numeric' => true,
                 'width' => '11rem',
                 'render' => fn ($r) => view('finance::withdrawal.partials.left', ['row' => $r])],
                ['key' => 'taken_all', 'label' => __('finance::field.taken_all'), 'numeric' => true,
                 'width' => '11rem',
                 'render' => fn ($r) => \App\Core\Support\Money::format($r['taken_all'])],
            ]" />
    </section>

    <div class="mb-4 grid gap-4 lg:grid-cols-2">
        {{-- ── নতুন উত্তোলন ──────────────────────────────────────────── --}}
        <section data-boxed class="rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card) p-4">
            <h2 class="mb-1 font-semibold">{{ __('finance::field.record_a_withdrawal') }}</h2>

            <p class="mb-3 text-2xs text-(--color-ink-muted)">
                {{ __('finance::message.withdrawal_is_not_an_expense') }}
            </p>

            <form method="POST" action="{{ route('finance.withdrawal.store') }}"
                  class="grid gap-3 sm:grid-cols-2">
                @csrf

                <x-ui.field name="contributor_name" :label="__('finance::field.who')" required
                            :value="old('contributor_name')" />

                <x-ui.field name="amount" type="number" step="0.01" numeric required
                            :label="__('finance::field.amount')" :value="old('amount')" />

                <x-ui.field name="trx_date" type="date" :label="__('finance::field.date')" required
                            :value="old('trx_date', now()->toDateString())" />

                <div class="flex items-end">
                    <x-ui.button type="submit" tone="primary" class="w-full">
                        {{ __('core.action.save') }}
                    </x-ui.button>
                </div>

                <div class="sm:col-span-2">
                    <x-ui.field name="reason" :label="__('finance::field.why')" :value="old('reason')" />
                </div>
            </form>
        </section>

        {{-- ── মাসিক সীমা ────────────────────────────────────────────
             একই পর্দায়, ইচ্ছাকৃতভাবে: সীমা পেরোলে সেবাটা আটকায়, আর
             বদলানোর ঘরটা অন্য পাতায় থাকলে ব্যবহারকারী খুঁজতে যেতেন না
             — তাঁরা ধরে নিতেন জিনিসটা নষ্ট। --}}
        @if (auth()->user()?->can('finance.withdrawal.cap'))
            <section data-boxed class="rounded-(--radius-card) border border-(--color-border)
                            bg-(--color-surface-card) p-4">
                <h2 class="mb-1 font-semibold">{{ __('finance::field.set_a_cap') }}</h2>

                <p class="mb-3 text-2xs text-(--color-ink-muted)">
                    {{ __('finance::message.cap_can_be_changed_here') }}
                </p>

                <form method="POST" action="{{ route('finance.withdrawal.cap') }}"
                      class="grid gap-3 sm:grid-cols-2">
                    @csrf

                    <x-ui.field name="contributor_name" :label="__('finance::field.who')" required
                                errorKey="contributor_name" />

                    <x-ui.field name="monthly_cap" type="number" step="0.01" numeric
                                :label="__('finance::field.monthly_cap')"
                                :hint="__('finance::field.no_cap')" />

                    <div class="flex items-end sm:col-span-2">
                        <x-ui.button type="submit" tone="secondary" class="w-full">
                            {{ __('core.action.save') }}
                        </x-ui.button>
                    </div>
                </form>
            </section>
        @endif
    </div>

    {{-- ── যা যা তোলা হয়েছে ──────────────────────────────────────────
         প্রতিটা সংখ্যা তার ভাউচারে নামায় — নিয়ম ১। --}}
    <section data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card)">
        <h2 class="border-b border-(--color-border) bg-(--color-section-head) px-4 py-3 font-semibold">
            {{ __('finance::field.withdrawals_list') }}
        </h2>

        <x-ui.table
            :empty="__('finance::message.no_withdrawal_yet')"
            :rows="$rows"
            :columns="[
                ['key' => 'trx_date', 'label' => __('finance::field.date'), 'width' => '9rem',
                 'render' => fn ($w) => \App\Core\Support\DateFormat::format($w->trx_date)],
                ['key' => 'document_no', 'label' => __('core.print.document_no'), 'width' => '10rem'],
                ['key' => 'contributor_name', 'label' => __('finance::field.who')],
                ['key' => 'reason', 'label' => __('finance::field.why'),
                 'render' => fn ($w) => $w->reason ?: '—'],
                ['key' => 'amount', 'label' => __('finance::field.amount'), 'numeric' => true,
                 'width' => '11rem',
                 'render' => fn ($w) => \App\Core\Support\Money::format($w->amount)],
                ['key' => 'state', 'label' => __('finance::field.state'), 'width' => '16rem',
                 'render' => fn ($w) => view('finance::withdrawal.partials.state',
                     ['row' => $w, 'accounts' => $accounts])],
            ]" />

        <x-ui.pager :rows="$rows" />
    </section>
</x-layouts.app>
