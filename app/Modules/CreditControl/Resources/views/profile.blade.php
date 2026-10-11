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
        ['key' => 'limit', 'label' => __('credit_control::profile.limit'), 'value' => Money::format($position['base_limit'])],
        // ⓘ অস্থায়ী সীমা কেবল শেষ দিন পর্যন্ত — তারপর ড্যাশ, আর চলতি সীমা নিজে থেকেই সাধারণে (ধাপ ২.৩)
        ['key' => 'temporary', 'label' => __('credit_control::profile.temporary_limit'), 'value' => $position['temp_limit'] === null ? '—'
            : __('credit_control::profile.temporary_until', ['amount' => Money::format($position['temp_limit']), 'date' => DateFormat::format($position['temp_until'])])],
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
        @if (session('saved'))
            <div role="status" class="rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm text-(--color-badge-success-ink)">
                {{ session('saved') }}
            </div>
        @endif

        <x-ui.errors />

        {{-- ⭐ বাকি বন্ধ — কারণ, আর চেক ফেরতের বন্ধ হলে খোলেন কেবল মালিক (ধাপ ২.৪) --}}
        @if ($customer->isCreditBlocked())
            <section role="alert" data-credit-blocked
                     class="flex flex-wrap items-center justify-between gap-2 rounded-(--radius-card) bg-(--color-badge-danger-bg) px-4 py-2 text-sm text-(--color-badge-danger-ink)">
                <div>
                    <span class="font-semibold">{{ __('customer::credit_block.badge') }}</span> · {{ $customer->credit_block_reason }}
                    @if ($customer->credit_block_kind === \App\Modules\Customer\Models\Customer::BLOCK_BOUNCED_CHEQUE)
                        · <span data-block-kind="bounced_cheque">{{ __('customer::credit_block.kind_bounced_cheque') }}</span>
                    @endif
                </div>
                @if ($mayLift)
                    <form method="POST" action="{{ route('customer.credit_block.destroy', $customer) }}" class="flex items-center gap-2">
                        @csrf
                        @method('DELETE')
                        <input type="text" name="reason" required maxlength="500" placeholder="{{ __('customer::credit_block.clear_reason') }}"
                               class="h-(--spacing-field-dense) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm text-(--color-ink)">
                        <x-ui.button type="submit" tone="secondary">{{ __('customer::credit_block.clear') }}</x-ui.button>
                    </form>
                @endif
            </section>
        @endif
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
                <div class="max-h-80 overflow-auto">
                    <table class="ui-list w-full text-sm" data-open-bills>
                        <thead>
                            <tr class="text-left text-2xs text-(--color-ink-muted)">
                                <th class="font-semibold">{{ __('credit_control::report.bill') }}</th>
                                <th class="font-semibold">{{ __('credit_control::report.due_on') }}</th>
                                <th class="text-right font-semibold">{{ __('credit_control::report.days_overdue') }}</th>
                                <th class="text-right font-semibold">{{ __('credit_control::report.unpaid') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($bills as $bill)
                                <tr class="border-t border-(--color-border)" data-bill="{{ $bill['no'] }}">
                                    <td><x-ui.drill source="sales_invoice" :id="$bill['id']">{{ $bill['no'] }}</x-ui.drill></td>
                                    <td>{{ DateFormat::format($bill['due_on']) }}</td>
                                    <td @class(['tabular text-right', 'font-semibold text-(--color-badge-danger-ink)' => $bill['days_overdue'] > 0])>
                                        {{ $bill['days_overdue'] > 0 ? $bill['days_overdue'] : __('credit_control::report.bucket_not_due') }}
                                    </td>
                                    <td class="tabular text-right">{{ Money::format($bill['unpaid']) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="text-center text-(--color-ink-muted)">{{ __('credit_control::profile.no_open_bills') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="min-w-0 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
                <h2 class="border-b border-(--color-border) px-4 py-2 text-sm font-semibold text-(--color-ink)">{{ __('credit_control::profile.history') }}</h2>
                <div class="max-h-80 overflow-auto">
                    <table class="ui-list w-full text-sm" data-credit-history>
                        <thead>
                            <tr class="text-left text-2xs text-(--color-ink-muted)">
                                <th class="font-semibold">{{ __('credit_control::profile.when') }}</th>
                                <th class="font-semibold">{{ __('credit_control::profile.what') }}</th>
                                <th class="text-right font-semibold">{{ __('credit_control::profile.old') }}</th>
                                <th class="text-right font-semibold">{{ __('credit_control::profile.new') }}</th>
                                <th class="font-semibold">{{ __('credit_control::profile.who') }}</th>
                                <th class="font-semibold">{{ __('credit_control::profile.reason') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($history as $row)
                                <tr class="border-t border-(--color-border)">
                                    <td class="whitespace-nowrap">{{ DateFormat::format($row['at']) }}</td>
                                    <td>{{ $row['what'] }}</td>
                                    <td class="tabular text-right">{{ $row['old'] === null ? '—' : ($row['money'] ? Money::format($row['old']) : $row['old']) }}</td>
                                    <td class="tabular text-right">{{ $row['new'] === null ? '—' : ($row['money'] ? Money::format($row['new']) : $row['new']) }}</td>
                                    <td>{{ $row['who'] ?? '—' }}</td>
                                    <td>
                                        {{ $row['reason'] ?? '—' }}
                                        @if ($row['approval'] !== null)
                                            · <x-ui.drill source="approval" :id="$row['approval']">{{ __('credit_control::profile.signature') }}</x-ui.drill>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="text-center text-(--color-ink-muted)">{{ __('credit_control::profile.no_history') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        {{-- ⭐ সীমা বাড়ানোর অনুরোধ — লেখা আর তালিকা; সই পড়ার আগে সীমা বদলায় না (ধাপ ২.৫) --}}
        <div class="grid gap-3 xl:grid-cols-2">
            @if ($canRequest)
                <section class="min-w-0 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)" data-limit-request-form>
                    <h2 class="border-b border-(--color-border) px-4 py-2 text-sm font-semibold text-(--color-ink)">{{ __('customer::credit_request.title') }}</h2>
                    <form method="POST" action="{{ route('credit_control.limit_request.store', $customer->id) }}" enctype="multipart/form-data"
                          class="grid gap-2 px-4 py-3 sm:grid-cols-2">
                        @csrf
                        <x-ui.select name="kind" :label="__('customer::credit_request.kind')" required
                                     :options="['limit' => __('customer::credit_request.kind_limit'), 'temporary' => __('customer::credit_request.kind_temporary')]"
                                     :selected="old('kind', 'limit')" />
                        <x-ui.field name="amount" type="number" inputmode="decimal" numeric required
                                    :label="__('customer::credit_request.amount')" :value="old('amount')" />
                        <label class="flex flex-col gap-1">
                            <span class="text-sm font-medium">{{ __('customer::credit_request.temp_until') }}</span>
                            <x-ui.date name="temp_until" :value="old('temp_until')" />
                        </label>
                        <x-ui.field name="reason" required :label="__('customer::credit_request.reason')" :value="old('reason')" />
                        <div class="flex flex-col gap-1">
                            <span class="text-sm font-medium">{{ __('customer::credit_request.attachment') }}</span>
                            <x-ui.file-input id="limit-request-file" name="attachment" accept="image/png,image/jpeg,image/webp,application/pdf" />
                        </div>
                        <div class="flex items-end justify-end">
                            <x-ui.button type="submit">{{ __('customer::credit_request.send') }}</x-ui.button>
                        </div>
                        <p class="text-xs text-(--color-ink-muted) sm:col-span-2">{{ __('customer::credit_request.help') }}</p>
                    </form>
                </section>
            @endif

            <section class="min-w-0 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
                <h2 class="border-b border-(--color-border) px-4 py-2 text-sm font-semibold text-(--color-ink)">{{ __('customer::credit_request.list') }}</h2>
                <div class="max-h-80 overflow-auto">
                    <table class="ui-list w-full text-sm" data-limit-requests>
                        <thead>
                            <tr class="text-left text-2xs text-(--color-ink-muted)">
                                <th class="font-semibold">{{ __('customer::credit_request.asked_on') }}</th>
                                <th class="font-semibold">{{ __('customer::credit_request.kind') }}</th>
                                <th class="text-right font-semibold">{{ __('customer::credit_request.from_to') }}</th>
                                <th class="font-semibold">{{ __('customer::credit_request.asked_by') }}</th>
                                <th class="font-semibold">{{ __('customer::credit_request.status') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($requests as $r)
                                @php
                                    $shown = $r->shownStatus();
                                @endphp
                                <tr class="border-t border-(--color-border)" data-request-status="{{ $shown }}">
                                    <td class="whitespace-nowrap">{{ DateFormat::format($r->created_at) }}</td>
                                    <td>
                                        {{ __('customer::credit_request.kind_'.$r->kind) }}
                                        @if ($r->temp_until !== null)
                                            · {{ DateFormat::format($r->temp_until) }}
                                        @endif
                                    </td>
                                    <td class="tabular text-right">{{ Money::format($r->current_limit) }} → {{ Money::format($r->requested_limit) }}</td>
                                    <td>{{ $r->requester?->name ?? '—' }}</td>
                                    <td>
                                        <x-ui.badge :tone="['approved' => 'success', 'pending' => 'pending'][$shown] ?? 'danger'">{{ __('customer::credit_request.status_'.$shown) }}</x-ui.badge>
                                        @if ($r->approval_id !== null)
                                            · <x-ui.drill source="approval" :id="$r->approval_id">{{ __('credit_control::profile.signature') }}</x-ui.drill>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="text-center text-(--color-ink-muted)">{{ __('customer::credit_request.none') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <section class="min-w-0 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
            <h2 class="border-b border-(--color-border) px-4 py-2 text-sm font-semibold text-(--color-ink)">{{ __('credit_control::profile.decisions') }}</h2>
            <div class="overflow-x-auto">
            <table class="ui-list w-full text-sm" data-wall-decisions>
                <thead>
                    <tr class="text-left text-2xs text-(--color-ink-muted)">
                        <th class="font-semibold">{{ __('credit_control::profile.when') }}</th>
                        <th class="font-semibold">{{ __('credit_control::profile.where') }}</th>
                        <th class="font-semibold">{{ __('credit_control::profile.paper') }}</th>
                        <th class="text-right font-semibold">{{ __('credit_control::profile.this_paper') }}</th>
                        <th class="text-right font-semibold">{{ __('credit_control::profile.after') }}</th>
                        <th class="text-right font-semibold">{{ __('credit_control::profile.limit') }}</th>
                        <th class="font-semibold">{{ __('credit_control::profile.result') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($decisions as $d)
                        <tr class="border-t border-(--color-border)">
                            <td class="whitespace-nowrap">{{ DateFormat::formatWithTime($d->created_at) }}</td>
                            <td>{{ __('credit_control::profile.stage_'.$d->stage) }}</td>
                            <td>{{ $d->paper_no ?? '—' }}</td>
                            <td class="tabular text-right">{{ Money::format($d->adding) }}</td>
                            <td class="tabular text-right">{{ $d->exposure_after === null ? '—' : Money::format($d->exposure_after) }}</td>
                            <td class="tabular text-right">{{ $d->credit_limit === null ? '—' : Money::format($d->credit_limit) }}</td>
                            <td>
                                <x-ui.badge :tone="$d->result === 'pass' ? 'success' : 'danger'">{{ __('credit_control::profile.reason_'.$d->reason) }}</x-ui.badge>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-(--color-ink-muted)">{{ __('credit_control::profile.no_decisions') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
            </div>
        </section>
    </div>
</x-layouts.app>
