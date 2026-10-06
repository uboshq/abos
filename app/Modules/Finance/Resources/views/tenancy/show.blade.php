{{--
    ⭐ একটা ভাড়াটের চুক্তি — মালিকের সিদ্ধান্ত প্র৩, ৬ অক্টোবর ২০২৬ ([[TenancyService]])।

    মাথায় চারটা সংখ্যা: মাসিক ভাড়া · বকেয়া · হাতে রাখা জামানত · মেয়াদ শেষ। নিচে কাজ (আদায়, জামানত নেওয়া, জামানত থেকে কাটা,
    ফেরত, শর্ত বদল, শেষ), তারপর মাসের দাবি আর টাকার প্রতিটা নড়াচড়া — প্রতিটার ভাউচারে ক্লিক করে পৌঁছানো যায়।
--}}
@php
    $outstanding = $tenancy->outstanding();
    $held = $tenancy->depositHeld();
    $field = 'h-(--spacing-field-compact) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-2 text-sm';
@endphp
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $tenancy->tenant }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$tenancy->tenant" :subtitle="trim($tenancy->document_no.' · '.$tenancy->premises, ' ·')" />
    </x-slot:header>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    <x-ui.errors />

    @if ($waiting)
        <p class="mb-4 rounded-(--radius-field) bg-(--color-badge-warning-bg) px-3 py-2 text-sm text-(--color-badge-warning-ink)">
            {{ __('finance::message.awaiting_signature') }}
        </p>
    @endif

    <section data-tenancy-facts class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ([
            [__('finance::tenancy.monthly_rent'), \App\Core\Support\Money::format($tenancy->monthly_rent)],
            [__('finance::tenancy.outstanding'), \App\Core\Support\Money::format($outstanding)],
            [__('finance::tenancy.deposit_held'), \App\Core\Support\Money::format($held)],
            [__('finance::tenancy.ends_on'), $tenancy->ends_on->format('d/m/Y')],
        ] as [$label, $value])
            <div class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-3">
                <span class="block text-2xs text-(--color-ink-muted)">{{ $label }}</span>
                <span class="num text-lg font-semibold">{{ $value }}</span>
            </div>
        @endforeach
    </section>

    @if ($tenancy->status !== \App\Modules\Finance\Models\Tenancy::AWAITING)
        <section data-boxed class="mb-4 grid gap-4 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4 lg:grid-cols-2">
            @can('finance.rental.create')
                {{-- ⭐ আদায় — টাকা ধরে; বন্ধ চুক্তির পুরনো বকেয়াও --}}
                <form method="POST" action="{{ route('finance.tenancy.collect', $tenancy) }}" data-tenancy-collect class="grid gap-2 text-sm">
                    @csrf
                    <h2 class="font-semibold">{{ __('finance::tenancy.collect') }}</h2>
                    <input type="number" name="amount" step="0.01" min="0.01" required class="{{ $field }}"
                           value="{{ bccomp($outstanding, '0', 2) > 0 ? bcadd($outstanding, '0', 2) : '' }}"
                           aria-label="{{ __('finance::tenancy.amount') }}">
                    <x-ui.date name="moved_on" :value="now()->toDateString()" />
                    @include('finance::rental._money', ['money' => $money, 'label' => __('finance::tenancy.money_account'), 'required' => true])
                    <x-ui.button type="submit">{{ __('finance::tenancy.collect') }}</x-ui.button>
                </form>

                @if ($tenancy->isActive())
                    <form method="POST" action="{{ route('finance.tenancy.deposit', $tenancy) }}" data-tenancy-deposit class="grid gap-2 text-sm">
                        @csrf
                        <h2 class="font-semibold">{{ __('finance::tenancy.receive_deposit') }}</h2>
                        <input type="number" name="amount" step="0.01" min="0.01" required class="{{ $field }}"
                               aria-label="{{ __('finance::tenancy.amount') }}">
                        <x-ui.date name="moved_on" :value="now()->toDateString()" />
                        @include('finance::rental._money', ['money' => $money, 'label' => __('finance::tenancy.money_account'), 'required' => true])
                        <x-ui.button type="submit" tone="secondary">{{ __('finance::tenancy.receive_deposit') }}</x-ui.button>
                    </form>

                    <form method="POST" action="{{ route('finance.tenancy.from-deposit', $tenancy) }}" data-tenancy-from-deposit class="grid gap-2 text-sm">
                        @csrf
                        <h2 class="font-semibold">{{ __('finance::tenancy.take_from_deposit') }}</h2>
                        <input type="number" name="amount" step="0.01" min="0.01" required class="{{ $field }}"
                               aria-label="{{ __('finance::tenancy.amount') }}">
                        <span class="text-2xs text-(--color-ink-muted)">{{ __('finance::tenancy.from_deposit_note') }}</span>
                        <x-ui.button type="submit" tone="secondary">{{ __('finance::tenancy.take_from_deposit') }}</x-ui.button>
                    </form>

                    <form method="POST" action="{{ route('finance.tenancy.revise', $tenancy) }}" data-tenancy-revise class="grid gap-2 text-sm">
                        @csrf
                        @method('PUT')
                        <h2 class="font-semibold">{{ __('finance::tenancy.revise') }}</h2>
                        <input type="number" name="monthly_rent" step="0.01" min="0.01" class="{{ $field }}"
                               value="{{ bcadd((string) $tenancy->monthly_rent, '0', 2) }}" aria-label="{{ __('finance::tenancy.monthly_rent') }}">
                        <span class="text-2xs text-(--color-ink-muted)">{{ __('finance::tenancy.revise_note') }}</span>
                        <x-ui.button type="submit" tone="secondary">{{ __('finance::tenancy.revise') }}</x-ui.button>
                    </form>
                @endif
            @endcan

            @can('finance.rental.close')
                @if (bccomp($held, '0', 2) > 0)
                    <form method="POST" action="{{ route('finance.tenancy.refund', $tenancy) }}" data-tenancy-refund class="grid gap-2 text-sm">
                        @csrf
                        <h2 class="font-semibold">{{ __('finance::tenancy.refund') }}</h2>
                        <input type="number" name="amount" step="0.01" min="0.01" required class="{{ $field }}"
                               value="{{ bcadd($tenancy->depositFree(), '0', 2) }}" aria-label="{{ __('finance::tenancy.amount') }}">
                        @include('finance::rental._money', ['money' => $money, 'label' => __('finance::tenancy.money_account'), 'required' => true])
                        <x-ui.button type="submit" tone="secondary">{{ __('finance::tenancy.refund') }}</x-ui.button>
                    </form>
                @endif

                @if ($tenancy->isActive())
                    <form method="POST" action="{{ route('finance.tenancy.close', $tenancy) }}" data-tenancy-close class="grid gap-2 text-sm">
                        @csrf
                        <h2 class="font-semibold">{{ __('finance::tenancy.close') }}</h2>
                        <x-ui.date name="closed_on" :value="now()->toDateString()" />
                        <span class="text-2xs text-(--color-ink-muted)">{{ __('finance::tenancy.close_note') }}</span>
                        <x-ui.button type="submit" tone="secondary">{{ __('finance::tenancy.close') }}</x-ui.button>
                    </form>
                @endif
            @endcan
        </section>
    @endif

    <section data-boxed data-tenancy-charges class="mb-4 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
        <h2 class="mb-2 font-semibold">{{ __('finance::tenancy.charges_title') }}</h2>
        <table class="w-full text-sm">
            <thead>
                <tr class="text-start text-2xs text-(--color-ink-muted)">
                    <th class="text-start">{{ __('finance::tenancy.month') }}</th>
                    <th class="text-end">{{ __('finance::tenancy.amount') }}</th>
                    <th class="text-start">{{ __('finance::tenancy.voucher') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($charges as $charge)
                    <tr class="border-t border-(--color-border)">
                        <td>{{ $charge->for_month->translatedFormat('F Y') }}</td>
                        <td class="num text-end">{{ \App\Core\Support\Money::format($charge->amount) }}</td>
                        <td>
                            @if ($charge->voucher)
                                <a href="{{ route('accounts.voucher.show', $charge->voucher) }}" class="text-(--color-link) underline-offset-2 hover:underline">{{ $charge->voucher->document_no }}</a>
                                @if ($charge->voucher->isDraft())
                                    <span class="text-2xs text-(--color-badge-warning-ink)">{{ __('finance::tenancy.waiting') }}</span>
                                @endif
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="py-2 text-(--color-ink-muted)">{{ __('finance::tenancy.no_charges') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    <section data-boxed data-tenancy-moves class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
        <h2 class="mb-2 font-semibold">{{ __('finance::tenancy.moves_title') }}</h2>
        <table class="w-full text-sm">
            <thead>
                <tr class="text-start text-2xs text-(--color-ink-muted)">
                    <th class="text-start">{{ __('finance::tenancy.date') }}</th>
                    <th class="text-start">{{ __('finance::tenancy.kind') }}</th>
                    <th class="text-end">{{ __('finance::tenancy.amount') }}</th>
                    <th class="text-start">{{ __('finance::tenancy.voucher') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($moves as $move)
                    <tr class="border-t border-(--color-border)">
                        <td>{{ $move->moved_on->format('d/m/Y') }}</td>
                        <td>{{ __('finance::tenancy.kind_'.$move->kind) }}</td>
                        <td class="num text-end">{{ \App\Core\Support\Money::format($move->amount) }}</td>
                        <td>
                            @if ($move->voucher)
                                <a href="{{ route('accounts.voucher.show', $move->voucher) }}" class="text-(--color-link) underline-offset-2 hover:underline">{{ $move->voucher->document_no }}</a>
                                @if ($move->voucher->isDraft())
                                    <span class="text-2xs text-(--color-badge-warning-ink)">{{ __('finance::tenancy.waiting') }}</span>
                                @endif
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="py-2 text-(--color-ink-muted)">{{ __('finance::tenancy.no_moves') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>
</x-layouts.app>
