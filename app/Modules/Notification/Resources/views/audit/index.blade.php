{{--
    ⭐ বিজ্ঞপ্তির নিরীক্ষার খাতা — কে, কী, কখন, কোনটায়, ফল (মালিকের স্পেক §১৩; বিজ্ঞপ্তি ব্যবস্থাপনা, ধাপ ১)। কেবল পড়া।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('notification::audit.title') }}</x-slot:title>

    <div class="space-y-4">
        <x-ui.toolbar :title="__('notification::audit.title')" :search="false" :export="false" :share="false">
            <label class="grid gap-1 text-2xs text-(--color-ink-muted)">
                {{ __('notification::audit.action') }}
                <select name="action" class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
                    <option value="">{{ __('core.notify.any') }}</option>
                    @foreach ($actions as $action)
                        <option value="{{ $action }}" @selected(($filters['action'] ?? '') === $action)>{{ __('notification::audit.actions.'.$action) }}</option>
                    @endforeach
                </select>
            </label>
            <label class="grid gap-1 text-2xs text-(--color-ink-muted)">
                {{ __('notification::audit.outcome') }}
                <select name="outcome" class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
                    <option value="">{{ __('core.notify.any') }}</option>
                    @foreach (['done', 'denied', 'failed'] as $outcome)
                        <option value="{{ $outcome }}" @selected(($filters['outcome'] ?? '') === $outcome)>{{ __('notification::audit.outcomes.'.$outcome) }}</option>
                    @endforeach
                </select>
            </label>
            <label class="grid gap-1 text-2xs text-(--color-ink-muted)">
                {{ __('notification::audit.from') }}
                <input type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
            </label>
            <label class="grid gap-1 text-2xs text-(--color-ink-muted)">
                {{ __('notification::audit.to') }}
                <input type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
            </label>
        </x-ui.toolbar>

        <p class="text-sm text-(--color-ink-muted)">{{ __('notification::audit.note') }}</p>

        @if ($rows->isEmpty())
            <p class="text-sm text-(--color-ink-muted)">{{ __('notification::audit.empty') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="ui-grid">
                    <thead class="bg-(--color-surface-sunken) text-2xs text-(--color-ink-muted)">
                        <tr>
                            <th class="text-start">{{ __('notification::audit.when') }}</th>
                            <th class="text-start">{{ __('notification::audit.actor') }}</th>
                            <th class="text-start">{{ __('notification::audit.action') }}</th>
                            <th class="text-start">{{ __('notification::audit.target') }}</th>
                            <th class="text-start">{{ __('notification::audit.outcome') }}</th>
                            <th class="text-start">{{ __('notification::audit.detail') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $log)
                            <tr class="border-t border-(--color-border)" data-audit-row="{{ $log->id }}">
                                <td class="whitespace-nowrap text-2xs">{{ $log->created_at?->format('d/m/Y H:i:s') }}</td>
                                <td class="text-2xs">{{ $log->actor?->name ?? __('notification::audit.system') }}</td>
                                <td class="text-2xs">{{ __('notification::audit.actions.'.$log->action) }}</td>
                                <td class="font-mono text-2xs">{{ $log->target_type ? $log->target_type.' #'.$log->target_id : '—' }}</td>
                                <td class="text-2xs">{{ __('notification::audit.outcomes.'.$log->outcome) }}</td>
                                <td class="font-mono text-2xs">{{ $log->detail ? json_encode($log->detail, JSON_UNESCAPED_UNICODE) : '—' }}</td>
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
