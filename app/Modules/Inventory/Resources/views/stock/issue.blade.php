{{--
    বিক্রি ছাড়া মাল বের করে দেওয়া।

    ── কেন এটা সমন্বয়ের পর্দা নয় ───────────────────────────────────────
    সমন্বয়ে প্রশ্ন "গুনে কত পেলাম" — অর্থাৎ একটা ভুল ধরা পড়ছে, খাতা আর
    তাক মেলেনি। এখানে প্রশ্ন "কতটা দিয়ে দিলাম", আর কিছুই ভুল হয়নি:
    মালটা জেনেশুনে গেছে।

    এক পর্দায় রাখলে ব্যবহারকারীকে মাথায় বিয়োগ করতে হত ("তাকে ৫০ আছে, ২
    কার্টন দিলাম, লিখব ৪৮") — আর ওই বিয়োগটাই ভুল হয়। তার চেয়েও বড় কথা,
    মজুদ ঘাটতির রিপোর্টে আপ্যায়নের বিস্কুট ঘাটতি হিসেবে গিয়ে বসত, আর
    মালিক ভাবতেন গুদামে চুরি হচ্ছে।

    ── তিনটা কারণ, তিনটা আলাদা খাত ─────────────────────────────────────
    কারণটা বেছে নিলেই টাকাটা ঠিক জায়গায় যায়, আর তালিকাটা সেটিংসের সারি
    — কোম্পানি নিজে নতুন কারণ যোগ করতে পারে।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('inventory::menu.issue') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('inventory::menu.issue')"
                          :subtitle="__('inventory::message.issue_note')" />
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

    <div class="grid gap-4 lg:grid-cols-[1fr_20rem]">
        <section data-boxed class="rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card) p-4">
            <form method="POST" action="{{ route('inventory.stock.issue.store') }}" class="space-y-3">
                @csrf

                <x-ui.select name="product_id" :label="__('inventory::field.product')"
                             :options="$products->mapWithKeys(fn ($p) => [$p->id => $p->code . ' - ' . $p->name()])"
                             placeholder="-" required />

                <x-ui.select name="warehouse_id" :label="__('inventory::field.warehouse')"
                             :options="$warehouses->mapWithKeys(fn ($w) => [$w->id => $w->name()])"
                             placeholder="-" required />

                {{-- "কতটা যাচ্ছে" — তাকে কত থাকবে তা নয়। মানুষ যা জানে
                     সেটাই জিজ্ঞেস করা হয়; বিয়োগটা সিস্টেম করে। --}}
                <x-ui.field name="qty" type="number" step="0.01" inputmode="decimal"
                            :label="__('inventory::field.issued_qty')" numeric required />

                <x-ui.select name="reason_code_id" :label="__('inventory::field.reason')"
                             :options="$reasons->mapWithKeys(fn ($r) => [
                                 $r->id => $r->name() . ($r->account ? '  →  ' . $r->account->label() : ''),
                             ])"
                             placeholder="-" required />

                <x-ui.field name="trx_date" type="date" :label="__('inventory::field.date')"
                            :value="old('trx_date', now()->toDateString())" required />

                <x-ui.field name="narration" :label="__('inventory::field.narration')"
                            :value="old('narration')"
                            :hint="__('inventory::message.issue_narration_hint')" />

                <x-ui.button type="submit" tone="primary">
                    {{ __('inventory::action.issue') }}
                </x-ui.button>
            </form>
        </section>

        {{--
            কোন কারণ কোন খাতে যায় — পাশেই লেখা।

            এই তিনটা আলাদা রাখা হিসাবের দিক থেকে জরুরি, কিন্তু কারণটা
            চোখে পড়ার মতো নয়। বিশেষ করে তৃতীয়টা: মালিকের নিজের ব্যবহার
            খরচ নয়, উত্তোলন। খরচ লিখলে ব্যবসার মুনাফা কম দেখাত আর
            বছরশেষে কে কত নিল তা বলার উপায় থাকত না।
        --}}
        <aside class="rounded-(--radius-card) border border-(--color-border)
                      bg-(--color-surface-card) p-4">
            <h2 class="mb-2 font-semibold">{{ __('inventory::message.issue_where_it_goes') }}</h2>

            <dl class="space-y-2 text-sm">
                @foreach ($reasons as $reason)
                    <div class="flex items-baseline justify-between gap-3 border-b
                                border-(--color-border) pb-2 last:border-0">
                        <dt>{{ $reason->name() }}</dt>
                        <dd class="text-end text-2xs text-(--color-ink-muted)">
                            {{ $reason->account?->label() ?? __('inventory::message.issue_no_account') }}
                        </dd>
                    </div>
                @endforeach
            </dl>

            <p class="mt-3 text-2xs text-(--color-ink-muted)">
                {{ __('inventory::message.issue_cost_note') }}
            </p>
        </aside>
    </div>
</x-layouts.app>
