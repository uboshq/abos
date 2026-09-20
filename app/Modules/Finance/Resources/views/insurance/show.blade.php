{{--
    একটা পলিসি — কী, কোথায়, কত, কবে পর্যন্ত; নিচে প্রতি মেয়াদের প্রিমিয়াম।

    ⭐ ফিতাটা এখানেও: "এই খাতা → পরিশোধ ভাউচার → খতিয়ান"। প্রিমিয়ামের
    সারিতে "প্রিমিয়াম দিন" পরিশোধ ভাউচার খোলে; পোস্ট হলে সারিটা নিজে
    "দেওয়া হয়েছে" হয়, ভাউচারের নম্বরসহ ([[InsurancePremium::settleWith]])।
--}}
@php
    use App\Core\Support\Money;

    /* ⓘ টাকার যোগ bcmath-এ — float-এ পয়সা হারায়,
       আর এই সংখ্যাটাই বলে প্রিমিয়াম আর কত বাকি */
    $paid = $policy->premiums->filter->isPaid()
        ->reduce(fn ($c, $p) => bcadd($c, (string) $p->amount, 4), '0');
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $policy->policy_no }} — {{ __('finance::insurance.title') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('finance::insurance.title').' — '.$policy->policy_no"
                          :subtitle="$policy->subject.' · '.__('finance::insurance.covers_'.$policy->covers)">
            <x-slot:actions>
                @can('finance.insurance.manage')
                    <x-ui.button tone="secondary" :href="route('finance.insurance.edit', $policy)">
                        {{ __('finance::insurance.edit') }}
                    </x-ui.button>
                    <x-ui.button tone="primary" :href="route('finance.insurance.renew_form', $policy)">
                        {{ __('finance::insurance.renew') }}
                    </x-ui.button>
                @endcan
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>

    @if (session('saved'))
        <p role="status" class="mb-3 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2
                                text-sm text-(--color-badge-success-ink)">{{ session('saved') }}</p>
    @endif

    <section data-boxed
             class="mb-4 max-w-5xl rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card) p-4">
        <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <dt class="text-2xs text-(--color-ink-muted)">{{ __('finance::insurance.insurer') }}</dt>
                <dd class="font-medium">
                    @include('finance::institution.partials.link', [
                        'id' => $policy->institution_id,
                        'label' => $policy->institution?->label() ?? '—',
                    ])
                </dd>
            </div>
            <div>
                <dt class="text-2xs text-(--color-ink-muted)">{{ __('finance::insurance.sum_insured') }}</dt>
                <dd class="font-medium tabular-nums">{{ Money::format($policy->sum_insured) }}</dd>
            </div>
            <div>
                <dt class="text-2xs text-(--color-ink-muted)">{{ __('finance::insurance.premium') }}</dt>
                <dd class="font-medium tabular-nums">{{ Money::format($policy->premium) }}</dd>
            </div>
            <div>
                <dt class="text-2xs text-(--color-ink-muted)">{{ __('finance::insurance.period') }}</dt>
                <dd class="font-medium">
                    {{ $policy->starts_on->format('d M Y') }} →
                    @include('finance::insurance.partials.renewal', ['policy' => $policy])
                </dd>
            </div>
            @if (filled($policy->notes))
                <div class="sm:col-span-2 lg:col-span-4">
                    <dt class="text-2xs text-(--color-ink-muted)">{{ __('finance::insurance.notes') }}</dt>
                    <dd>{{ $policy->notes }}</dd>
                </div>
            @endif
        </dl>
    </section>

    @include('finance::partials.handoff', ['voucher' => 'payment'])

    <section data-boxed
             class="max-w-5xl overflow-hidden rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card)">
        <h2 class="border-b border-(--color-border) px-4 py-2 text-sm font-semibold">
            {{ __('finance::insurance.premiums') }}
            <span class="ms-2 text-2xs font-normal text-(--color-ink-muted)">
                {{ __('finance::insurance.total_paid') }}: {{ Money::format($paid) }}
            </span>
        </h2>

        <x-ui.table :rows="$policy->premiums" :empty="'—'" :columns="[
            ['key' => 'period', 'label' => __('finance::insurance.period'),
             'render' => fn ($p) => $p->period_from->format('d M Y').' → '.$p->period_to->format('d M Y')],
            ['key' => 'amount', 'label' => __('finance::insurance.premium'), 'numeric' => true, 'width' => '10rem',
             'render' => fn ($p) => Money::format($p->amount)],
            ['key' => 'status', 'label' => __('finance::insurance.state'), 'width' => '16rem',
             'render' => fn ($p) => view('finance::insurance.partials.pay', ['premium' => $p, 'policy' => $policy])],
        ]" />
    </section>
</x-layouts.app>
