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
        {{-- ⭐ "+ নতুন" উপরে, বাকি পর্দাগুলোর মতোই।

             ⛔ ফর্মটা আগে তালিকার মাঝখানে গোঁজা ছিল, আর মালিক ধরেছেন
             যে বাকি পর্দায় উপরে বোতাম থাকে। ⓘ এক রকম না হলে মানুষ
             প্রতিটা পর্দায় নতুন করে খোঁজেন কোথায় কী। --}}
        <x-ui.page-header :title="__('finance::menu.capital')"
                          :subtitle="__('finance::message.capital_note')">
            <x-slot:actions>
                @can('finance.capital.create')
                    <x-ui.button tone="primary" icon="plus" :href="route('finance.capital.create')">
                        {{ __('finance::action.new_contribution') }}
                    </x-ui.button>
                @endcan
            </x-slot:actions>
        </x-ui.page-header>
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
                ['key' => 'person', 'label' => __('finance::field.who'),
                 'render' => fn ($e) => $e->person?->name() ?? '—'],
                ['key' => 'entry_type', 'label' => __('finance::field.kind'), 'width' => '8rem',
                 'render' => fn ($e) => __('finance::kind.'.$e->entry_type)],
                ['key' => 'amount', 'label' => __('finance::field.amount'), 'numeric' => true, 'width' => '10rem',
                 'render' => fn ($e) => \App\Core\Support\Money::format($e->amount)],
                /* ⚠️ চওড়া, কারণ ভিতরে খাতের ঘর, নম্বরের ঘর আর বোতাম —
                   তিনটা। সরু রাখলে লেখাগুলো লম্বালম্বি ভেঙে যায়। */
                ['key' => 'status', 'label' => __('finance::field.state'), 'width' => '22rem',
                 'render' => fn ($e) => view('finance::capital.partials.state',
                     ['entry' => $e, 'accounts' => $accounts])],

                /*
                 * ⛔ সম্পাদনা ও মোছা — কেবল খসড়ায়।
                 *
                 * পোস্ট হওয়া সারিতে বোতাম দুইটা আসে না, আর সেটা সৌজন্য
                 * মাত্র: আসল পাহারা [[CapitalController::assertStillADraft()]]-এ,
                 * কারণ ঠিকানা টাইপ করে বা পুরনো ট্যাব থেকেও অনুরোধ আসতে
                 * পারে। ⓘ **মেনুতে লুকানো আর দরজায় তালা দেওয়া এক জিনিস নয়** —
                 * আজ এই পার্থক্যটা মালিকানার পর্দাতেও ধরা পড়েছে।
                 */
                ['key' => 'actions', 'label' => '', 'width' => '3rem',
                 'render' => fn ($e) => $e->status !== \App\Modules\Finance\Models\CapitalEntry::DRAFT
                     ? ''
                     : view('finance::capital.partials.row-actions', ['entry' => $e])],
            ]" />

        <x-ui.pager :rows="$entries" />
    </section>
</x-layouts.app>
