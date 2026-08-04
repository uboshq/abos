{{--
    একটা এলাকা — তার পথ, দায়িত্ব, আর নিচের এলাকাগুলো।

    পথের প্রতিটা ধাপ ক্লিকযোগ্য (নিয়ম ১): গাছের মধ্যে নিজের জায়গাটা
    এক নজরে, আর উপরে ওঠাও যায়।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $location->name() }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$location->name()"
                          :subtitle="__('master_data::level.' . $location->level) . ' · ' . $location->code">
            <x-slot:actions>
                @can('master_data.manage')
                    <x-ui.button tone="secondary" :href="route('master_data.location.edit', $location)">
                        {{ __('core.action.edit') }}
                    </x-ui.button>

                    @if ($location->is_active)
                        <form method="POST" action="{{ route('master_data.location.destroy', $location) }}"
                              onsubmit="return confirm('{{ __('master_data::message.deactivate_confirm') }}')">
                            @csrf
                            @method('DELETE')
                            <x-ui.button type="submit" tone="secondary">
                                {{ __('master_data::action.deactivate') }}
                            </x-ui.button>
                        </form>
                    @endif
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

    <section class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
            <h2 class="font-semibold">{{ __('master_data::field.path') }}</h2>

            <x-ui.badge :tone="$location->is_active ? 'success' : 'neutral'">
                {{ $location->is_active ? __('customer::state.active') : __('customer::state.inactive') }}
            </x-ui.badge>
        </div>

        <p class="text-sm">
            @foreach ($location->ancestors() as $ancestor)
                <a href="{{ route('master_data.location.show', $ancestor) }}"
                   class="text-(--color-brand-500) underline-offset-2 hover:underline">{{ $ancestor->name() }}</a>
                <span class="text-(--color-ink-placeholder)" aria-hidden="true">›</span>
            @endforeach
            <span class="font-medium">{{ $location->name() }}</span>
        </p>

        @if ($location->assignee)
            <p class="mt-3 flex items-center gap-2 text-sm">
                <span class="text-2xs text-(--color-ink-muted)">
                    {{ __('master_data::field.assigned_to') }}:
                </span>
                <x-ui.avatar :user="$location->assignee" size="sm" />
                {{ $location->assignee->name }}
            </p>
        @endif
    </section>

    @if ($childLevel !== null)
        <section class="mt-4 overflow-hidden rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card)">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-(--color-border) px-4 py-3">
                <h2 class="font-semibold">{{ __('master_data::level.' . $childLevel) }}</h2>

                @can('master_data.manage')
                    <x-ui.button tone="secondary"
                                 :href="route('master_data.location.create', ['level' => $childLevel, 'parent' => $location->id])">
                        {{ __('master_data::action.new') }}
                    </x-ui.button>
                @endcan
            </div>

            <x-ui.table
                :empty="__('master_data::message.none_yet')"
                :rows="$location->children"
                :columns="[
                    ['key' => 'code', 'label' => __('master_data::field.code'), 'width' => '9rem',
                     'render' => fn ($l) => view('master_data::location.partials.code', ['location' => $l])],
                    ['key' => 'name_en', 'label' => __('master_data::field.name'),
                     'render' => fn ($l) => $l->name()],
                    ['key' => 'assigned_to', 'label' => __('master_data::field.assigned_to'), 'width' => '12rem',
                     'render' => fn ($l) => $l->assignee?->name ?? '—'],
                ]" />
        </section>
    @endif
</x-layouts.app>
