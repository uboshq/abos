{{--
    মালিকের কেন্দ্র — সতর্কতা, সব কোম্পানির।

    ⓘ সারিতে সতর্কতা, কলামে কোম্পানি। প্রতিটা সংখ্যা একটা রিপোর্টের সারি গোনা ([[Alerts]]) —
    চাপলে ঐ কোম্পানিতে বসে ঠিক সেই তালিকা খোলে। "অনুমতি নেই" মানে শূন্য নয়।
--}}
@php
    use App\Core\Support\Money;
    use App\Modules\Executive\Support\Go;
@endphp
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('executive::alerts_page.title') }}</x-slot:title>

    @include('executive::partials.open-form')
    @include('executive::partials.fit')

    <div data-executive-alerts class="flex flex-col gap-3">
        <div data-fit class="flex flex-wrap items-center justify-between gap-3 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) px-4"
             style="height: var(--exec-bar)">
            <h1 class="truncate text-lg font-bold text-(--color-ink)">{{ __('executive::alerts_page.title') }}</h1>
            <form method="POST" action="{{ route('executive.refresh') }}" class="flex flex-wrap items-center gap-2">
                @csrf
                <span class="text-2xs text-(--color-ink-muted)">{{ __('executive::today.cached_note') }}</span>
                <x-ui.button type="submit"><x-ui.icon name="refresh" :size="14" /> {{ __('executive::today.refresh') }}</x-ui.button>
            </form>
        </div>

        <div class="grid gap-3 xl:grid-cols-3">
            <section data-alert-grid class="min-w-0 overflow-x-auto rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) xl:col-span-2">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-2xs text-(--color-ink-muted)">
                            <th class="px-3 py-2 font-semibold">{{ __('executive::alerts_page.what') }}</th>
                            @foreach ($companies as $company)
                                <th class="px-3 py-2 text-right font-semibold">{{ $company['name'] }}</th>
                            @endforeach
                            <th class="px-3 py-2 text-right font-semibold">{{ __('executive::today.group_total') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($alerts as $alert)
                            <tr data-alert="{{ $alert['kind'] }}" class="border-t border-(--color-border)">
                                <td class="px-3 py-2 text-(--color-ink)">{{ __('executive::alert.'.$alert['kind']) }}</td>
                                @foreach ($alert['companies'] as $part)
                                    <td class="tabular px-3 py-2 text-right" data-company="{{ $part['id'] }}">
                                        @if ($part['count'] === null)
                                            <span class="text-2xs text-(--color-ink-muted)">{{ __('executive::alert.no_key') }}</span>
                                        @else
                                            <button type="submit" form="executive-open" name="go"
                                                    value="{{ Go::to($part['id'], null, $part['route'], $part['params']) }}"
                                                    @class(['tabular hover:underline', 'font-semibold text-(--color-badge-danger-ink)' => $part['count'] > 0, 'text-(--color-ink-muted)' => $part['count'] === 0])>{{ $part['count'] }}</button>
                                        @endif
                                    </td>
                                @endforeach
                                <td class="tabular px-3 py-2 text-right font-bold">{{ $alert['total'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </section>

            <section data-waiting class="flex min-h-0 min-w-0 flex-col overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)"
                     style="max-height: 640px">
                <h2 class="border-b border-(--color-border) px-4 py-2 text-sm font-semibold text-(--color-ink)">{{ __('executive::today.waiting_title') }}</h2>
                <ul class="min-h-0 flex-1 overflow-auto text-sm">
                    @forelse ($waiting as $paper)
                        <li class="border-t border-(--color-border) px-4 py-1.5">
                            <button type="submit" form="executive-open" name="go"
                                    value="{{ Go::to($paper['company_id'], null, $paper['route'], $paper['params']) }}"
                                    class="flex w-full items-center justify-between gap-2 text-left hover:underline">
                                <span class="min-w-0 truncate">{{ $paper['label'] }}
                                    <span class="text-2xs text-(--color-ink-muted)">· {{ $paper['company_name'] }}</span></span>
                                @if ($paper['amount'] !== null)
                                    <span class="tabular shrink-0">{{ Money::format($paper['amount'], 0) }}</span>
                                @endif
                            </button>
                        </li>
                    @empty
                        <li class="px-4 py-2 text-2xs text-(--color-ink-muted)">{{ __('executive::today.nothing_waiting') }}</li>
                    @endforelse
                </ul>
            </section>
        </div>
    </div>
</x-layouts.app>
