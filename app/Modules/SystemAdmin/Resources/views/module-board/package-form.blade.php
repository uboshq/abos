{{-- ⭐ এক প্যাকেজ — নাম আর মডিউল; নিচে কোন শাখায় বসবে ([[ModulePackageController]]) --}}
@php
    $title = $package->exists ? $package->name() : __('system_admin::module_board.package_new');
    $branchColumns = [
                        ['key' => 'pick', 'label' => __('system_admin::module_board.pick'), 'width' => '3rem', 'render' => fn ($b) => new \Illuminate\Support\HtmlString('<input type="checkbox" name="branches[]" value="'.(int) $b->id.'" class="size-4" aria-label="'.e($b->name()).'">')],
                        ['key' => 'name', 'label' => __('core.company.branch'), 'render' => fn ($b) => $b->name()],
                        ['key' => 'package', 'label' => __('system_admin::module_board.package'), 'render' => fn ($b) => isset($given[(int) $b->id]) ? ($given[(int) $b->id]['custom'] ? __('system_admin::module_board.package_custom', ['name' => $given[(int) $b->id]['name']]) : $given[(int) $b->id]['name']) : '—'],
                    ];
@endphp
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $title }}</x-slot:title>
    <x-slot:header>
        <x-ui.page-header :title="$title" :info="$package->exists ? __('system_admin::module_board.package_spreads', ['n' => $followers]) : null" />
    </x-slot:header>

    @if (session('saved'))
        <div role="status" class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm text-(--color-badge-success-ink)">{{ session('saved') }}</div>
    @endif
    <x-ui.errors />

    <form method="POST" action="{{ $package->exists ? route('system_admin.module.packages.update', $package) : route('system_admin.module.packages.store') }}" class="space-y-4">
        @csrf
        @if ($package->exists)
            @method('PUT')
        @endif
        <div class="grid gap-4 sm:grid-cols-2">
            <x-ui.field name="name_bn" :label="__('system_admin::module_board.name_bn')" :value="old('name_bn', $package->name_bn)" />
            <x-ui.field name="name_en" :label="__('system_admin::module_board.name_en')" :value="old('name_en', $package->name_en)" required />
        </div>
        @foreach ($groups as $group)
            <section data-boxed class= + CARD + r>
                <h2 class="border-b border-(--color-border) px-4 py-2 text-sm font-semibold">{{ $group['label'] }}</h2>
                <div class="grid gap-x-4 px-4 py-2 sm:grid-cols-2">
                    @foreach ($group['codes'] as $code)
                        <label class="flex items-center gap-3 py-2 text-sm">
                            <span class="flex-1">{{ $board->label($code) }}</span>
                            <input type="checkbox" role="switch" name="modules[]" value="{{ $code }}" class="size-4"
                                   @checked($board->locked($code) || in_array($code, (array) old('modules', $package->modules), true)) @disabled($board->locked($code))>
                        </label>
                    @endforeach
                </div>
            </section>
        @endforeach
        <x-ui.form-actions />
    </form>

    @if ($package->exists)
        <section class="mt-6">
            <h2 class="mb-2 text-sm font-semibold" title="{{ __('system_admin::module_board.package_assign_warns') }}">{{ __('system_admin::module_board.package_assign') }}</h2>
            <form method="POST" action="{{ route('system_admin.module.packages.assign', $package) }}" data-package-assign>
                @csrf
                <div data-boxed class="mb-3 overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
                    <x-ui.table :rows="$branches" :compact="true" :empty="__('core.empty.no_results')" :columns="$branchColumns" />
                    <x-ui.pager :rows="$branches" />
                </div>
                <p class="mb-3 text-2xs text-(--color-badge-pending-ink)">{{ __('system_admin::module_board.package_assign_warns') }}</p>
                <x-ui.button type="submit" tone="primary">{{ __('system_admin::module_board.package_assign') }}</x-ui.button>
            </form>
        </section>
    @endif
</x-layouts.app>
