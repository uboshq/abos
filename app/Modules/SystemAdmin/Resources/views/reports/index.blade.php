{{--
    নির্ধারিত রিপোর্ট — যা নিজে তৈরি হয়ে সঠিক মানুষের কাছে পৌঁছায়।

    প্রতিটা সারি একটা সূচি; নিচে সাম্প্রতিক তৈরি হওয়া ফাইলগুলো, download-রুট
    দিয়ে (private ডিস্ক, অনুমতি যাচাই করা)।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('system_admin::schedule.title') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('system_admin::schedule.title')"
                          :subtitle="__('system_admin::schedule.subtitle')">
            <x-slot:actions>
                <x-ui.button tone="primary" :href="route('system_admin.reports.schedule.create')">
                    {{ __('system_admin::schedule.add') }}
                </x-ui.button>
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

    {{-- সূচিগুলো --}}
    <section class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        @if ($schedules->isEmpty())
            <p class="p-8 text-center text-(--color-ink-muted)">{{ __('system_admin::schedule.none') }}</p>
        @else
            <table class="ui-list w-full text-sm">
                <thead>
                    <tr class="border-b border-(--color-border) text-start text-(--color-ink-muted)">
                        <th class="text-start">{{ __('system_admin::schedule.report') }}</th>
                        <th class="text-start">{{ __('system_admin::schedule.frequency') }}</th>
                        <th class="text-start">{{ __('system_admin::schedule.next_run') }}</th>
                        <th class="text-start">{{ __('system_admin::schedule.status_col') }}</th>
                        <th ></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($schedules as $s)
                        <tr class="border-b border-(--color-border)/60">
                            <td >
                                <span class="font-medium text-(--color-ink)">
                                    {{ $reportTitles[$s->report_key] ?? $s->report_key }}
                                </span>
                                <span class="block text-2xs uppercase text-(--color-ink-muted)">{{ $s->format }}</span>
                            </td>
                            <td class="text-(--color-ink-muted)">
                                {{ __('system_admin::schedule.freq.'.$s->frequency) }} ·
                                <span class="num">{{ $s->at_time }}</span>
                            </td>
                            <td class="num text-(--color-ink-muted)">
                                {{ $s->next_run_at ? \App\Core\Support\DateFormat::formatWithTime($s->next_run_at) : '—' }}
                            </td>
                            <td >
                                <x-ui.badge :tone="$s->is_active ? 'success' : 'draft'">
                                    {{ __($s->is_active ? 'system_admin::schedule.active' : 'system_admin::schedule.inactive') }}
                                </x-ui.badge>
                            </td>
                            <td class="text-end">
                                <a href="{{ route('system_admin.reports.schedule.edit', $s) }}"
                                   class="text-(--color-brand-600) hover:underline">{{ __('core.action.edit') }}</a>
                                <form method="POST" action="{{ route('system_admin.reports.schedule.toggle', $s) }}"
                                      class="inline">
                                    @csrf
                                    <button type="submit"
                                            class="ms-3 text-2xs text-(--color-ink-muted) underline hover:text-(--color-ink)">
                                        {{ __($s->is_active ? 'system_admin::schedule.deactivate' : 'system_admin::schedule.activate') }}
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>

    {{-- সাম্প্রতিক ফাইল --}}
    @if ($runs->isNotEmpty())
        <section class="mt-4 overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
            <h2 class="border-b border-(--color-border) bg-(--color-section-head) px-4 py-3 font-semibold">
                {{ __('system_admin::schedule.runs_heading') }}
            </h2>
            <table class="ui-list w-full text-sm">
                <tbody>
                    @foreach ($runs as $run)
                        <tr class="border-b border-(--color-border)/60">
                            <td class="text-(--color-ink-muted)">
                                {{ $reportTitles[$run->schedule?->report_key] ?? ($run->schedule?->report_key ?? '—') }}
                                <span class="block text-2xs uppercase text-(--color-ink-muted)">{{ $run->format }}</span>
                            </td>
                            <td class="num text-(--color-ink-muted)">
                                {{ \App\Core\Support\DateFormat::formatWithTime($run->ran_at) }}
                            </td>
                            <td >
                                {{ __('system_admin::schedule.run_status.'.$run->status) }}
                                @if ($run->status === 'ok')
                                    <span class="text-2xs text-(--color-ink-muted)">· {{ $run->row_count }}</span>
                                @endif
                            </td>
                            <td class="text-end">
                                @if ($run->hasFile())
                                    <a href="{{ route('system_admin.reports.download', $run) }}"
                                       class="text-(--color-brand-600) hover:underline">
                                        {{ __('system_admin::schedule.download') }}
                                    </a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @endif
</x-layouts.app>
