{{--
    যাচাই — প্রতিটা রাত, আর সেটা ফিরিয়ে এনে দেখা গিয়েছিল কি না।

    ⓘ সারিগুলো রাতের (BackupRun), যাচাইয়ের নয়: যে রাতে ডাম্পই হয়নি তার
    কোনো যাচাই নেই, অথচ সেটাই সবচেয়ে জরুরি লাল সারি। ব্যর্থ রাতে কারণটা
    হুবহু দেখানো হয় — ১৯ সেপ্টেম্বর ২০২৬-এর "Access denied … verify"
    মালিক আগে কেবল সার্ভারের লগে পেতেন।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('backup::screen.verify_title') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('backup::screen.verify_title')"
                          :subtitle="__('backup::screen.verify_subtitle')" />
    </x-slot:header>

    <p class="mb-3 text-sm text-(--color-ink-muted)">
        @if ($since)
            {{ __('backup::screen.records_since', ['date' => \App\Core\Support\DateFormat::format(\Illuminate\Support\Carbon::parse($since))]) }}
        @else
            {{ __('backup::screen.no_records_yet') }}
        @endif
    </p>

    @if ($runs->isNotEmpty())
        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <div class="overflow-x-auto">
                <table class="ui-list w-full text-sm">
                    <thead>
                        <tr class="border-b border-(--color-border) text-start text-2xs uppercase tracking-wide text-(--color-ink-muted)">
                            <th class="text-start">{{ __('backup::screen.col_when') }}</th>
                            <th class="text-start">{{ __('backup::screen.col_how') }}</th>
                            <th class="text-start">{{ __('backup::screen.col_result') }}</th>
                            <th class="text-start">{{ __('backup::screen.col_check') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($runs as $run)
                            @php
                                $check = $run->verifications->sortByDesc('verified_at')->first();
                                $bad = $run->status === 'failed' || $check?->status === 'failed';
                            @endphp
                            <tr @class(['border-b border-(--color-border)/50', 'bg-(--color-badge-danger-bg)' => $bad])
                                data-run-status="{{ $run->status }}">
                                <td class="num whitespace-nowrap text-2xs">
                                    {{ \App\Core\Support\DateFormat::formatWithTime($run->started_at) }}
                                </td>
                                <td class="text-2xs">
                                    {{ $run->triggered_by === 'schedule' ? __('backup::screen.trigger_schedule') : __('backup::screen.trigger_manual') }}
                                </td>
                                <td @class(['text-2xs', 'font-semibold text-(--color-badge-danger-ink)' => $run->status === 'failed'])>
                                    {{ __('backup::screen.status_'.$run->status) }}
                                    @if ($run->error)
                                        <span class="mt-0.5 block font-mono text-2xs break-all">{{ $run->error }}</span>
                                    @endif
                                </td>
                                <td @class(['text-2xs', 'font-semibold text-(--color-badge-danger-ink)' => $check?->status === 'failed'])>
                                    @if ($check === null)
                                        <span class="text-(--color-ink-muted)">{{ __('backup::screen.check_none') }}</span>
                                    @elseif ($check->sawSomething())
                                        {{ __('backup::screen.check_passed', ['tables' => $check->detail['tables'] ?? 0]) }}
                                    @else
                                        {{ __('backup::screen.check_failed') }}
                                        @if (! empty($check->detail['error']))
                                            <span class="mt-0.5 block font-mono break-all">{{ $check->detail['error'] }}</span>
                                        @endif
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <x-ui.pager :rows="$runs" />
        </section>
    @endif
</x-layouts.app>
