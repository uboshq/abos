{{--
    ⭐ নিয়মের তালিকা — কোন ঘটনায়, কোন শর্তে, কার কাছে; চালু কি না, কোন সংস্করণ (মালিকের স্পেক §৪ "Rules", §৮; ধাপ ৩)।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('notification::rule.title') }}</x-slot:title>

    <div class="space-y-4">
        <x-ui.toolbar :title="__('notification::rule.title')" :search-placeholder="__('notification::rule.search')" :export="false" :share="false">
            <label class="grid gap-1 text-2xs text-(--color-ink-muted)">
                {{ __('notification::rule.module') }}
                <select name="module" class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
                    <option value="">{{ __('core.notify.any') }}</option>
                    @foreach ($modules as $module)
                        <option value="{{ $module }}" @selected(($filters['module'] ?? '') === $module)>{{ \App\Core\Support\NotificationKinds::sourceLabel($module) }}</option>
                    @endforeach
                </select>
            </label>
            <label class="grid gap-1 text-2xs text-(--color-ink-muted)">
                {{ __('notification::rule.state') }}
                <select name="state" class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
                    <option value="">{{ __('core.notify.any') }}</option>
                    @foreach (['active', 'inactive'] as $state)
                        <option value="{{ $state }}" @selected(($filters['state'] ?? '') === $state)>{{ __('notification::rule.states.'.$state) }}</option>
                    @endforeach
                </select>
            </label>
        </x-ui.toolbar>

        <div class="flex items-center justify-between gap-2">
            <p class="text-sm text-(--color-ink-muted)">{{ __('notification::rule.note') }}</p>
            <a href="{{ route('notification.rules.create') }}" class="shrink-0 rounded-(--radius-field) bg-(--color-brand-500) px-3 py-1.5 text-sm text-white">{{ __('notification::rule.new') }}</a>
        </div>

        @include('notification::partials.flash')

        @if ($rows->isEmpty())
            <p class="text-sm text-(--color-ink-muted)">{{ __('notification::rule.empty') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="ui-grid">
                    <thead class="bg-(--color-surface-sunken) text-2xs text-(--color-ink-muted)">
                        <tr>
                            <th class="text-start">{{ __('notification::rule.name') }}</th>
                            <th class="text-start">{{ __('notification::rule.event') }}</th>
                            <th class="text-start">{{ __('notification::rule.conditions') }}</th>
                            <th class="text-start">{{ __('notification::rule.priority') }}</th>
                            <th class="text-start">{{ __('notification::rule.template') }}</th>
                            <th class="text-start">{{ __('notification::rule.state') }}</th>
                            <th class="text-end">{{ __('notification::rule.version') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $rule)
                            <tr class="border-t border-(--color-border)" data-rule="{{ $rule->id }}">
                                <td><a href="{{ route('notification.rules.edit', $rule) }}" class="hover:underline">{{ $rule->name }}</a></td>
                                <td class="text-2xs">
                                    {{ \App\Core\Support\NotificationKinds::sourceLabel($rule->module) }} ·
                                    {{ __(\App\Core\Support\NotificationKinds::all()[$rule->event] ?? $rule->event) }}
                                </td>
                                <td class="text-2xs">{{ count((array) $rule->conditions) }}</td>
                                <td class="text-2xs">{{ $rule->priority ? __('core.notify.priority.'.$rule->priority) : __('notification::rule.as_module') }}</td>
                                <td class="text-2xs">{{ $rule->template?->name ?? __('notification::rule.as_module') }}</td>
                                <td class="text-2xs">{{ __('notification::rule.states.'.($rule->is_active ? 'active' : 'inactive')) }}</td>
                                <td class="text-end text-2xs tabular-nums">v{{ $rule->version }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <x-ui.pager :rows="$rows" />
        <x-ui.list-totals :rows="$rows" />
    </div>
</x-layouts.app>
