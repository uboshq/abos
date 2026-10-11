{{--
    গ্রাহকের বাকির পাতা — বাকি নিয়ন্ত্রণ ধাপ ১.৩ (১১ অক্টোবর ২০২৬)।

    ⛔ এখানে কোনো হিসাব নেই: প্রতিটা অঙ্ক দেয়ালের নিজের হিসাব থেকে ([[CreditExposure::positionOf()]], [[openBills()]])।
    ⓘ এক পর্দায় — উপরে অঙ্ক, মাঝে খোলা বিল আর বদলের ইতিহাস পাশাপাশি, নিচে দেয়ালের শেষ সিদ্ধান্তগুলো।
--}}
@php
    use App\Core\Support\DateFormat;
    use App\Core\Support\Money;

    $tone = ['active' => 'success', 'over_limit' => 'danger', 'overdue' => 'danger', 'blocked' => 'danger', 'unchecked' => 'draft'][$position['state']] ?? 'draft';
    $figures = [
        ['key' => 'limit', 'label' => __('credit_control::profile.limit'), 'value' => Money::format($position['limit'])],
        ['key' => 'temporary', 'label' => __('credit_control::profile.temporary_limit'), 'value' => '—'],
        ['key' => 'effective', 'label' => __('credit_control::profile.effective_limit'), 'value' => Money::format($position['limit'])],
        ['key' => 'days', 'label' => __('credit_control::profile.credit_days'), 'value' => $customer->credit_days === null ? '—' : __('credit_control::profile.days', ['n' => $customer->credit_days])],
        ['key' => 'due', 'label' => __('credit_control::profile.due'), 'value' => Money::format($position['due'])],
        ['key' => 'held', 'label' => __('credit_control::profile.held'), 'value' => Money::format($position['held'])],
        ['key' => 'uncleared', 'label' => __('credit_control::profile.uncleared'), 'value' => Money::format($position['uncleared'])],
        ['key' => 'exposure', 'label' => __('credit_control::profile.exposure'), 'value' => Money::format($position['exposure'])],
        ['key' => 'available', 'label' => __('credit_control::profile.available'), 'value' => Money::format($position['available'])],
    ];
