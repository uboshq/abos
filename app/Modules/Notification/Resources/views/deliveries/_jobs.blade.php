{{--
    ⓘ কিউ আর ব্যর্থ-তালিকার একই টেবিল — কার কাছে, কোন মাধ্যমে, অবস্থা, কতবার, পরের চেষ্টা, শেষ ভুল (ধাপ ২)।
    ⛔ হাতে আবার চেষ্টা আর বাতিল কেবল `notification.retry` চাবিতে; বাতিলে কারণ লাগে, আর দুইটাই নিরীক্ষায় যায়।
    ⚠️ সারির ফর্মগুলো টেবিলের বাইরে, `form="…"` দিয়ে জোড়া — বাতিলের কারণের ঘরটাও তাই।
--}}
<x-ui.toolbar :title="$title" :search="false" :export="false" :share="false">
    <label class="grid gap-1 text-2xs text-(--color-ink-muted)">
        {{ __('notification::delivery.channel') }}
        <select name="channel" class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
            <option value="">{{ __('core.notify.any') }}</option>
            @foreach (\App\Models\NotificationChannel::ALL as $channel)
                <option value="{{ $channel }}" @selected(($filters['channel'] ?? '') === $channel)>{{ __('notification::channel.names.'.$channel) }}</option>
            @endforeach
        </select>
    </label>
    @if (count($statuses) > 1)
        <label class="grid gap-1 text-2xs text-(--color-ink-muted)">
            {{ __('notification::delivery.status') }}
            <select name="status" class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
                <option value="">{{ __('core.notify.any') }}</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ __('notification::delivery.statuses.'.$status) }}</option>
                @endforeach
            </select>
        </label>
    @endif
</x-ui.toolbar>

<p class="text-sm text-(--color-ink-muted)">{{ $note }}</p>

@if (session('saved'))
    <p role="status" class="rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2
                            text-sm text-(--color-badge-success-ink)">{{ session('saved') }}</p>
@endif
@if (session('failed'))
    <p role="alert" class="rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2
                           text-sm text-(--color-badge-danger-ink)">{{ session('failed') }}</p>
@endif

@if ($rows->isEmpty())
    <p class="text-sm text-(--color-ink-muted)">{{ __('notification::delivery.empty') }}</p>
@else
    <div class="overflow-x-auto">
        <table class="ui-grid">
            <thead class="bg-(--color-surface-sunken) text-2xs text-(--color-ink-muted)">
                <tr>
                    <th class="text-start">{{ __('notification::delivery.notification') }}</th>
                    <th class="text-start">{{ __('notification::delivery.recipient') }}</th>
                    <th class="text-start">{{ __('notification::delivery.channel') }}</th>
                    <th class="text-start">{{ __('notification::delivery.status') }}</th>
                    <th class="text-end">{{ __('notification::delivery.attempts') }}</th>
                    <th class="text-start">{{ __('notification::delivery.next') }}</th>
                    <th class="text-start">{{ __('notification::delivery.error') }}</th>
                    @if ($canRetry)
                        <th class="text-end"></th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $job)
                    <tr class="border-t border-(--color-border)" data-job="{{ $job->id }}" data-status="{{ $job->status }}">
                        <td class="text-2xs">{{ $job->notification?->title ?? __('notification::delivery.none') }}</td>
                        <td class="text-2xs">{{ $job->user?->name ?? __('notification::delivery.none') }}</td>
                        <td class="text-2xs">{{ __('notification::channel.names.'.$job->channel) }}</td>
                        <td class="text-2xs">{{ __('notification::delivery.statuses.'.$job->status) }}</td>
                        <td class="text-end text-2xs tabular-nums">{{ $job->attempts }} / {{ $job->max_attempts }}</td>
                        <td class="whitespace-nowrap text-2xs">{{ $job->next_attempt_at?->format('d/m/Y H:i') ?? __('notification::delivery.none') }}</td>
                        <td class="text-2xs">
                            @if ($job->error_kind)
                                <span class="text-(--color-ink-muted)">{{ __('notification::delivery.outcomes.'.$job->error_kind) }}:</span>
                            @endif
                            {{ $job->last_error ?? __('notification::delivery.none') }}
                        </td>
                        @if ($canRetry)
                            <td class="whitespace-nowrap text-end text-2xs">
                                @if ($job->status === \App\Models\NotificationJob::DEAD)
                                    <button type="submit" form="job-retry-{{ $job->id }}" class="text-(--color-brand-500) hover:underline">{{ __('notification::delivery.retry') }}</button>
                                @endif
                                @if ($job->status !== \App\Models\NotificationJob::PROCESSING)
                                <input type="text" name="resolution" form="job-cancel-{{ $job->id }}" maxlength="255" required
                                       placeholder="{{ __('notification::delivery.resolution') }}" aria-label="{{ __('notification::delivery.resolution') }}"
                                       class="ms-2 h-(--spacing-field) w-36 rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-1 text-2xs">
                                <button type="submit" form="job-cancel-{{ $job->id }}" class="ms-1 text-(--color-ink-muted) hover:underline">{{ __('notification::delivery.cancel') }}</button>
                                @endif
                            </td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if ($canRetry)
        @foreach ($rows as $job)
            <form id="job-retry-{{ $job->id }}" method="POST" action="{{ route('notification.deliveries.retry', $job) }}" class="hidden">@csrf</form>
            <form id="job-cancel-{{ $job->id }}" method="POST" action="{{ route('notification.deliveries.cancel', $job) }}" class="hidden">@csrf</form>
        @endforeach
    @endif
@endif

<x-ui.pager :rows="$rows" />
<x-ui.list-totals :rows="$rows" />
