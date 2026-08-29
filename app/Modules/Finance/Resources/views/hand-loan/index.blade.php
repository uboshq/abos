{{--
    হাতধার — কে আমার কাছে পায়, আর আমি কার কাছে পাই।

    ── কেন একটাই তালিকা, দুইটা নয় ──────────────────────────────────────
    "পাওনা" আর "দেনা" আলাদা দুইটা পর্দা হলে কেউ ওদের মিলিয়ে দেখত না, আর
    একই মানুষ দুই তালিকায় থাকতে পারতেন — একদিকে পাঁচ হাজার পাওনা,
    অন্যদিকে তিন হাজার দেনা, অথচ সত্যিটা দুই হাজার। চিহ্নটাই ভাগ করে
    দেয়, আর যোগফল দুইটা উপরে আলাদা করে বলা থাকে।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('finance::menu.hand_loan') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('finance::menu.hand_loan')"
                          :subtitle="__('finance::message.hand_loan_note')" />
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

    {{-- ── দুই দিকের যোগফল ──────────────────────────────────────────── --}}
    <section data-boxed class="mb-4 grid gap-3 sm:grid-cols-3">
        @foreach ([
            ['finance::field.owed_to_us', \App\Core\Support\Money::format($standing['owed_to_us'])],
            ['finance::field.we_owe', \App\Core\Support\Money::format($standing['we_owe'])],
            ['finance::field.how_many_people', count($standing['rows'])],
        ] as [$label, $value])
            <div class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                <p class="text-2xs text-(--color-ink-muted)">{{ __($label) }}</p>
                <p class="mt-1 text-lg font-semibold tabular-nums">{{ $value }}</p>
            </div>
        @endforeach
    </section>

    {{-- ── নতুন মানুষ ────────────────────────────────────────────────
         নাম আর একটা নম্বর, ব্যস। এদের বেশিরভাগ গ্রাহকও নন, সরবরাহকারীও
         নন — আগে একটা পক্ষ-রেকর্ড বানাতে বললে ফিচারটা কেউ ব্যবহার করত না। --}}
    <section data-boxed class="mb-4 rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card) p-4">
        <h2 class="mb-1 font-semibold">{{ __('finance::field.open_a_hand_loan') }}</h2>

        <p class="mb-3 text-2xs text-(--color-ink-muted)">
            {{ __('finance::message.hand_loan_is_not_a_loan') }}
        </p>

        <form method="POST" action="{{ route('finance.hand_loan.store') }}"
              class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            @csrf

            <x-ui.field name="person_name" :label="__('finance::field.person_name')" required
                        :value="old('person_name')" />

            <x-ui.field name="mobile" :label="__('finance::field.mobile')" :value="old('mobile')" />

            <x-ui.field name="note" :label="__('finance::field.note')" :value="old('note')" />

            <div class="flex items-end">
                <x-ui.button type="submit" tone="primary" class="w-full">
                    {{ __('core.action.save') }}
                </x-ui.button>
            </div>
        </form>
    </section>

    {{-- ── কে কোথায় দাঁড়িয়ে ─────────────────────────────────────────── --}}
    <section data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card)">
        <h2 class="border-b border-(--color-border) bg-(--color-section-head) px-4 py-3 font-semibold">
            {{ __('finance::field.where_each_stands') }}
        </h2>

        <x-ui.table
            :empty="__('finance::message.no_hand_loan_yet')"
            :rows="$standing['rows']"
            :columns="[
                ['key' => 'person', 'label' => __('finance::field.person_name'),
                 'render' => fn ($r) => view('finance::hand-loan.partials.person', ['row' => $r])],
                ['key' => 'movements', 'label' => __('finance::field.movements_count'),
                 'numeric' => true, 'width' => '7rem',
                 'render' => fn ($r) => $r['movements']],
                ['key' => 'balance', 'label' => __('finance::field.hand_loan_balance'),
                 'numeric' => true, 'width' => '13rem',
                 'render' => fn ($r) => view('finance::hand-loan.partials.balance', ['row' => $r])],
                ['key' => 'do', 'label' => '', 'width' => '7rem',
                 'render' => fn ($r) => view('finance::hand-loan.partials.open-it', ['row' => $r])],
            ]" />
    </section>
</x-layouts.app>
