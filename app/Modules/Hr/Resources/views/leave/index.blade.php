{{-- ছুটির আবেদনের তালিকা। --}}
@php
    $columns = [
        ['key' => 'employee', 'label' => __('hr::field.name'),
         'render' => fn ($a) => $a->employee?->label() ?? '—'],
        ['key' => 'type', 'label' => __('hr::field.leave_type'), 'width' => '12rem',
         'render' => fn ($a) => $a->leaveType?->name() ?? '—'],
        ['key' => 'from', 'label' => __('hr::field.from_date'), 'width' => '9rem',
         'render' => fn ($a) => $a->from_date->format('d M Y')],
        ['key' => 'to', 'label' => __('hr::field.to_date'), 'width' => '9rem',
         'render' => fn ($a) => $a->to_date->format('d M Y')],
        ['key' => 'days', 'label' => __('hr::field.days'), 'numeric' => true, 'width' => '6rem',
         'render' => fn ($a) => rtrim(rtrim((string) $a->days, '0'), '.')],
        ['key' => 'status', 'label' => __('hr::field.status'), 'width' => '8rem',
         'render' => fn ($a) => __('hr::kind.' . $a->status)],
        ['key' => 'actions', 'label' => '—', 'width' => '14rem',
         'render' => fn ($a) => view('hr::leave.partials.actions', ['application' => $a])],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('hr::menu.leave') }}</x-slot:title>

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

    <div class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form method="GET" class="contents">
            <x-ui.toolbar :title="__('hr::menu.leave')"
                :sort="$sortOptions"
                :columns="$columns" :search="false">
        <x-slot:actions>
            @can('hr.leave.manage')
                    <x-ui.button :href="route('hr.leave_type.index')">
                        {{ __('hr::menu.leave_types') }}
                    </x-ui.button>
                    <x-ui.button tone="primary" icon="plus" :href="route('hr.leave.create')">
                        {{ __('hr::action.apply_leave') }}
                    </x-ui.button>
                @endcan
        </x-slot:actions>
                <label class="flex min-h-(--spacing-touch) items-center gap-2 text-sm">
                    <input type="checkbox" name="pending" value="1" @checked($onlyPending) class="size-4">
                    {{ __('hr::action.only_pending') }}
                </label>
            </x-ui.toolbar>
        </form>

        <x-ui.table
            :empty="__('hr::message.no_leave')"
            :rows="$applications"
            :columns="$columns" />
    </div>

    <div class="mt-4">{{ $applications->links() }}</div>
</x-layouts.app>
