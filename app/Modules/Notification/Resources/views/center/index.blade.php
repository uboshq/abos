{{--
    ⭐ বিজ্ঞপ্তি কেন্দ্র — কোম্পানির সব খবর (মালিকের স্পেক §২, §৪; বিজ্ঞপ্তি ব্যবস্থাপনা, ধাপ ১)।
    ⓘ প্রতিটা সারি একটা ঘটনা: কতজন পেলেন, কতজন পড়লেন। শিরোনামে চাপলে কে কে পেলেন আর পড়লেন।
    ⛔ নাগালের শাখার খবরই ([[NotificationCenterController]])। আর্কাইভের বোতাম কেবল যাঁর `notification.manage` আছে।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('notification::center.title') }}</x-slot:title>

    <div class="space-y-4">
        <x-ui.toolbar :title="__('notification::center.title')"
                      :search-placeholder="__('notification::center.search')"
                      :sort="['newest' => __('core.notify.sort.newest'), 'oldest' => __('core.notify.sort.oldest'), 'priority' => __('core.notify.sort.priority'), 'recipients' => __('notification::center.sort_recipients')]"
                      :export="false" :share="false">
            <label class="grid gap-1 text-2xs text-(--color-ink-muted)">
                {{ __('core.notify.module_label') }}
                <select name="module" class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
                    <option value="">{{ __('core.notify.any') }}</option>
                    @foreach ($modules as $module)
                        <option value="{{ $module }}" @selected(($filters['module'] ?? '') === $module)>{{ \App\Core\Support\NotificationKinds::sourceLabel($module) }}</option>
                    @endforeach
                </select>
            </label>
            <label class="grid gap-1 text-2xs text-(--color-ink-muted)">
                {{ __('core.notify.category_label') }}
                <select name="category" class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
                    <option value="">{{ __('core.notify.any') }}</option>
                    @foreach (\App\Core\Support\NotificationKinds::CATEGORIES as $category)
                        <option value="{{ $category }}" @selected(($filters['category'] ?? '') === $category)>{{ __('core.notify.category.'.$category) }}</option>
                    @endforeach
                </select>
            </label>
            <label class="grid gap-1 text-2xs text-(--color-ink-muted)">
                {{ __('core.notify.priority_label') }}
                <select name="priority" class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
                    <option value="">{{ __('core.notify.any') }}</option>
                    @foreach (\App\Core\Support\NotificationKinds::PRIORITIES as $priority)
                        <option value="{{ $priority }}" @selected(($filters['priority'] ?? '') === $priority)>{{ __('core.notify.priority.'.$priority) }}</option>
                    @endforeach
                </select>
            </label>
            <label class="grid gap-1 text-2xs text-(--color-ink-muted)">
                {{ __('notification::center.from') }}
                <input type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
            </label>
            <label class="grid gap-1 text-2xs text-(--color-ink-muted)">
                {{ __('notification::center.to') }}
                <input type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
            </label>
            <label class="flex items-center gap-2 text-2xs text-(--color-ink-muted)">
                <input type="checkbox" name="archived" value="1" @checked(! empty($filters['archived']))>
                {{ __('notification::center.archived_only') }}
            </label>
        </x-ui.toolbar>

        <p class="text-sm text-(--color-ink-muted)">{{ __('notification::center.note') }}</p>

        @if (session('saved'))
            <p role="status" class="rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm text-(--color-badge-success-ink)">{{ session('saved') }}</p>
        @endif

        @if ($rows->isEmpty())
            <p class="text-sm text-(--color-ink-muted)">{{ __('notification::center.empty') }}</p>
        @else
            <form method="POST" action="{{ route('notification.center.archive') }}" data-center-archive>
                @csrf

                @if ($canManage)
                    <div class="mb-2 flex flex-wrap items-center gap-2 text-sm">
                        <span class="text-(--color-ink-muted)">{{ __('notification::center.selected') }}:</span>
                        <button type="submit" name="action" value="{{ empty($filters['archived']) ? 'archive' : 'restore' }}"
                                class="rounded-(--radius-field) border border-(--color-border) px-2 py-1 text-2xs hover:bg-(--color-surface-hover)">
                            {{ __('notification::center.'.(empty($filters['archived']) ? 'archive' : 'restore')) }}
                        </button>
                    </div>
                @endif

                <div class="overflow-x-auto">
                    <table class="ui-grid">
                        <thead class="bg-(--color-surface-sunken) text-2xs text-(--color-ink-muted)">
                            <tr>
                                @if ($canManage)
                                    <th class="w-8"></th>
                                @endif
                                <th class="text-start">{{ __('notification::center.col_title') }}</th>
                                <th class="text-start">{{ __('notification::center.col_source') }}</th>
                                <th class="text-start">{{ __('notification::center.col_priority') }}</th>
                                <th class="text-start">{{ __('notification::center.col_branch') }}</th>
                                <th class="text-end">{{ __('notification::center.col_recipients') }}</th>
                                <th class="text-end">{{ __('notification::center.col_read') }}</th>
                                <th class="text-start">{{ __('notification::center.col_when') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $event)
                                <tr class="border-t border-(--color-border)" data-center-event="{{ $event->id }}">
                                    @if ($canManage)
                                        <td><input type="checkbox" name="ids[]" value="{{ $event->id }}" aria-label="{{ $event->title }}"></td>
                                    @endif
                                    <td>
                                        <a href="{{ route('notification.center.show', $event) }}" class="hover:underline">{{ $event->title }}</a>
                                        <span class="block text-2xs text-(--color-ink-muted)">{{ __('core.notify.category.'.$event->category) }}</span>
                                    </td>
                                    <td class="text-2xs">{{ \App\Core\Support\NotificationKinds::sourceLabel($event->module) }}</td>
                                    <td class="text-2xs">{{ __('core.notify.priority.'.$event->priority) }}</td>
                                    <td class="text-2xs">{{ $event->branch?->name() ?? __('notification::center.no_branch') }}</td>
                                    <td class="num text-end tabular-nums">{{ $event->recipients }}</td>
                                    <td class="num text-end tabular-nums">{{ $event->read_count }}</td>
                                    <td class="whitespace-nowrap text-2xs text-(--color-ink-muted)">{{ $event->created_at?->format('d/m/Y H:i') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </form>
        @endif

        <x-ui.pager :rows="$rows" />
        <x-ui.list-totals :rows="$rows" />
    </div>
</x-layouts.app>