@endphp
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('credit_control::profile.title', ['name' => $customer->name()]) }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$customer->name()" :subtitle="$customer->code.' · '.__('credit_control::profile.subtitle')">
            <x-slot:actions>
                <x-ui.button tone="secondary" :href="route('customer.show', $customer)">
                    <x-ui.icon name="customer" :size="14" /> {{ __('credit_control::profile.open_customer') }}
                </x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>

    <div class="flex flex-col gap-3" data-credit-profile>
        <section class="flex flex-wrap items-center gap-2 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) px-4 py-2">
            <span class="text-sm text-(--color-ink-muted)">{{ __('credit_control::profile.status') }}</span>
            <x-ui.badge :tone="$tone" data-credit-state="{{ $position['state'] }}">{{ __('credit_control::state.'.$position['state']) }}</x-ui.badge>
            @if ($position['used_percent'] !== null)
                <span class="text-sm text-(--color-ink-muted)">· {{ __('credit_control::profile.used', ['p' => $position['used_percent']]) }}</span>
            @endif
            @if ($position['stop'] !== null)
                <span class="text-sm text-(--color-badge-danger-ink)">· {{ $position['stop'] }}</span>
            @endif
        </section>

        <section class="grid grid-cols-3 gap-2 lg:grid-cols-9">
            @foreach ($figures as $figure)
                <div data-figure="{{ $figure['key'] }}" class="min-w-0 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) px-3 py-2">
                    <div class="truncate text-2xs text-(--color-ink-muted)">{{ $figure['label'] }}</div>
                    <div class="tabular truncate text-base font-semibold text-(--color-ink)">{{ $figure['value'] }}</div>
                </div>
            @endforeach
        </section>

        <div class="grid gap-3 xl:grid-cols-2">
            <section class="min-w-0 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
                <h2 class="border-b border-(--color-border) px-4 py-2 text-sm font-semibold text-(--color-ink)">{{ __('credit_control::profile.open_bills') }}</h2>
                <div class="max-h-80 overflow-y-auto">
                    <table class="w-full text-sm" data-open-bills>
                        <thead>
                            <tr class="text-left text-2xs text-(--color-ink-muted)">
                                <th class="px-3 py-1.5 font-semibold">{{ __('credit_control::report.bill') }}</th>
                                <th class="px-3 py-1.5 font-semibold">{{ __('credit_control::report.due_on') }}</th>
                                <th class="px-3 py-1.5 text-right font-semibold">{{ __('credit_control::report.days_overdue') }}</th>
                                <th class="px-3 py-1.5 text-right font-semibold">{{ __('credit_control::report.unpaid') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($bills as $bill)
                                <tr class="border-t border-(--color-border)" data-bill="{{ $bill['no'] }}">
                                    <td class="px-3 py-1.5"><x-ui.drill source="sales_invoice" :id="$bill['id']">{{ $bill['no'] }}</x-ui.drill></td>
                                    <td class="px-3 py-1.5">{{ DateFormat::format($bill['due_on']) }}</td>
                                    <td @class(['tabular px-3 py-1.5 text-right', 'font-semibold text-(--color-badge-danger-ink)' => $bill['days_overdue'] > 0])>
                                        {{ $bill['days_overdue'] > 0 ? $bill['days_overdue'] : __('credit_control::report.bucket_not_due') }}
                                    </td>
                                    <td class="tabular px-3 py-1.5 text-right">{{ Money::format($bill['unpaid']) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="px-3 py-3 text-center text-(--color-ink-muted)">{{ __('credit_control::profile.no_open_bills') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="min-w-0 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
                <h2 class="border-b border-(--color-border) px-4 py-2 text-sm font-semibold text-(--color-ink)">{{ __('credit_control::profile.history') }}</h2>
                <div class="max-h-80 overflow-y-auto">
                    <table class="w-full text-sm" data-credit-history>
                        <thead>
                            <tr class="text-left text-2xs text-(--color-ink-muted)">
                                <th class="px-3 py-1.5 font-semibold">{{ __('credit_control::profile.when') }}</th>
                                <th class="px-3 py-1.5 font-semibold">{{ __('credit_control::profile.what') }}</th>
                                <th class="px-3 py-1.5 text-right font-semibold">{{ __('credit_control::profile.old') }}</th>
                                <th class="px-3 py-1.5 text-right font-semibold">{{ __('credit_control::profile.new') }}</th>
                                <th class="px-3 py-1.5 font-semibold">{{ __('credit_control::profile.who') }}</th>
                                <th class="px-3 py-1.5 font-semibold">{{ __('credit_control::profile.reason') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($history as $row)
                                <tr class="border-t border-(--color-border)">
                                    <td class="px-3 py-1.5 whitespace-nowrap">{{ DateFormat::format($row['at']) }}</td>
                                    <td class="px-3 py-1.5">{{ $row['what'] }}</td>
                                    <td class="tabular px-3 py-1.5 text-right">{{ $row['old'] === null ? '—' : ($row['money'] ? Money::format($row['old']) : $row['old']) }}</td>
                                    <td class="tabular px-3 py-1.5 text-right">{{ $row['new'] === null ? '—' : ($row['money'] ? Money::format($row['new']) : $row['new']) }}</td>
                                    <td class="px-3 py-1.5">{{ $row['who'] ?? '—' }}</td>
                                    <td class="px-3 py-1.5">
                                        {{ $row['reason'] ?? '—' }}
                                        @if ($row['approval'] !== null)
                                            · <x-ui.drill source="approval" :id="$row['approval']">{{ __('credit_control::profile.signature') }}</x-ui.drill>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="px-3 py-3 text-center text-(--color-ink-muted)">{{ __('credit_control::profile.no_history') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <section class="min-w-0 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
            <h2 class="border-b border-(--color-border) px-4 py-2 text-sm font-semibold text-(--color-ink)">{{ __('credit_control::profile.decisions') }}</h2>
            <table class="w-full text-sm" data-wall-decisions>
                <thead>
                    <tr class="text-left text-2xs text-(--color-ink-muted)">
                        <th class="px-3 py-1.5 font-semibold">{{ __('credit_control::profile.when') }}</th>
                        <th class="px-3 py-1.5 font-semibold">{{ __('credit_control::profile.where') }}</th>
                        <th class="px-3 py-1.5 font-semibold">{{ __('credit_control::profile.paper') }}</th>
                        <th class="px-3 py-1.5 text-right font-semibold">{{ __('credit_control::profile.this_paper') }}</th>
                        <th class="px-3 py-1.5 text-right font-semibold">{{ __('credit_control::profile.after') }}</th>
                        <th class="px-3 py-1.5 text-right font-semibold">{{ __('credit_control::profile.limit') }}</th>
                        <th class="px-3 py-1.5 font-semibold">{{ __('credit_control::profile.result') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($decisions as $d)
                        <tr class="border-t border-(--color-border)">
                            <td class="px-3 py-1.5 whitespace-nowrap">{{ DateFormat::formatWithTime($d->created_at) }}</td>
                            <td class="px-3 py-1.5">{{ __('credit_control::profile.stage_'.$d->stage) }}</td>
                            <td class="px-3 py-1.5">{{ $d->paper_no ?? '—' }}</td>
                            <td class="tabular px-3 py-1.5 text-right">{{ Money::format($d->adding) }}</td>
                            <td class="tabular px-3 py-1.5 text-right">{{ $d->exposure_after === null ? '—' : Money::format($d->exposure_after) }}</td>
                            <td class="tabular px-3 py-1.5 text-right">{{ $d->credit_limit === null ? '—' : Money::format($d->credit_limit) }}</td>
                            <td class="px-3 py-1.5">
                                <x-ui.badge :tone="$d->result === 'pass' ? 'success' : 'danger'">{{ __('credit_control::profile.reason_'.$d->reason) }}</x-ui.badge>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-3 py-3 text-center text-(--color-ink-muted)">{{ __('credit_control::profile.no_decisions') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>
    </div>
</x-layouts.app>
