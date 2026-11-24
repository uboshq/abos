{{-- বদলের ইতিহাস — কন্ট্রোল প্যানেলের দুই পাতায় একই --}}
@if (($history ?? []) !== [])
    @php($say = fn ($v) => match ((string) $v) { '1' => __('system_admin::control.value_on'), '0', '' => __('system_admin::control.value_off'), default => (string) $v })
    <details data-control-history class="mt-6 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <summary class="cursor-pointer px-4 py-3 text-sm font-semibold">{{ __('system_admin::control.history') }}</summary>
        <ul class="divide-y divide-(--color-border) border-t border-(--color-border)">
            @foreach ($history as $row)
                <li class="flex flex-wrap items-baseline gap-x-3 gap-y-1 px-4 py-2 text-sm">
                    <span class="tabular text-2xs text-(--color-ink-muted)">{{ \App\Core\Support\DateFormat::spoken($row['when']) }}</span>
                    <span class="font-medium">{{ $row['label'] }}</span>
                    <span class="text-(--color-ink-muted)">{{ $say($row['from']) }} → {{ $say($row['to']) }}</span>
                    <span class="text-2xs text-(--color-ink-muted)">{{ $row['who'] ?? '—' }}</span>
                </li>
            @endforeach
        </ul>
    </details>
@endif
