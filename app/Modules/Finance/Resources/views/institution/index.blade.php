{{--
    আর্থিক প্রতিষ্ঠান — মূলধনের পাতার ধাঁচে (মালিকের নমুনা, ২০ সেপ্টেম্বর ২০২৬):
    শিরোনাম · বর্ণনা · [+ নতুন] → ধরনের ট্যাব → তালিকা।

    ⓘ কেন তালিকাটা এখানে, আর কেন কেবল অর্থে: মালিকের কথায় *"eta sudu
    ekhanei bebohar hobe"* — ব্যাংকের সুবিধা, আমানত আর বীমা, তিনটাই এই
    মডিউলের। বিস্তার মাইগ্রেশনের মাথায়।
--}}
@php
    use App\Modules\Finance\Models\Institution;

    $tabs = ['' => __('finance::institution.tab_all')];

    foreach (Institution::KINDS as $k) {
        $tabs[$k] = __('finance::institution.kind_'.$k);
    }

    $columns = [
        [
            'key' => 'name',
            'label' => __('finance::institution.name_en'),
            'render' => fn ($i) => view('finance::institution.partials.name-link', ['institution' => $i]),
        ],
        ['key' => 'short_code', 'label' => __('finance::institution.short_code'), 'width' => '7rem',
            'render' => fn ($i) => $i->short_code ?: '—'],
        ['key' => 'kind', 'label' => __('finance::institution.kind'), 'width' => '11rem',
            'render' => fn ($i) => __('finance::institution.kind_'.$i->kind)],
        ['key' => 'branch_name', 'label' => __('finance::institution.branch_name'), 'width' => '10rem',
            'render' => fn ($i) => $i->branch_name ?: '—'],
        ['key' => 'contact_person', 'label' => __('finance::institution.contact_person'), 'width' => '10rem',
            'render' => fn ($i) => $i->contact_person ?: '—'],
        ['key' => 'phone', 'label' => __('finance::institution.phone'), 'width' => '9rem',
            'render' => fn ($i) => $i->phone ?: '—'],
        ['key' => 'is_active', 'label' => __('finance::institution.state'), 'width' => '6rem',
            'render' => fn ($i) => $i->is_active ? __('finance::institution.active') : __('finance::institution.inactive')],
        [
            'key' => 'actions',
            'label' => __('core.table.actions'),
            'width' => '8rem',
            'render' => fn ($i) => view('finance::institution.partials.row-actions', ['institution' => $i]),
        ],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('finance::institution.title') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('finance::institution.title')"
                          :subtitle="__('finance::institution.subtitle')">
            <x-slot:actions>
                @can('finance.institution.manage')
                    <x-ui.button tone="primary" icon="plus"
                                 :href="route('finance.institution.create', $kind ? ['kind' => $kind] : [])">
                        {{ __('finance::institution.new') }}
                    </x-ui.button>
                @endcan
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>

    @if (session('saved'))
        <p role="status" class="mb-3 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2
                                text-sm text-(--color-badge-success-ink)">{{ session('saved') }}</p>
    @endif

    <nav class="mb-3 flex flex-wrap gap-1 border-b border-(--color-border) text-sm"
         aria-label="{{ __('finance::institution.title') }}">
        @foreach ($tabs as $key => $label)
            @php $on = (string) $kind === (string) $key; @endphp
            <a href="{{ route('finance.institution.index', $key === '' ? [] : ['kind' => $key]) }}"
               @if ($on) aria-current="page" @endif
               class="-mb-px flex min-h-(--spacing-touch) items-center gap-2 border-b-2 px-3
                      {{ $on
                          ? 'border-(--color-brand-500) font-semibold text-(--color-ink)'
                          : 'border-transparent text-(--color-ink-muted) hover:text-(--color-ink)' }}">
                {{ $label }}
                <span class="rounded-full bg-(--color-surface-sunken) px-2 text-2xs text-(--color-ink-muted)">
                    {{ $key === '' ? $counts->sum() : ($counts[$key] ?? 0) }}
                </span>
            </a>
        @endforeach
    </nav>

    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <x-ui.table :rows="$institutions" :columns="$columns"
                    :empty="__('finance::institution.none_yet')" />

        <x-ui.pager :rows="$institutions" />
    </div>
</x-layouts.app>
