{{--
    ⭐ বিজ্ঞপ্তির আর্কাইভ — খোঁজা, ছাঁকা, আর চাবি থাকলে নামানো (মালিকের স্পেক §৪ "Archive"; ধাপ ৪)।
    ⓘ রাখার মেয়াদ পর্দায় লেখা; প্রতিটা সারি কেন্দ্রের বিস্তারিত পাতায় খোলে।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('notification::archive.title') }}</x-slot:title>

    <div class="space-y-4">
        <x-ui.toolbar :title="__('notification::archive.title')" :search-placeholder="__('notification::archive.search')" :export="$canExport" :share="false">
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
                {{ __('core.notify.priority_label') }}
                <select name="priority" class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
                    <option value="">{{ __('core.notify.any') }}</option>
                    @foreach (\App\Core\Support\NotificationKinds::PRIORITIES as $priority)
                        <option value="{{ $priority }}" @selected(($filters['priority'] ?? '') === $priority)>{{ __('core.notify.priority.'.$priority) }}</option>
                    @endforeach
                </select>
            </label>
            <label class="grid gap-1 text-2xs text-(--color-ink-muted)">
                {{ __('notification::delivery.from') }}
                <input type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
            </label>
            <label class="grid gap-1 text-2xs text-(--color-ink-muted)">
                {{ __('notification::delivery.to') }}
                <input type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
            </label>
        </x-ui.toolbar>

        <p class="text-sm text-(--color-ink-muted)" data-archive-policy>
            {{ $archiveDays > 0 ? __('notification::archive.policy_archive', ['days' => $archiveDays]) : __('notification::archive.policy_no_archive') }}
            {{ $retentionDays > 0 ? __('notification::archive.policy_retention', ['days' => max(\App\Core\Notifications\RetentionService::MIN_RETENTION_DAYS, $retentionDays)]) : __('notification::archive.policy_keep') }}
        </p>

        {{-- ⓘ সাধারণ টেবিল — রপ্তানি (CSV/XLSX) এটাই ধরে, চাবি থাকলে --}}
        @php
            $columns = [
                ['key' => 'title', 'label' => __('core.notify.col.title'), 'render' => fn ($e) => $e->title],
                ['key' => 'module', 'label' => __('core.notify.col.source'), 'width' => '9rem', 'render' => fn ($e) => \App\Core\Support\NotificationKinds::sourceLabel($e->module)],
                ['key' => 'priority', 'label' => __('core.notify.col.priority'), 'width' => '6rem', 'render' => fn ($e) => __('core.notify.priority.'.$e->priority)],
                ['key' => 'branch', 'label' => __('notification::archive.branch'), 'width' => '9rem', 'render' => fn ($e) => $e->branch?->name_bn ?? '—'],
                ['key' => 'recipients', 'label' => __('notification::archive.recipients'), 'numeric' => true, 'width' => '6rem', 'render' => fn ($e) => (string) $e->recipients],
                ['key' => 'created_at', 'label' => __('core.notify.col.when'), 'width' => '8rem', 'render' => fn ($e) => $e->created_at?->format('d/m/Y H:i')],
                ['key' => 'archived_at', 'label' => __('notification::archive.archived_at'), 'width' => '8rem', 'render' => fn ($e) => $e->archived_at?->format('d/m/Y H:i')],
            ];
        @endphp
        <div data-archive-table>
            <x-ui.table :rows="$rows" :columns="$columns" :empty="__('notification::archive.empty')"
                        :view-url="fn ($e) => route('notification.center.show', $e)" />
        </div>

        <x-ui.pager :rows="$rows" />
        <x-ui.list-totals :rows="$rows" />
    </div>
</x-layouts.app>
