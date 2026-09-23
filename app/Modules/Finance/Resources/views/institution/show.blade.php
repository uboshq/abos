{{--
    একটা প্রতিষ্ঠান — এখানে আমাদের কী কী আছে, এক পাতায়।

    ⓘ অংশগুলো: হিসাবের খাত (আজকের জেরসহ) · বীমা পলিসি। ব্যাংক সুবিধা আর
    আমানত যোগ হয় যখন ওই দুইটা ফর্ম প্রতিষ্ঠান বাছে (ধাপ ৩)।
    ⚠️ জের এখানে লেখা থাকে না — প্রতিবার খতিয়ান থেকে পড়া। আলাদা করে
    রাখলে একদিন খতিয়ান আর এই পাতা দুই কথা বলত।
--}}
@php
    use App\Core\Support\Money;
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $institution->name() }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$institution->label()"
                          :subtitle="collect([__('finance::institution.kind_'.$institution->kind), $institution->branch_name,
                                              $institution->contact_person, $institution->phone])->filter()->implode(' · ')">
            <x-slot:actions>
                @can('finance.institution.manage')
                    <x-ui.button tone="secondary" :href="route('finance.institution.edit', $institution)">
                        {{ __('finance::institution.edit') }}
                    </x-ui.button>
                @endcan
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>

    @if (session('saved'))
        <p role="status" class="mb-3 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2
                                text-sm text-(--color-badge-success-ink)">{{ session('saved') }}</p>
    @endif

    <x-ui.errors />

    @if ($institution->kind !== \App\Modules\Finance\Models\Institution::INSURANCE)
        <section data-boxed
                 class="mb-4 max-w-screen-2xl overflow-hidden rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card)">
            <header class="flex flex-wrap items-baseline gap-x-3 gap-y-1 border-b border-(--color-border) px-4 py-2">
                <h2 class="text-sm font-semibold">{{ __('finance::institution.accounts') }}</h2>
                <span class="text-2xs text-(--color-ink-muted)">{{ __('finance::institution.accounts_hint') }}</span>
                {{-- ⚠️ এই পাতার যোগফলগুলো ইচ্ছাকৃতভাবে সাদা — সিদ্ধান্ত, ভুলে
                     যাওয়া নয় (২০ সেপ্টেম্বর ২০২৬)।

                     ⓘ মালিকের নিয়ম: সংখ্যা তার পিছনের সারিগুলো খোলে। কিন্তু
                     এখানে সারিগুলো যোগফলের ঠিক নিচেই দাঁড়িয়ে আছে — লিংকটা
                     চোখকে যেখানে নিয়ে যেত, চোখ এমনিতেই সেখানে। ⛔ ছাঁকা
                     তালিকার পাতা বানিয়ে সেখানে পাঠালে একই জিনিস দুইবার
                     দেখানো হত, আর ফেরার পথ একটা বাড়ত। --}}
                <span class="ms-auto text-sm tabular-nums">
                    {{ __('finance::institution.total') }}: <strong>{{ Money::format($balanceTotal) }}</strong>
                </span>
            </header>

            @if ($links->isEmpty())
                <p class="px-4 py-3 text-sm text-(--color-ink-muted)">{{ __('finance::institution.no_accounts') }}</p>
            @else
                <table class="w-full text-sm">
                    <thead class="text-2xs text-(--color-ink-muted)">
                        <tr>
                            <th class="px-4 py-2 text-start font-medium">{{ __('finance::institution.account') }}</th>
                            <th class="num px-4 py-2 font-medium">{{ __('finance::institution.balance_today') }}</th>
                            <th class="w-24 px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($links as $link)
                            <tr class="border-t border-(--color-border)">
                                <td class="px-4 py-2">
                                    <a href="{{ route('accounts.report.show', ['slug' => 'ledger', 'account_id' => $link['account']->id]) }}"
                                       class="text-(--color-brand-600) underline-offset-2 hover:underline">
                                        {{ $link['account']->label() }}
                                    </a>
                                </td>
                                {{-- ⭐ জেরটাও খতিয়ানে নামে — মালিকের কথা, ২০ সেপ্টেম্বর
                                     ২০২৬: সংখ্যা মানে তার পিছনের সারিগুলো। ⓘ নামের ঘরে
                                     লিংকটা ছিল, কিন্তু চোখ যায় সংখ্যাটায়, আর হাতও যায়
                                     সেখানেই — মানুষ নামে ক্লিক করে না, টাকায় করে। --}}
                                <td class="num px-4 py-2 tabular-nums">
                                    <a href="{{ route('accounts.report.show', ['slug' => 'ledger', 'account_id' => $link['account']->id]) }}"
                                       class="text-(--color-brand-600) underline-offset-2 hover:underline">
                                        {{ Money::format($link['balance']) }}
                                    </a>
                                </td>
                                <td class="px-4 py-2 text-end">
                                    @can('finance.institution.manage')
                                        <form method="POST"
                                              action="{{ route('finance.institution.unlink', [$institution, $link['account']->id]) }}">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit"
                                                    class="text-2xs text-(--color-badge-danger-ink) underline-offset-2 hover:underline">
                                                {{ __('finance::institution.unlink') }}
                                            </button>
                                        </form>
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif

            @can('finance.institution.manage')
                <form method="POST" action="{{ route('finance.institution.link', $institution) }}"
                      class="flex flex-wrap items-end gap-2 border-t border-(--color-border) px-4 py-3">
                    @csrf
                    @if ($linkable === [])
                        <span class="text-2xs text-(--color-ink-muted)">{{ __('finance::institution.nothing_to_link') }}</span>
                    @else
                        <div class="min-w-64">
                            <x-ui.select name="account_id" :label="__('finance::institution.account')"
                                         :options="$linkable" placeholder="—" required />
                        </div>
                        <x-ui.button type="submit" tone="primary" icon="plus">
                            {{ __('finance::institution.link_account') }}
                        </x-ui.button>
                    @endif
                </form>
            @endcan
        </section>
    @endif

    @if ($facilities->isNotEmpty())
        <section data-boxed
                 class="mb-4 max-w-screen-2xl overflow-hidden rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card)">
            <header class="flex flex-wrap items-baseline gap-x-3 border-b border-(--color-border) px-4 py-2">
                <h2 class="text-sm font-semibold">{{ __('finance::menu.bank_facility') }}</h2>
                <span class="ms-auto text-sm tabular-nums">
                    {{ __('finance::field.limit_amount') }}:
                    <strong>{{ Money::format($facilities->reduce(fn ($c, $f) => bcadd($c, (string) $f->limit_amount, 4), '0')) }}</strong>
                </span>
            </header>

            <x-ui.table :rows="$facilities" :empty="'—'" :columns="[
                ['key' => 'document_no', 'label' => __('core.table.document'), 'width' => '9rem',
                 'render' => fn ($f) => view('finance::institution.partials.facility-link', ['facility' => $f])],
                ['key' => 'kind', 'label' => __('finance::field.facility_kind'), 'width' => '10rem',
                 'render' => fn ($f) => __('finance::field.facility_'.$f->kind)],
                ['key' => 'branch_name', 'label' => __('finance::institution.branch_name'), 'width' => '10rem',
                 'render' => fn ($f) => $f->branch_name ?: '—'],
                ['key' => 'limit_amount', 'label' => __('finance::field.limit_amount'), 'numeric' => true, 'width' => '10rem',
                 'render' => fn ($f) => Money::format($f->limit_amount)],
            ]" />
        </section>
    @endif

    @if ($deposits->isNotEmpty())
        <section data-boxed
                 class="mb-4 max-w-screen-2xl overflow-hidden rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card)">
            <header class="flex flex-wrap items-baseline gap-x-3 border-b border-(--color-border) px-4 py-2">
                <h2 class="text-sm font-semibold">{{ __('finance::menu.deposit_bank') }}</h2>
                <span class="ms-auto text-sm tabular-nums">
                    {{ __('finance::institution.total') }}:
                    <strong>{{ Money::format($deposits->reduce(fn ($c, $d) => bcadd($c, (string) $d->principal, 4), '0')) }}</strong>
                </span>
            </header>

            <x-ui.table :rows="$deposits" :empty="'—'" :columns="[
                ['key' => 'document_no', 'label' => __('core.table.document'), 'width' => '9rem',
                 'render' => fn ($d) => view('finance::institution.partials.deposit-link', ['deposit' => $d])],
                ['key' => 'kind', 'label' => __('finance::field.facility_kind'), 'width' => '10rem',
                 /* ⓘ আমানতের ধরনটার নিজের পাতা আছে — সম্পাদনার ফর্ম, আর সেখানেই
                ঐ ধরনের নিয়মগুলো লেখা। ⚠️ লিংকটা কেবল যাঁর ক্ষমতা আছে তাঁর
                জন্য, নাহলে সবাইকে একটা ৪০৩-এর দিকে পাঠানো হত। */
             'render' => fn ($d) => $d->deposit_kind_id && auth()->user()?->can('finance.deposit_kind.manage')
                 ? view('finance::institution.partials.kind-link', [
                     'id' => $d->deposit_kind_id, 'label' => $d->kind?->name() ?? '—',
                 ])
                 : ($d->kind?->name() ?? '—')],
                ['key' => 'reference_no', 'label' => __('finance::field.reference_no'), 'width' => '10rem',
                 'render' => fn ($d) => $d->reference_no ?: '—'],
                ['key' => 'principal', 'label' => __('finance::field.principal'), 'numeric' => true, 'width' => '10rem',
                 'render' => fn ($d) => Money::format($d->principal)],
            ]" />
        </section>
    @endif

    @if ($policies->isNotEmpty() || $institution->kind === \App\Modules\Finance\Models\Institution::INSURANCE)
        <section data-boxed
                 class="mb-4 max-w-screen-2xl overflow-hidden rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card)">
            <header class="flex flex-wrap items-baseline gap-x-3 border-b border-(--color-border) px-4 py-2">
                <h2 class="text-sm font-semibold">{{ __('finance::institution.policies') }}</h2>
                <span class="ms-auto text-sm tabular-nums">
                    {{ __('finance::insurance.sum_insured') }}:
                    <strong>{{ Money::format($policies->where('is_active', true)->reduce(fn ($c, $p) => bcadd($c, (string) $p->sum_insured, 4), '0')) }}</strong>
                    · {{ __('finance::insurance.premium') }}:
                    <strong>{{ Money::format($policies->where('is_active', true)->reduce(fn ($c, $p) => bcadd($c, (string) $p->premium, 4), '0')) }}</strong>
                </span>
            </header>

            <x-ui.table :rows="$policies" :empty="__('finance::insurance.none_yet')" :columns="[
                ['key' => 'policy_no', 'label' => __('finance::insurance.policy_no'), 'width' => '10rem',
                 'render' => fn ($p) => view('finance::insurance.partials.policy-link', ['policy' => $p])],
                ['key' => 'subject', 'label' => __('finance::insurance.subject'),
                 'render' => fn ($p) => $p->subject.' · '.__('finance::insurance.covers_'.$p->covers)],
                ['key' => 'sum_insured', 'label' => __('finance::insurance.sum_insured'), 'numeric' => true, 'width' => '9rem',
                 'render' => fn ($p) => Money::format($p->sum_insured)],
                ['key' => 'ends_on', 'label' => __('finance::insurance.ends_on'), 'width' => '10rem',
                 'render' => fn ($p) => view('finance::insurance.partials.renewal', ['policy' => $p])],
            ]" />
        </section>
    @endif
</x-layouts.app>
