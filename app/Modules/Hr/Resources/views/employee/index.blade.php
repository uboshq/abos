{{--
    কর্মীর তালিকা।

    ছেড়ে যাওয়া কর্মীরা ডিফল্টে নেই, কিন্তু মোছাও হয়নি — চেকবক্সে
    দেখা যায়। পুরনো বেতনশিটে নামটা লাগে, তাই মোছার কোনো পথ নেই।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('hr::menu.employees') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header
            :title="__('hr::menu.employees')"
            :subtitle="trans_choice('core.count.records', $employees->total(), ['count' => $employees->total()])">
            <x-slot:actions>
                @can('hr.employee.manage')
                    <x-ui.button tone="primary" icon="+" :href="route('hr.employee.create')">
                        {{ __('hr::action.new_employee') }}
                    </x-ui.button>
                @endcan
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    <div class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form method="GET" class="contents">
            <x-ui.toolbar :sort="$sortOptions"
                          :search-placeholder="__('hr::field.name')">
                <label class="flex min-h-(--spacing-touch) items-center gap-2 text-sm">
                    <input type="checkbox" name="left" value="1" @checked($showLeft) class="size-4">
                    {{ __('hr::action.show_left') }}
                </label>
            </x-ui.toolbar>
        </form>

        <x-ui.table
            :empty="$q ? __('core.empty.no_results') : __('hr::message.no_employees')"
            :rows="$employees"
            :columns="[
                ['key' => 'code', 'label' => __('hr::field.code'), 'width' => '8rem',
                 'render' => fn ($e) => view('hr::employee.partials.code', ['employee' => $e])],
                ['key' => 'name_en', 'label' => __('hr::field.name'),
                 'render' => fn ($e) => view('hr::employee.partials.name', ['employee' => $e])],
                ['key' => 'department', 'label' => __('hr::field.department'), 'width' => '12rem',
                 'render' => fn ($e) => $e->department?->name() ?? '—'],
                ['key' => 'designation', 'label' => __('hr::field.designation'), 'width' => '12rem',
                 'render' => fn ($e) => $e->designation?->name() ?? '—'],
                ['key' => 'mobile', 'label' => __('hr::field.mobile'), 'width' => '10rem',
                 'render' => fn ($e) => $e->mobile ?? '—'],
                ['key' => 'joining_date', 'label' => __('hr::field.joining_date'), 'width' => '10rem',
                 'render' => fn ($e) => $e->joining_date?->format('d M Y') ?? '—'],
            ]" />
    </div>

    <div class="mt-4">{{ $employees->links() }}</div>
</x-layouts.app>
