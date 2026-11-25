{{--
    ⭐ একটা খবরের বিস্তারিত — কে কে পেলেন, কে দেখলেন, কে পড়লেন (মালিকের স্পেক §১০: পৌঁছানো আর পড়া আলাদা)।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $event->title }}</x-slot:title>

    <x-ui.errors />

    <div class="space-y-4" data-center-detail>
        <x-ui.page-header :title="$event->title">
            <x-slot:actions>
                <x-ui.button tone="secondary" :href="route('notification.center.index')">{{ __('notification::center.back') }}</x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>

        <section class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            @if ($event->body)
                <p class="mt-1 text-sm text-(--color-ink-muted)">{{ $event->body }}</p>
            @endif

            <dl class="mt-3 grid gap-2 text-sm sm:grid-cols-3">
                <div><dt class="text-2xs text-(--color-ink-muted)">{{ __('notification::center.col_source') }}</dt><dd>{{ \App\Core\Support\NotificationKinds::sourceLabel($event->module) }}</dd></div>
                <div><dt class="text-2xs text-(--color-ink-muted)">{{ __('notification::center.type') }}</dt><dd class="font-mono text-2xs">{{ $event->type }}</dd></div>
                <div><dt class="text-2xs text-(--color-ink-muted)">{{ __('notification::center.col_priority') }}</dt><dd>{{ __('core.notify.priority.'.$event->priority) }} · {{ __('core.notify.category.'.$event->category) }}</dd></div>
                <div><dt class="text-2xs text-(--color-ink-muted)">{{ __('notification::center.col_branch') }}</dt><dd>{{ $event->branch?->name() ?? __('notification::center.no_branch') }}</dd></div>
                <div><dt class="text-2xs text-(--color-ink-muted)">{{ __('notification::center.by') }}</dt><dd>{{ $event->actor?->name ?? __('notification::center.nobody') }}</dd></div>
                <div><dt class="text-2xs text-(--color-ink-muted)">{{ __('notification::center.col_when') }}</dt><dd>{{ \App\Core\Support\DateFormat::formatWithTime($event->created_at) }}</dd></div>
                @if ($event->idempotency_key)
                    <div class="sm:col-span-3"><dt class="text-2xs text-(--color-ink-muted)">{{ __('notification::center.key') }}</dt><dd class="font-mono text-2xs">{{ $event->idempotency_key }}</dd></div>
                @endif
            </dl>
        </section>

        <section class="overflow-x-auto rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
            <h2 class="p-4 pb-2 font-semibold">{{ __('notification::center.recipients') }}</h2>
            <table class="ui-grid">
                <thead class="bg-(--color-surface-sunken) text-2xs text-(--color-ink-muted)">
                    <tr>
                        <th class="text-start">{{ __('notification::center.recipient') }}</th>
                        <th class="text-start">{{ __('notification::center.seen') }}</th>
                        <th class="text-start">{{ __('notification::center.read') }}</th>
                        <th class="text-start">{{ __('notification::center.archived_by_user') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($deliveries as $delivery)
                        <tr class="border-t border-(--color-border)" data-center-recipient="{{ $delivery->user_id }}">
                            <td>{{ $delivery->user?->name }}</td>
                            <td class="text-2xs">{{ \App\Core\Support\DateFormat::formatWithTime($delivery->seen_at) ?? __('notification::center.not_yet') }}</td>
                            <td class="text-2xs">{{ \App\Core\Support\DateFormat::formatWithTime($delivery->read_at) ?? __('notification::center.not_yet') }}</td>
                            <td class="text-2xs">{{ (\App\Core\Support\DateFormat::formatWithTime($delivery->archived_at) ?: '—') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    </div>
</x-layouts.app>
