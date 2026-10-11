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
        ['key' => 'limit', 'label' => __('credit_control::profile.limit'), 'value' => Money::format($position['normal_limit'])],
        // ⭐ অস্থায়ী সীমা আর তার শেষ দিন — খাটলে তবেই (ধাপ ২.৩); শেষ দিনের পরে চলতি সীমা নিজেই সাধারণে ফেরে
        ['key' => 'temporary', 'label' => __('credit_control::profile.temporary_limit'), 'value' => $position['temporary_limit'] === null ? '—'
            : Money::format($position['temporary_limit']).' · '.__('credit_control::profile.until', ['date' => DateFormat::format($position['temporary_until'])])],
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

        <x-ui.errors />

        {{-- ⭐ বাকি বন্ধ — কারণ, আর খোলার ঘর; চেক ফেরতের বন্ধ খোলেন কেবল মালিক (ধাপ ২.৪) --}}
        @if ($customer->isCreditBlocked())
            <section data-credit-block class="flex flex-wrap items-center justify-between gap-2 rounded-(--radius-card) bg-(--color-badge-danger-bg) px-4 py-2 text-sm text-(--color-badge-danger-ink)">
                <div>
                    <span class="font-semibold">{{ $customer->isBlockedByBouncedCheque() ? __('customer::credit_block.bounced_badge') : __('customer::credit_block.badge') }}</span>
                    · {{ $customer->credit_block_reason }} · {{ DateFormat::format($customer->credit_blocked_at) }}
                </div>
                @if ($customer->isBlockedByBouncedCheque() ? $isOwner : $canEdit)
                    <form method="POST" action="{{ route('customer.credit_block.destroy', $customer) }}" class="flex items-center gap-2" data-credit-release-form>
                        @csrf
                        @method('DELETE')
                        <input type="text" name="reason" required maxlength="500" placeholder="{{ __('customer::credit_block.clear_reason') }}"
                               class="h-(--spacing-field-dense) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm text-(--color-ink)">
                        <x-ui.button type="submit" tone="secondary">{{ __('customer::credit_block.clear') }}</x-ui.button>
                    </form>
                @endif
            </section>
        @endif

        {{-- ⭐ কাজ — এক সারিতে, কারণ বাধ্যতামূলক (বাকি নিয়ন্ত্রণ ধাপ ২); দরজা নিজে চাবি দেখে, এখানে কেবল আঁকা না-আঁকা --}}
        @if ($canEdit || $canRequest || $isOwner)
            <section class="grid gap-2 lg:grid-cols-3" data-credit-actions>
                @if ($canEdit)
                <form method="POST" action="{{ route('credit_control.customer.credit_days', $customer->id) }}" data-credit-days-form
                      class="flex flex-wrap items-center gap-2 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) px-3 py-2">
                    @csrf
                    <label class="text-sm text-(--color-ink-muted)" for="cc-credit-days">{{ __('credit_control::profile.credit_days') }}</label>
                    <input id="cc-credit-days" type="number" name="credit_days" min="0" max="3650" required
                           value="{{ old('credit_days', (int) $customer->credit_days) }}"
                           class="h-(--spacing-field-dense) w-20 rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm text-(--color-ink)">
                    <input type="text" name="reason" required maxlength="500" placeholder="{{ __('credit_control::profile.reason_required') }}"
                           class="h-(--spacing-field-dense) min-w-0 flex-1 rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm text-(--color-ink)">
                    <x-ui.button type="submit" tone="secondary">{{ __('credit_control::profile.save') }}</x-ui.button>
                </form>
                @endif

                {{-- ⭐ সীমা — হিসাবরক্ষক/ম্যানেজার অনুরোধ লেখেন (বসে কেবল মালিকের সইয়ে), মালিক নিজে বসান (ধাপ ২.৫) --}}
                @if ($isOwner || $canRequest)
                    <form method="POST" enctype="multipart/form-data" x-data="{ kind: '{{ old('kind', 'limit') }}' }"
                          action="{{ $isOwner ? route('credit_control.customer.limit_direct', $customer->id) : route('credit_control.customer.limit_request', $customer->id) }}"
                          data-limit-form="{{ $isOwner ? 'direct' : 'request' }}"
                          class="flex flex-wrap items-center gap-2 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) px-3 py-2 lg:col-span-2">
                        @csrf
                        <span class="text-sm font-semibold text-(--color-ink)">{{ $isOwner ? __('credit_control::request.direct_title') : __('credit_control::request.title') }}</span>
                        @foreach (['limit', 'temporary'] as $k)
                            <label class="flex items-center gap-1 text-sm text-(--color-ink)">
                                <input type="radio" name="kind" value="{{ $k }}" x-model="kind"> {{ __('credit_control::request.kind_'.$k) }}
                            </label>
                        @endforeach
                        <input type="number" name="requested_limit" min="0" step="0.01" required value="{{ old('requested_limit') }}"
                               placeholder="{{ __('credit_control::request.amount') }}" aria-label="{{ __('credit_control::request.amount') }}"
                               class="h-(--spacing-field-dense) w-32 rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm text-(--color-ink)">
                        <div x-show="kind === 'temporary'" x-cloak class="w-40" aria-label="{{ __('credit_control::request.until') }}">
                            <x-ui.date name="temporary_until" dense :value="old('temporary_until')" />
                        </div>
                        <input type="text" name="reason" required maxlength="500" value="{{ old('reason') }}" placeholder="{{ __('credit_control::profile.reason_required') }}"
                               class="h-(--spacing-field-dense) min-w-0 flex-1 rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm text-(--color-ink)">
                        @unless ($isOwner)
                            <input type="file" name="file" aria-label="{{ __('credit_control::request.file') }}" class="max-w-48 text-2xs text-(--color-ink-muted)">
                        @endunless
                        <x-ui.button type="submit" tone="secondary">{{ $isOwner ? __('credit_control::request.direct_submit') : __('credit_control::request.submit') }}</x-ui.button>
                    </form>
                @endif
            </section>
        @endif

        <div class="grid gap-3 xl:grid-cols-3">
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

            {{-- ⭐ সীমা বাড়ানোর অনুরোধ — "না" আর মেয়াদ ফুরানোও থাকে (ধাপ ২.৫) --}}
            <section class="min-w-0 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
                <h2 class="border-b border-(--color-border) px-4 py-2 text-sm font-semibold text-(--color-ink)">{{ __('credit_control::request.list') }}</h2>
                <div class="max-h-80 overflow-auto">
                    <table class="ui-list w-full text-sm" data-limit-requests>
                        <thead>
                            <tr class="text-left text-2xs text-(--color-ink-muted)">
                                <th class="font-semibold">{{ __('credit_control::profile.when') }}</th>
                                <th class="font-semibold">{{ __('credit_control::request.kind') }}</th>
                                <th class="text-right font-semibold">{{ __('credit_control::request.amount') }}</th>
                                <th class="font-semibold">{{ __('credit_control::profile.who') }}</th>
                                <th class="font-semibold">{{ __('credit_control::profile.result') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($requests as $r)
                                @php $shown = $r->shownStatus(); @endphp
                                <tr class="border-t border-(--color-border)" data-request="{{ $r->id }}" title="{{ $r->reason }}">
                                    <td class="whitespace-nowrap">{{ DateFormat::format($r->created_at) }}</td>
                                    <td>{{ __('credit_control::request.kind_'.$r->kind) }}@if ($r->temporary_until) · {{ DateFormat::format($r->temporary_until) }}@endif</td>
                                    <td class="tabular text-right">{{ Money::format($r->requested_limit) }}</td>
                                    <td>{{ $r->requester?->name ?? '—' }}</td>
                                    <td>
                                        <x-ui.badge :tone="['pending' => 'pending', 'approved' => 'success', 'rejected' => 'danger'][$shown] ?? 'draft'" data-request-status="{{ $shown }}">{{ __('credit_control::request.status_'.$shown) }}</x-ui.badge>
                                        @if ($r->approval_id !== null)
                                            · <x-ui.drill source="approval" :id="$r->approval_id">{{ __('credit_control::profile.signature') }}</x-ui.drill>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="text-center text-(--color-ink-muted)">{{ __('credit_control::request.none') }}</td></tr>
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
