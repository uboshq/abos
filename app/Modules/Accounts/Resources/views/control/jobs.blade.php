{{--
    পটভূমির কাজ ও সতর্কতা — ফিন্যান্স মানচিত্রের §৩১।

    ── কেন, ২০ সেপ্টেম্বর ২০২৬ ─────────────────────────────────────────
    লাইভে সময়সূচির কাজগুলো ১৭ সেপ্টেম্বর থেকে চলছিল না (proc_open বন্ধ),
    আর রাতের ব্যাকআপ যাচাইও ব্যর্থ হচ্ছিল। কেউ জানতেন না, কারণ দেখার
    কোনো পর্দা ছিল না। ⓘ এখানে: কোন কাজ কখন চলার কথা, সারিতে কী অপেক্ষায়,
    কী ব্যর্থ, আর শেষ ব্যাকআপ কবে।
--}}
@php
    $taskColumns = [
        ['key' => 'what', 'label' => __('accounts::control.task')],
        ['key' => 'when', 'label' => __('accounts::control.expression'), 'width' => '10rem',
         'render' => fn ($t) => $t['when']],
        ['key' => 'next', 'label' => __('accounts::control.next_run'), 'width' => '12rem',
         'render' => fn ($t) => \App\Core\Support\DateFormat::formatWithTime($t['next']->toDateTimeString())],
    ];

    $failedColumns = [
        ['key' => 'queue', 'label' => __('accounts::control.queue'), 'width' => '8rem'],
        ['key' => 'exception', 'label' => __('core.table.description'),
         'render' => fn ($j) => \Illuminate\Support\Str::limit(strtok((string) $j->exception, "\n"), 200)],
        ['key' => 'failed_at', 'label' => __('accounts::control.failed_at'), 'width' => '12rem',
         'render' => fn ($j) => \App\Core\Support\DateFormat::formatWithTime($j->failed_at)],
    ];

    $backupOk = $lastBackup !== null && $lastBackup->status === 'success';
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('accounts::control.jobs_title') }}</x-slot:title>

    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <x-ui.toolbar :title="__('accounts::control.jobs_title')"
                      :subtitle="__('accounts::control.jobs_note')"
                      :search="false" :filter="false" :density="false"
                      :export="false" :share="false" />

        {{-- সতর্কতা — চারটা সংখ্যা, শূন্য না হলে লাল --}}
        <section class="grid gap-3 border-b border-(--color-border) p-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ([
                ['label' => __('accounts::control.unseen_errors'), 'value' => $unseenErrors, 'bad' => $unseenErrors > 0,
                 'url' => auth()->user()?->can('governance.error.view') ? route('governance.error.index') : null],
                ['label' => __('accounts::control.failed_jobs'), 'value' => $failedJobs->count(), 'bad' => $failedJobs->isNotEmpty(), 'url' => null],
                ['label' => __('accounts::control.queue_waiting'), 'value' => $waiting, 'bad' => false, 'url' => null],
                ['label' => __('accounts::control.last_backup'),
                 'value' => $lastBackup ? \App\Core\Support\DateFormat::formatWithTime($lastBackup->started_at) : __('accounts::control.backup_never'),
                 'bad' => ! $backupOk,
                 'url' => \Illuminate\Support\Facades\Route::has('backup.index') && auth()->user()?->can('backup.view') ? route('backup.index') : null],
            ] as $alert)
                <div class="rounded-(--radius-field) border p-3
                            {{ $alert['bad'] ? 'border-(--color-danger) bg-(--color-badge-danger-bg)' : 'border-(--color-border)' }}"
                     data-alert>
                    <p class="text-2xs text-(--color-ink-muted)">{{ $alert['label'] }}</p>
                    @if ($alert['url'])
                        <a href="{{ $alert['url'] }}" class="num text-lg font-semibold text-(--color-link) hover:underline">{{ $alert['value'] }}</a>
                    @else
                        <p class="num text-lg font-semibold">{{ $alert['value'] }}</p>
                    @endif
                </div>
            @endforeach
        </section>

        <h2 class="border-b border-(--color-border) bg-(--color-section-head) px-4 py-2 font-semibold">
            {{ __('accounts::control.scheduled') }}
        </h2>
        <x-ui.table :rows="$tasks" :columns="$taskColumns" />

        <h2 class="border-y border-(--color-border) bg-(--color-section-head) px-4 py-2 font-semibold">
            {{ __('accounts::control.failed_jobs') }}
        </h2>
        <x-ui.table :rows="$failedJobs" :columns="$failedColumns" :empty="__('accounts::control.no_failed_jobs')" />
    </div>
</x-layouts.app>
