{{--
    ⭐ আমার বিজ্ঞপ্তি — নিজের সব খবর (মালিকের স্পেক §২, §৪, §৯খ; বিজ্ঞপ্তি ব্যবস্থাপনা, ধাপ ১)।

    ⓘ সব / না-পড়া / পড়া / আর্কাইভ ট্যাব, খোঁজা, ধরন-গুরুত্ব-মডিউলের ছাঁকনি, সাজানো, আর বাছাগুলো একসাথে পড়া বা আর্কাইভ।
    ⛔ এখানে কেবল নিজের খবর, আর কাগজের শাখা নাগালের বাইরে গেলে সেই খবর লুকায় ([[Notification::scopeVisibleTo()]])।

    ⚠️ সারির ছোট বোতামগুলো (পড়া, আর্কাইভ) টেবিলের বাইরের নিজের ফর্মে — `form="…"` দিয়ে জোড়া; ফর্মের ভিতরে ফর্ম চলে না,
    আর বাছাইয়ের বড় ফর্মটা গোটা টেবিল ঘিরে থাকে।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('core.notify.mine') }}</x-slot:title>

    <div class="space-y-4">
        <x-ui.toolbar :title="__('core.notify.mine')"
                      :search-placeholder="__('core.notify.search')"
                      :sort="['newest' => __('core.notify.sort.newest'), 'oldest' => __('core.notify.sort.oldest'), 'priority' => __('core.notify.sort.priority')]"
                      :export="false" :share="false">
            <input type="hidden" name="tab" value="{{ $tab }}">

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
                {{ __('core.notify.module_label') }}
                <select name="module" class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
                    <option value="">{{ __('core.notify.any') }}</option>
                    @foreach ($modules as $module)
                        <option value="{{ $module }}" @selected(($filters['module'] ?? '') === $module)>{{ \App\Core\Support\NotificationKinds::sourceLabel($module) }}</option>
                    @endforeach
                </select>
            </label>
        </x-ui.toolbar>

        <x-ui.list-tabs :label="__('core.notify.mine')" :tabs="collect(['all', 'unread', 'read', 'archived'])->map(fn ($key) => [
            'key' => $key,
            'label' => __('core.notify.tab.'.$key),
            'url' => route('notifications.index', array_merge(request()->except('page', 'tab'), ['tab' => $key])),
            'count' => match ($key) { 'all' => $total, 'unread' => $unread, default => null },
            'active' => $tab === $key,
        ])->all()" />

        @if (session('saved'))
            <p role="status" class="rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2
                                    text-sm text-(--color-badge-success-ink)">{{ session('saved') }}</p>
        @endif

        @if ($rows->isEmpty())
            <p class="text-sm text-(--color-ink-muted)">{{ __('core.notify.empty') }}</p>
        @else
            <form method="POST" action="{{ route('notifications.bulk') }}" data-notify-bulk>
                @csrf

                <div class="mb-2 flex flex-wrap items-center gap-2 text-sm">
                    <span class="text-(--color-ink-muted)">{{ __('core.notify.selected') }}:</span>
                    @foreach ($tab === 'archived' ? ['restore'] : ['read', 'unread', 'archive'] as $action)
                        <button type="submit" name="action" value="{{ $action }}"
                                class="rounded-(--radius-field) border border-(--color-border) px-2 py-1 text-2xs hover:bg-(--color-surface-hover)">
                            {{ __('core.notify.action.'.$action) }}
                        </button>
                    @endforeach
                </div>

                <div class="overflow-x-auto">
                    <table class="ui-grid">
                        <thead class="bg-(--color-surface-sunken) text-2xs text-(--color-ink-muted)">
                            <tr>
                                <th class="w-8"></th>
                                <th class="text-start">{{ __('core.notify.col.title') }}</th>
                                <th class="text-start">{{ __('core.notify.col.source') }}</th>
                                <th class="text-start">{{ __('core.notify.col.priority') }}</th>
                                <th class="text-start">{{ __('core.notify.col.when') }}</th>
                                <th class="text-start">{{ __('core.notify.col.state') }}</th>
                                <th class="text-end"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $note)
                                <tr data-notification="{{ $note->id }}" @class([
                                    'border-t border-(--color-border)',
                                    'font-medium' => $note->isUnread(),
                                ])>
                                    <td><input type="checkbox" name="ids[]" value="{{ $note->id }}" aria-label="{{ $note->title }}"></td>
                                    <td>
                                        <a href="{{ route('notifications.open', $note) }}" class="hover:underline">{{ $note->title }}</a>
                                        @if ($note->body)
                                            <span class="block text-2xs font-normal text-(--color-ink-muted)">{{ $note->body }}</span>
                                        @endif
                                    </td>
                                    <td class="text-2xs">{{ \App\Core\Support\NotificationKinds::sourceLabel($note->module) }}</td>
                                    <td class="text-2xs">
                                        <span @class([
                                            'rounded-full px-1.5 py-0.5',
                                            'bg-(--color-danger) text-white' => $note->priority === 'critical',
                                            'bg-(--color-badge-warning-bg) text-(--color-badge-warning-ink)' => $note->priority === 'high',
                                            'text-(--color-ink-muted)' => in_array($note->priority, ['normal', 'low'], true),
                                        ]) data-priority="{{ $note->priority }}">{{ __('core.notify.priority.'.$note->priority) }}</span>
                                    </td>
                                    <td class="whitespace-nowrap text-2xs text-(--color-ink-muted)" title="{{ $note->created_at?->toDateTimeString() }}">{{ $note->created_at?->diffForHumans() }}</td>
                                    <td class="text-2xs">{{ __('core.notify.state.'.($note->isArchived() ? 'archived' : ($note->isUnread() ? 'unread' : 'read'))) }}</td>
                                    <td class="whitespace-nowrap text-end text-2xs">
                                        @if ($note->isUnread())
                                            <button type="submit" form="note-read-{{ $note->id }}" class="text-(--color-brand-500) hover:underline">{{ __('core.notify.action.read') }}</button>
                                        @endif
                                        <button type="submit" form="note-archive-{{ $note->id }}" class="ms-2 text-(--color-ink-muted) hover:underline">
                                            {{ __('core.notify.action.'.($note->isArchived() ? 'restore' : 'archive')) }}
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </form>

            @foreach ($rows as $note)
                @if ($note->isUnread())
                    <form id="note-read-{{ $note->id }}" method="POST" action="{{ route('notifications.read', $note) }}" class="hidden">@csrf</form>
                @endif
                <form id="note-archive-{{ $note->id }}" method="POST" action="{{ route('notifications.archive', $note) }}" class="hidden">@csrf</form>
            @endforeach
        @endif

        <x-ui.pager :rows="$rows" />
        <x-ui.list-totals :rows="$rows" />
    </div>
</x-layouts.app>
