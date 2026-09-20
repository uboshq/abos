{{--
    বীমা — মূলধনের পাতার ধাঁচে: শিরোনাম · বর্ণনা · [+ নতুন] → ট্যাব → তালিকা।

    ⭐ উপরে সতর্কবার্তা, যখন কোনো পলিসির নবায়ন ৩০ দিনের মধ্যে বা মেয়াদ
    পেরিয়ে গেছে — ট্যাবে না গিয়েও চোখে পড়ে। ⚠️ ট্যাবে লুকিয়ে রাখলে
    ঠিক যে মানুষটার দেখা দরকার তিনি ট্যাবটা খুলতেন না।
--}}
@php
    use App\Core\Support\Money;
    use App\Modules\Finance\Models\InsurancePremium;

    $tabs = [
        'all' => __('finance::insurance.tab_all'),
        'due' => __('finance::insurance.tab_due'),
        'unpaid' => __('finance::insurance.tab_unpaid'),
        'inactive' => __('finance::insurance.tab_inactive'),
    ];

    $columns = [
        ['key' => 'policy_no', 'label' => __('finance::insurance.policy_no'), 'width' => '9rem',
         'render' => fn ($p) => view('finance::insurance.partials.policy-link', ['policy' => $p])],
        ['key' => 'subject', 'label' => __('finance::insurance.subject'),
         'render' => fn ($p) => $p->subject],
        ['key' => 'covers', 'label' => __('finance::insurance.covers'), 'width' => '8rem',
         'render' => fn ($p) => __('finance::insurance.covers_'.$p->covers)],
        /* ⭐ বিমাকারীর নাম তার নিজের পাতায় যায় — মালিকের কথা, ২০ সেপ্টেম্বর ২০২৬:
           *"সব জায়গায় হাইপার লিংক দেওয়ার কথা"*। ⓘ পলিসি দেখে পরের প্রশ্নটা
           প্রায় সবসময় "ঐ ব্যাংকে আর কী কী আছে" — উত্তরটা ঐ পাতাতেই। */
        ['key' => 'insurer', 'label' => __('finance::insurance.insurer'), 'width' => '12rem',
         'render' => fn ($p) => view('finance::institution.partials.link', [
             'id' => $p->institution_id, 'label' => $p->institution?->label() ?? '—',
         ])],
        ['key' => 'sum_insured', 'label' => __('finance::insurance.sum_insured'), 'numeric' => true, 'width' => '9rem',
         'render' => fn ($p) => Money::format($p->sum_insured)],
        ['key' => 'premium', 'label' => __('finance::insurance.premium'), 'numeric' => true, 'width' => '8rem',
         'render' => fn ($p) => Money::format($p->premium)],
        ['key' => 'ends_on', 'label' => __('finance::insurance.ends_on'), 'width' => '9rem',
         'render' => fn ($p) => view('finance::insurance.partials.renewal', ['policy' => $p])],
        ['key' => 'unpaid', 'label' => __('finance::insurance.premiums'), 'width' => '8rem',
         'render' => fn ($p) => $p->premiums->contains(fn ($x) => $x->status === InsurancePremium::DRAFT)
             ? view('finance::insurance.partials.unpaid', ['policy' => $p])
             : '—'],
        ['key' => 'actions', 'label' => __('core.table.actions'), 'width' => '6rem',
         'render' => fn ($p) => view('finance::insurance.partials.row-actions', ['policy' => $p])],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('finance::insurance.title') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('finance::insurance.title')"
                          :subtitle="__('finance::insurance.subtitle')">
            <x-slot:actions>
                @can('finance.insurance.manage')
                    <x-ui.button tone="primary" icon="plus" :href="route('finance.insurance.create')">
                        {{ __('finance::insurance.new') }}
                    </x-ui.button>
                @endcan
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>

    @if (session('saved'))
        <p role="status" class="mb-3 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2
                                text-sm text-(--color-badge-success-ink)">{{ session('saved') }}</p>
    @endif

    @if (($counts['due'] ?? 0) > 0 && $tab !== 'due')
        <a href="{{ route('finance.insurance.index', ['tab' => 'due']) }}" role="alert"
           class="mb-3 block rounded-(--radius-field) bg-(--color-badge-pending-bg) px-3 py-2 text-sm
                  text-(--color-badge-pending-ink) underline-offset-2 hover:underline">
            {{ __('finance::insurance.due_banner', ['count' => $counts['due']]) }}
        </a>
    @endif

    <nav class="mb-3 flex flex-wrap gap-1 border-b border-(--color-border) text-sm"
         aria-label="{{ __('finance::insurance.title') }}">
        @foreach ($tabs as $key => $label)
            @php $on = $tab === $key; @endphp
            <a href="{{ route('finance.insurance.index', $key === 'all' ? [] : ['tab' => $key]) }}"
               @if ($on) aria-current="page" @endif
               class="-mb-px flex min-h-(--spacing-touch) items-center gap-2 border-b-2 px-3
                      {{ $on
                          ? 'border-(--color-brand-500) font-semibold text-(--color-ink)'
                          : 'border-transparent text-(--color-ink-muted) hover:text-(--color-ink)' }}">
                {{ $label }}
                <span class="rounded-full bg-(--color-surface-sunken) px-2 text-2xs text-(--color-ink-muted)">
                    {{ $counts[$key] ?? 0 }}
                </span>
            </a>
        @endforeach
    </nav>

    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <x-ui.table :rows="$policies" :columns="$columns"
                    :empty="__('finance::insurance.none_yet')" />

        <x-ui.pager :rows="$policies" />
    </div>
</x-layouts.app>
