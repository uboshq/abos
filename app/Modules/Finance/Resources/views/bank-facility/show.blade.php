{{--
    একটা সুবিধার নথি — মঞ্জুরি, জামানত, শর্ত।

    ── ⛔ এখানেও টাকা নাড়ার বোতাম নেই ────────────────────────────────
    **খাতা ঘটনা লেখে · ভাউচার টাকা নাড়ে।** ⓘ CC-র ব্যালান্স দেখতে
    হলে ওর ব্যাংক হিসাবের খতিয়ানে যেতে হয় — আর সেটাই সঠিক, কারণ
    ওখানেই সংখ্যাটা থাকে।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $facility->bank }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header
            :title="$facility->bank"
            :subtitle="__('finance::field.facility_' . $facility->kind) . ' · ' . $facility->drillDocumentNo()" />
    </x-slot:header>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-badge-success-bg px-3 py-2 text-sm text-badge-success-ink">
            {{ session('saved') }}
        </div>
    @endif

    <div class="grid gap-4 lg:grid-cols-2">

        {{-- মঞ্জুরি --}}
        <section data-boxed
                 class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="mb-3 font-semibold">{{ __('finance::message.facility_sanction') }}</h2>

            <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                <dt class="text-(--color-ink-muted)">{{ __('finance::field.limit_amount') }}</dt>
                <dd class="text-end"><x-ui.amount :value="$facility->limit_amount" /></dd>

                <dt class="text-(--color-ink-muted)">{{ __('finance::field.interest_rate') }}</dt>
                <dd class="text-end">{{ $facility->interest_rate }}%</dd>

                <dt class="text-(--color-ink-muted)">{{ __('finance::field.sanctioned_on') }}</dt>
                <dd class="text-end">{{ $facility->sanctioned_on?->translatedFormat('j F Y') }}</dd>

                <dt class="text-(--color-ink-muted)">{{ __('finance::field.renews_on') }}</dt>
                <dd @class(['text-end', 'font-semibold text-badge-warning-ink' => $facility->renewalIsNear()])>
                    {{ $facility->renews_on?->translatedFormat('j F Y') ?? '—' }}
                </dd>
            </dl>

            {{--
                ⚠️ নবায়ন কাছে এলে কথাটা লেখা হয়, কেবল রঙ বদলানো হয় না।

                ⓘ রঙ একা কিছু বলে না — যিনি রং চেনেন না বা পর্দা-পাঠক
                ব্যবহার করেন, তাঁর কাছে ওটা নীরব।
            --}}
            @if ($facility->renewalIsNear())
                <p class="mt-3 rounded-(--radius-field) bg-badge-warning-bg px-3 py-2 text-sm text-badge-warning-ink">
                    {{ __('finance::message.facility_renew_soon') }}
                </p>
            @endif
        </section>

        {{--
            ⭐ ড্রয়িং পাওয়ার — কেবল CC-তে, আর এটাই এই পাতার আসল সংখ্যা।

            ⛔ দোকানদার প্রায়ই ভাবেন মঞ্জুরিকৃত সীমাটাই তাঁর টাকা।
            ⚠️ স্টক কমলে সীমাও কমে, আর টের পাওয়া যায় চেক ফেরত এলে।
        --}}
        @if ($facility->drawingPower() !== null)
            <section data-boxed
                     class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                <h2 class="mb-3 font-semibold">{{ __('finance::field.drawing_power') }}</h2>

                <p class="text-2xl font-semibold tabular-nums">
                    <x-ui.amount :value="$facility->drawingPower()" />
                </p>

                <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                    <dt class="text-(--color-ink-muted)">{{ __('finance::field.stock_value') }}</dt>
                    <dd class="text-end"><x-ui.amount :value="$facility->stock_value" /></dd>

                    <dt class="text-(--color-ink-muted)">{{ __('finance::field.margin_percent') }}</dt>
                    <dd class="text-end">{{ $facility->margin_percent }}%</dd>

                    <dt class="text-(--color-ink-muted)">{{ __('finance::field.last_statement_on') }}</dt>
                    <dd class="text-end">{{ $facility->last_statement_on?->translatedFormat('j M Y') ?? '—' }}</dd>
                </dl>

                <p class="mt-3 text-sm text-(--color-ink-muted)">
                    {{ __('finance::message.drawing_power_note') }}
                </p>

                @if ($facility->moneyAccount)
                    <p class="mt-2 text-sm text-(--color-ink-muted)">
                        {{ __('finance::message.facility_balance_lives_in', [
                            'account' => $facility->moneyAccount->code . ' · ' . $facility->moneyAccount->name(),
                        ]) }}
                    </p>
                @endif
            </section>
        @endif

        {{-- জামানত ও শর্ত --}}
        <section data-boxed
                 class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="mb-3 font-semibold">{{ __('finance::message.facility_security') }}</h2>

            <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                <dt class="text-(--color-ink-muted)">{{ __('finance::field.security_type') }}</dt>
                <dd class="text-end">{{ __('finance::field.security_' . $facility->security_type) }}</dd>

                <dt class="text-(--color-ink-muted)">{{ __('finance::field.security_value') }}</dt>
                <dd class="text-end">
                    {{ $facility->security_value === null ? '—' : \App\Core\Support\Money::format($facility->security_value) }}
                </dd>

                <dt class="text-(--color-ink-muted)">{{ __('finance::field.guarantors') }}</dt>
                <dd class="text-end">{{ $facility->guarantors ?: '—' }}</dd>
            </dl>

            @if ($facility->covenant)
                <p class="mt-3 rounded-(--radius-field) bg-(--color-surface-app) px-3 py-2 text-sm">
                    {{ $facility->covenant }}
                </p>
            @endif
        </section>

        {{--
            ⭐ স্থিতিপত্রে এটা কোথায় বসে — আর কোথায় বসে না।

            ⛔ গ্যারান্টি দায় নয় যতক্ষণ না কেউ ভাঙায়; CC-ও নয় এই
            অর্থে — ওর দেনা ব্যাংক হিসাবের ঋণাত্মক ব্যালান্স।
            ⚠️ দুইটাকে ঋণ ধরে বসালে ব্যবসাটা নিজের চেয়ে বেশি ঋণগ্রস্ত
            দেখাত, আর ব্যাংক পরের সুবিধা দিতে দ্বিধা করত।
        --}}
        <section data-boxed
                 class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="mb-3 font-semibold">{{ __('finance::message.facility_in_the_books') }}</h2>

            <p class="text-sm">
                {{ $facility->isBalanceSheetDebt()
                    ? __('finance::message.facility_is_debt')
                    : __('finance::message.facility_is_not_debt') }}
            </p>

            @if ($facility->liabilityAccount)
                <p class="mt-2 text-sm text-(--color-ink-muted)">
                    {{ $facility->liabilityAccount->code }} · {{ $facility->liabilityAccount->name() }}
                </p>
            @endif

            @can('finance.bank_facility.close')
                @if ($facility->closed_on === null)
                    <form method="POST" action="{{ route('finance.bank_facility.close', $facility) }}"
                          class="mt-4"
                          onsubmit="return confirm('{{ __('finance::message.facility_close_confirm') }}')">
                        @csrf
                        <x-ui.button type="submit" tone="secondary">
                            {{ __('finance::action.close_facility') }}
                        </x-ui.button>
                    </form>
                @endif
            @endcan
        </section>
    </div>
</x-layouts.app>
