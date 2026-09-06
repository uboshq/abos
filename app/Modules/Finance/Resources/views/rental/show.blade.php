{{--
    একটা ভাড়ার চুক্তি — আর তার সাথে যা যা ঘটে।

    ── কেন সব কাজ এক পাতায় ─────────────────────────────────────────────
    মাসের সমন্বয় · শর্ত বদল · জামানতে টাকা যোগ · চুক্তি শেষ — চারটাই
    একই কাগজের কথা, আর চারটাই মাসে একবারের কম ঘটে। আলাদা পাতা বানালে
    প্রতিটাতে আবার চুক্তির সংখ্যাগুলো দেখাতে হত, নাহলে মানুষ অন্ধভাবে
    ঘর ভরতেন।

    ── ⭐ উপরের চারটা সংখ্যা ────────────────────────────────────────────
    জামানত · এ পর্যন্ত কাটা · এখন বাকি · মেয়াদ শেষ। ⓘ মালিক যে চারটা
    প্রশ্ন নিয়ে আসেন, ঠিক সেই চারটাই — আর প্রতিটার নিচে ক্লিক করলে
    কোন কোন মাসে কী হয়েছে তা দেখা যায় (মালিকের স্থায়ী নিয়ম)।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $contract->counterparty }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$contract->counterparty"
                          :subtitle="$contract->subject" />
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

    <section data-boxed
             class="mb-4 grid gap-3 rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card) p-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ([
            'rental_deposit' => \App\Core\Support\Money::format($contract->deposit_amount),
            'rental_adjusted' => \App\Core\Support\Money::format($contract->adjustedSoFar()),
            'rental_deposit_left' => \App\Core\Support\Money::format($contract->depositLeft()),
            'rental_ends_on' => $contract->ends_on->format('d/m/Y'),
        ] as $label => $value)
            <div>
                <div class="text-2xs text-(--color-ink-muted)">{{ __('finance::field.'.$label) }}</div>
                <div class="num text-lg font-semibold">{{ $value }}</div>
            </div>
        @endforeach
    </section>

    <section data-boxed
             class="mb-4 rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card) p-4 text-sm">
        <dl class="grid gap-2 sm:grid-cols-3">
            <div>
                <dt class="text-2xs text-(--color-ink-muted)">{{ __('finance::field.rental_rent') }}</dt>
                <dd class="num">{{ \App\Core\Support\Money::format($contract->monthly_rent) }}</dd>
            </div>
            <div>
                <dt class="text-2xs text-(--color-ink-muted)">{{ __('finance::field.rental_cash') }}</dt>
                <dd class="num">{{ \App\Core\Support\Money::format($contract->monthlyCash()) }}</dd>
            </div>
            <div>
                <dt class="text-2xs text-(--color-ink-muted)">{{ __('finance::field.rental_from_deposit') }}</dt>
                <dd class="num">{{ \App\Core\Support\Money::format($contract->monthly_adjustment) }}</dd>
            </div>

            {{-- ⭐ খাতটা দেখানো হয়, কারণ জামানত (ফেরত আসবে) আর অগ্রিম
                 (খেয়ে যাবে) দুইটা এক নয় — আর পার্থক্যটা স্থিতিপত্রে
                 লাখ টাকার। --}}
            <div>
                <dt class="text-2xs text-(--color-ink-muted)">{{ __('finance::field.rental_head') }}</dt>
                <dd>{{ $contract->account?->code }} — {{ $contract->account?->name() }}</dd>
            </div>
            <div>
                <dt class="text-2xs text-(--color-ink-muted)">{{ __('finance::field.rental_expense_head') }}</dt>
                <dd>{{ $contract->expenseAccount?->code }} — {{ $contract->expenseAccount?->name() }}</dd>
            </div>
            <div>
                <dt class="text-2xs text-(--color-ink-muted)">{{ __('finance::field.rental_refund_at_end') }}</dt>
                <dd class="num">{{ \App\Core\Support\Money::format($contract->refundableAtEnd()) }}</dd>
            </div>
        </dl>
    </section>

    @can('finance.rental.create')
        @if ($contract->isActive())
            <div class="mb-4 grid gap-4 lg:grid-cols-2">
                {{-- এক মাসের ভাড়া --}}
                <section data-boxed
                         class="rounded-(--radius-card) border border-(--color-border)
                                bg-(--color-surface-card) p-4">
                    <h2 class="mb-3 font-semibold">{{ __('finance::action.rental_do_month') }}</h2>

                    <form method="POST" action="{{ route('finance.rental.adjust', $contract) }}"
                          class="grid gap-3 sm:grid-cols-2">
                        @csrf

                        <label class="grid gap-1">
                            <span class="text-2xs text-(--color-ink-muted)">
                                {{ __('finance::field.rental_for_month') }}
                            </span>
                            <x-ui.date name="for_month"
                                       :value="old('for_month', now()->startOfMonth()->toDateString())" required />
                        </label>

                        @include('finance::rental._money', [
                            'money' => $money,
                            'label' => __('finance::field.rental_money_account'),
                            'blank' => '—',
                        ])

                        {{-- ⓘ দুইটাই ঐচ্ছিক — খালি রাখলে চুক্তির শর্তই খাটে।
                             এক মাসে অন্যরকম হলে (যেমন এক মাস বেশি নগদে দিলেন)
                             তখনই কেবল লিখতে হয়। --}}
                        <x-ui.field name="rent" type="number" step="0.0001" min="0"
                                    :label="__('finance::field.rental_rent_this_month')"
                                    :placeholder="\App\Core\Support\Money::format($contract->monthly_rent)" />

                        <x-ui.field name="from_deposit" type="number" step="0.0001" min="0"
                                    :label="__('finance::field.rental_from_deposit')"
                                    :placeholder="\App\Core\Support\Money::format($contract->monthly_adjustment)" />

                        <div class="sm:col-span-2">
                            <x-ui.button type="submit">{{ __('finance::action.rental_post_month') }}</x-ui.button>
                        </div>
                    </form>
                </section>

                {{-- শর্ত বদল ও জামানতে টাকা --}}
                <section data-boxed
                         class="rounded-(--radius-card) border border-(--color-border)
                                bg-(--color-surface-card) p-4">
                    <h2 class="mb-3 font-semibold">{{ __('finance::action.rental_revise') }}</h2>

                    {{-- ⚠️ বদলটা কেবল সামনের মাসগুলোয় খাটে; যে মাসগুলো
                         করা হয়ে গেছে তারা নিজেদের সংখ্যা নিজেরাই ধরে
                         রাখে, তাই গত বছরের হিসাব নড়ে না। --}}
                    <p class="mb-3 text-2xs text-(--color-ink-muted)">
                        {{ __('finance::message.rental_revision_note') }}
                    </p>

                    <form method="POST" action="{{ route('finance.rental.revise', $contract) }}"
                          class="mb-4 grid gap-3 sm:grid-cols-2">
                        @csrf
                        @method('PUT')

                        <x-ui.field name="monthly_rent" type="number" step="0.0001" min="0"
                                    :label="__('finance::field.rental_rent')"
                                    :value="old('monthly_rent', $contract->monthly_rent)" />

                        <x-ui.field name="monthly_adjustment" type="number" step="0.0001" min="0"
                                    :label="__('finance::field.rental_from_deposit')"
                                    :value="old('monthly_adjustment', $contract->monthly_adjustment)" />

                        <div class="sm:col-span-2">
                            <x-ui.button type="submit" tone="secondary">
                                {{ __('finance::action.rental_save_terms') }}
                            </x-ui.button>
                        </div>
                    </form>

                    <h3 class="mb-2 font-semibold">{{ __('finance::action.rental_top_up') }}</h3>

                    <form method="POST" action="{{ route('finance.rental.topup', $contract) }}"
                          class="grid gap-3 sm:grid-cols-2">
                        @csrf

                        <x-ui.field name="amount" type="number" step="0.0001" min="0.0001"
                                    :label="__('finance::field.rental_top_up_amount')" required />

                        @include('finance::rental._money', [
                            'money' => $money,
                            'label' => __('finance::field.rental_money_account'),
                            'required' => true,
                        ])

                        <div class="sm:col-span-2">
                            <x-ui.button type="submit" tone="secondary">
                                {{ __('finance::action.rental_add_deposit') }}
                            </x-ui.button>
                        </div>
                    </form>
                </section>
            </div>

            {{-- চুক্তি শেষ --}}
            <section data-boxed
                     class="mb-4 rounded-(--radius-card) border border-(--color-border)
                            bg-(--color-surface-card) p-4">
                <h2 class="mb-1 font-semibold">{{ __('finance::action.rental_close') }}</h2>

                {{-- ⚠️ কত ফেরত আসবে তা এখানেই লেখা — মানুষকে গুনতে দিলে
                     ভুল হত, আর ভুলটা তাঁর নিজের ক্ষতি। ছয় মাস পর ছাড়লে
                     সংখ্যাটা মেয়াদ-শেষের হিসাবের চেয়ে আলাদা। --}}
                <p class="mb-3 text-sm text-(--color-ink-muted)">
                    {{ __('finance::message.rental_close_note', [
                        'amount' => \App\Core\Support\Money::format($contract->depositLeft()),
                    ]) }}
                </p>

                <form method="POST" action="{{ route('finance.rental.close', $contract) }}"
                      class="grid gap-3 sm:grid-cols-3">
                    @csrf

                    <label class="grid gap-1">
                        <span class="text-2xs text-(--color-ink-muted)">
                            {{ __('finance::field.rental_closed_on') }}
                        </span>
                        <x-ui.date name="closed_on" :value="old('closed_on', now()->toDateString())" />
                    </label>

                    {{-- ⓘ এখানে খালি রাখা যায়, আর সেটা একটা সিদ্ধান্ত:
                         বাড়িওয়ালা আজ টাকা ফেরত দেননি। উপরের লেখাটা তখন
                         বলে দেয় টাকাটা জামানতের খাতেই পড়ে থাকবে। --}}
                    @include('finance::rental._money', [
                        'money' => $money,
                        'label' => __('finance::field.rental_refund_to'),
                        'blank' => '—',
                    ])

                    <div class="self-end">
                        <x-ui.button type="submit" tone="secondary">
                            {{ __('finance::action.rental_close_now') }}
                        </x-ui.button>
                    </div>
                </form>
            </section>
        @endif
    @endcan

    <section data-boxed
             class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card)">
        <h2 class="p-4 pb-2 font-semibold">{{ __('finance::message.rental_months') }}</h2>

        @if ($adjustments->isEmpty())
            <p class="p-4 pt-0 text-sm text-(--color-ink-muted)">
                {{ __('finance::message.rental_no_months') }}
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="ui-list w-full">
                    <thead>
                        <tr class="text-2xs text-(--color-ink-muted)">
                            <th class="text-start">{{ __('finance::field.rental_for_month') }}</th>
                            <th class="text-end">{{ __('finance::field.rental_rent') }}</th>
                            <th class="text-end">{{ __('finance::field.rental_cash') }}</th>
                            <th class="text-end">{{ __('finance::field.rental_from_deposit') }}</th>
                            <th class="text-start">{{ __('finance::field.rental_voucher') }}</th>
                        </tr>
                    </thead>

                    <tbody>
                        @foreach ($adjustments as $row)
                            <tr class="border-t border-(--color-border)">
                                <td>{{ $row->monthLabel() }}</td>
                                <td class="num text-end">{{ \App\Core\Support\Money::format($row->rent) }}</td>
                                <td class="num text-end">{{ \App\Core\Support\Money::format($row->paid_cash) }}</td>
                                <td class="num text-end">{{ \App\Core\Support\Money::format($row->from_deposit) }}</td>

                                {{-- ⭐ প্রতিটা সংখ্যা তার উৎসে পৌঁছায় — মালিকের
                                     স্থায়ী নিয়ম। ভাউচারটা না থাকলে সারিটা
                                     কোথা থেকে এল তা অজানা থাকত। --}}
                                <td>
                                    @if ($row->voucher)
                                        <a href="{{ route('accounts.voucher.show', $row->voucher) }}"
                                           class="text-(--color-brand-500) underline-offset-2 hover:underline">
                                            {{ $row->voucher->document_no }}
                                        </a>
                                    @else
                                        <span class="text-(--color-ink-muted)">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</x-layouts.app>
