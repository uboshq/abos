{{--
    ⭐ নিজে থেকে পাঠানো — মালিকের "ok", ২৩ নভেম্বর ২০২৬ (নমুনা HUXqpfhxng8823eEFrvH54, ট্যাব ঙ) ([[PartyAutoController]], [[PartyAutoSend]])।
    ⓘ মাথায় এক সারি: নাম · ট্যাব (নিয়ম / আজ কারা পাবেন / বাদ রাখা পার্টি) · সংরক্ষণ — নামের নিচে ব্যাখ্যার লাইন নেই।
    ⓘ নিয়ম: দুই কার্ড পাশাপাশি (বাকির তাগাদা, টাকা পাওয়ার খবর) — সুইচ, ঘর, "যা যাবে — বাংলা" (একজন আসল পার্টির মানে; লেখা
      বদলালে সাথে সাথে বদলায়), আজকের লাইন; নিচে সবার-নিয়মের বাক্স, চার সুইচ।
    ⓘ "যা যাবে"-র প্রতিটা লেখা সার্ভারেই বানানো; পাতা কেবল বাছা লেখারটা দেখায় (CSP-Alpine-এ সরল তুলনা, কোনো JS ফাইল নয়)।
--}}
@php
    use App\Core\Services\PartyAutoSend;
    use App\Core\Services\PartyMessages;
    use App\Core\Support\DateFormat;
    use App\Core\Support\Money;

    $field = 'h-(--spacing-field) w-full rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm';
    $label = 'grid gap-1 text-2xs font-medium text-(--color-ink-muted)';
    $card = 'grid min-w-0 content-start gap-3 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-3';
    $preview = 'whitespace-pre-wrap rounded-(--radius-card) border border-(--color-border) bg-(--color-badge-success-bg) px-3 py-2 text-sm text-(--color-ink)';
    $plain = fn (string $v): string => Money::format($v, str_ends_with(Money::round($v, 2), '.00') ? 0 : 2);
@endphp
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('notification::party_auto.title') }}</x-slot:title>

    <div class="space-y-4" data-party-auto data-tab="{{ $tab }}">
        <x-ui.list-tabs :tabs="$tabs" :label="__('notification::party_auto.title')" />

        <x-ui.toolbar :title="__('notification::party_auto.title')" :search="false" :filter="false" :export="false" :share="false"
                      :print="false" :refresh="false" :quiet="['tab']">
            @if ($tab === 'rules')
                <x-slot:actions>
                    <button type="submit" form="party-auto-form" data-party-auto-save
                            class="h-(--spacing-field) rounded-(--radius-field) bg-(--color-brand-500) px-3 text-sm font-medium text-white">{{ __('notification::party_auto.save') }}</button>
                </x-slot:actions>
            @endif
        </x-ui.toolbar>

        @include('notification::partials.flash')

        @if ($tab === 'rules')
            <form id="party-auto-form" method="POST" action="{{ route('notification.party_auto.save') }}" class="space-y-4"
                  x-data="{ due: @js((string) ($dueChosen ?? '')), paid: @js((string) ($paidChosen ?? '')) }">
                @csrf
                @method('PUT')

                <div class="grid gap-3 lg:grid-cols-2">
                    {{-- ── ১. বাকির তাগাদা ── --}}
                    <section class="{{ $card }}" data-auto-rule="due" aria-labelledby="auto-due-h">
                        <div class="flex items-center gap-2">
                            <x-ui.icon name="clock" :size="18" class="text-(--color-badge-pending-ink)" />
                            <h2 id="auto-due-h" class="text-sm font-semibold">{{ __('notification::party_auto.due.title') }}</h2>
                            <span class="flex-1"></span>
                            @include('notification::party.partials.auto-switch', ['name' => 'due_enabled', 'on' => $rules[PartyAutoSend::DUE_ON], 'label' => __('notification::party_auto.due.switch')])
                        </div>

                        <div class="grid gap-3 sm:grid-cols-2">
                            <label class="{{ $label }}">{{ __('notification::party_auto.due.after') }}
                                <select name="due_after" class="{{ $field }}">
                                    @foreach (PartyAutoSend::AFTER_DAYS as $n)
                                        <option value="{{ $n }}" @selected((int) old('due_after', $rules[PartyAutoSend::DUE_AFTER]) === $n)>{{ __('notification::party_auto.days', ['n' => $n]) }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="{{ $label }}">{{ __('notification::party_auto.due.every') }}
                                <select name="due_every" class="{{ $field }}">
                                    @foreach (PartyAutoSend::EVERY_DAYS as $n)
                                        <option value="{{ $n }}" @selected((int) old('due_every', $rules[PartyAutoSend::DUE_EVERY]) === $n)>{{ __('notification::party_auto.days', ['n' => $n]) }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="{{ $label }}">{{ __('notification::party_auto.due.min') }}
                                <input type="text" name="due_min" inputmode="decimal" maxlength="15" required
                                       value="{{ old('due_min', $plain((string) $rules[PartyAutoSend::DUE_MIN])) }}" class="{{ $field }} tabular-nums">
                            </label>
                            <label class="{{ $label }}">{{ __('notification::party_auto.due.time') }}
                                <input type="text" name="due_time" inputmode="numeric" maxlength="5" required pattern="([01][0-9]|2[0-3]):[0-5][0-9]" placeholder="10:00"
                                       value="{{ old('due_time', $rules[PartyAutoSend::DUE_TIME]) }}" class="{{ $field }} tabular-nums">
                            </label>
                            <label class="{{ $label }}">{{ __('notification::party_auto.due.days') }}
                                <select name="due_days" class="{{ $field }}">
                                    @foreach (PartyAutoSend::DAYS as $d)
                                        <option value="{{ $d }}" @selected(old('due_days', $rules[PartyAutoSend::DUE_DAYS]) === $d)>{{ __('notification::party_auto.due.days_options.'.$d) }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="{{ $label }}">{{ __('notification::party_auto.due.template') }}
                                <select name="due_template" x-model="due" class="{{ $field }}">
                                    @foreach ($dueTemplates as $t)
                                        <option value="{{ $t->id }}" @selected((int) $t->id === (int) $dueChosen)>{{ $t->name }}</option>
                                    @endforeach
                                </select>
                            </label>
                        </div>

                        <div class="{{ $label }}" data-auto-preview="due">
                            <span>{{ __('notification::party_auto.preview') }}</span>
                            @forelse ($duePreview as $id => $text)
                                <div class="{{ $preview }}" data-preview-template="{{ $id }}" x-show="due === '{{ $id }}'" @if ((int) $id !== (int) $dueChosen) x-cloak @endif>{{ $text }}</div>
                            @empty
                                <div class="{{ $preview }}">{{ $dueTemplates->isEmpty() ? __('notification::party_auto.no_template') : __('notification::party_auto.no_example') }}</div>
                            @endforelse
                        </div>

                        <div class="flex flex-wrap items-center gap-2 text-2xs text-(--color-ink-muted)" data-auto-due-today>
                            <span>{!! __('notification::party_auto.due.line', [
                                'time' => '<b class="tabular-nums text-(--color-ink)">'.e($dueTime).'</b>',
                                'n' => '<b class="tabular-nums text-(--color-ink)" data-due-count>'.e((string) $dueCount).'</b>',
                            ]) !!}</span>
                            <span class="flex-1"></span>
                            <x-ui.button :href="route('notification.party_auto.index', ['tab' => 'today'])">{{ __('notification::party_auto.due.list') }}</x-ui.button>
                        </div>
                    </section>

                    {{-- ── ২. টাকা পাওয়ার খবর ── --}}
                    <section class="{{ $card }}" data-auto-rule="paid" aria-labelledby="auto-paid-h">
                        <div class="flex items-center gap-2">
                            <x-ui.icon name="chat" :size="18" class="text-(--color-badge-success-ink)" />
                            <h2 id="auto-paid-h" class="text-sm font-semibold">{{ __('notification::party_auto.paid.title') }}</h2>
                            <span class="flex-1"></span>
                            @include('notification::party.partials.auto-switch', ['name' => 'paid_enabled', 'on' => $rules[PartyAutoSend::PAID_ON], 'label' => __('notification::party_auto.paid.switch')])
                        </div>

                        <div class="grid gap-3 sm:grid-cols-2">
                            <label class="{{ $label }}">{{ __('notification::party_auto.paid.when') }}
                                <select name="paid_when" class="{{ $field }}">
                                    @foreach (PartyAutoSend::WHEN as $w)
                                        <option value="{{ $w }}" @selected(old('paid_when', $rules[PartyAutoSend::PAID_WHEN]) === $w)>{{ __('notification::party_auto.paid.when_options.'.$w) }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="{{ $label }}">{{ __('notification::party_auto.paid.min') }}
                                <input type="text" name="paid_min" inputmode="decimal" maxlength="15" required
                                       value="{{ old('paid_min', $plain((string) $rules[PartyAutoSend::PAID_MIN])) }}" class="{{ $field }} tabular-nums">
                            </label>
                            <label class="{{ $label }}">{{ __('notification::party_auto.paid.template') }}
                                <select name="paid_template" x-model="paid" class="{{ $field }}">
                                    @foreach ($paidTemplates as $t)
                                        <option value="{{ $t->id }}" @selected((int) $t->id === (int) $paidChosen)>{{ $t->name }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="{{ $label }}">{{ __('notification::party_auto.paid.link') }}
                                <select name="paid_link" class="{{ $field }}">
                                    <option value="1" @selected($rules[PartyAutoSend::PAID_LINK])>{{ __('notification::party_auto.paid.yes') }}</option>
                                    <option value="0" @selected(! $rules[PartyAutoSend::PAID_LINK])>{{ __('notification::party_auto.paid.no') }}</option>
                                </select>
                            </label>
                        </div>

                        <div class="{{ $label }}" data-auto-preview="paid">
                            <span>{{ __('notification::party_auto.preview') }}</span>
                            @forelse ($paidPreview as $id => $text)
                                <div class="{{ $preview }}" data-preview-template="{{ $id }}" x-show="paid === '{{ $id }}'" @if ((int) $id !== (int) $paidChosen) x-cloak @endif>{{ $text }}</div>
                            @empty
                                <div class="{{ $preview }}">{{ $paidTemplates->isEmpty() ? __('notification::party_auto.no_template') : __('notification::party_auto.no_example') }}</div>
                            @endforelse
                        </div>

                        <div class="flex flex-wrap items-center gap-2 text-2xs text-(--color-ink-muted)" data-auto-paid-today>
                            <span>{!! __('notification::party_auto.paid.line', [
                                'sent' => '<b class="tabular-nums text-(--color-ink)" data-paid-sent>'.e((string) $paidToday['sent']).'</b>',
                                'failed' => '<b class="tabular-nums text-(--color-badge-danger-ink)" data-paid-failed>'.e((string) $paidToday['failed']).'</b>',
                            ]) !!}</span>
                            <span class="flex-1"></span>
                            @if ($logUrl !== null)
                                <x-ui.button :href="$logUrl">{{ __('notification::party_auto.paid.log') }}</x-ui.button>
                            @endif
                        </div>
                    </section>
                </div>

                {{-- ── সবার নিয়ম ── --}}
                <section class="{{ $card }}" data-auto-shared aria-labelledby="auto-shared-h">
                    <h2 id="auto-shared-h" class="text-sm font-semibold">{{ __('notification::party_auto.shared.title') }}</h2>
                    @foreach ([
                        'quiet' => PartyAutoSend::QUIET,
                        'once_a_day' => PartyAutoSend::ONCE_A_DAY,
                        'optout' => PartyAutoSend::OPTOUT,
                        'per_branch' => PartyAutoSend::PER_BRANCH,
                    ] as $name => $key)
                        <div class="flex items-center gap-3 text-sm" data-auto-shared-rule="{{ $name }}">
                            @include('notification::party.partials.auto-switch', ['name' => $name, 'on' => $rules[$key], 'label' => __('notification::party_auto.shared.'.$name.'_label')])
                            <span>{{ __('notification::party_auto.shared.'.$name) }}</span>
                        </div>
                    @endforeach
                </section>
            </form>
        @elseif ($tab === 'today')
            <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
                <div class="overflow-x-auto">
                    <table class="ui-list w-full border-collapse text-sm" data-auto-today>
                        <thead>
                            <tr>
                                <th class="text-start">{{ __('notification::party_auto.today.party') }}</th>
                                <th class="text-start">{{ __('notification::party_auto.today.phone') }}</th>
                                <th class="text-end">{{ __('notification::party_auto.today.due') }}</th>
                                <th class="text-start">{{ __('notification::party_auto.today.oldest') }}</th>
                                <th class="text-start">{{ __('notification::party_auto.today.at') }}</th>
                                <th class="text-start">{{ __('notification::party_auto.today.road') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($rows as $row)
                                <tr data-auto-today-row>
                                    <td>{{ $row['name'] }}</td>
                                    <td class="whitespace-nowrap tabular-nums">{{ PartyMessages::shown($row['phone']) }}</td>
                                    <td class="text-end tabular-nums">{{ $row['due'] }}</td>
                                    <td class="whitespace-nowrap tabular-nums">{{ $row['oldest'] }} <span class="text-2xs text-(--color-ink-muted)">· {{ $row['oldest_date'] }}</span></td>
                                    <td class="tabular-nums">{{ $row['at'] }}</td>
                                    <td data-road="{{ $row['road'] }}">
                                        <x-ui.badge :tone="$row['road'] === 'none' ? 'danger' : 'success'">{{ __('notification::party_auto.roads.'.$row['road']) }}</x-ui.badge>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="py-6 text-center text-(--color-ink-muted)">{{ __('notification::party_auto.today.empty') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @else
            <form method="POST" action="{{ route('notification.party_auto.optout.store') }}" data-auto-optout-add
                  class="grid gap-3 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-3 md:grid-cols-[minmax(0,2fr)_minmax(0,2fr)_auto] md:items-end">
                @csrf
                <x-ui.party-search name="party" :groups="$pickerGroups" :label="__('notification::party_auto.optout.pick')" :selected="old('party')" required wide />
                <label class="{{ $label }}">{{ __('notification::party_auto.optout.reason') }}
                    <input type="text" name="reason" maxlength="191" value="{{ old('reason') }}" placeholder="{{ __('notification::party_auto.optout.reason_hint') }}" class="{{ $field }}">
                </label>
                <div>
                    <button type="submit" class="h-(--spacing-field) rounded-(--radius-field) bg-(--color-brand-500) px-3 text-sm font-medium text-white">{{ __('notification::party_auto.optout.add') }}</button>
                </div>
            </form>

            <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
                <div class="overflow-x-auto">
                    <table class="ui-list w-full border-collapse text-sm" data-auto-optouts>
                        <thead>
                            <tr>
                                <th class="text-start">{{ __('notification::party_auto.optout.party') }}</th>
                                <th class="text-start">{{ __('notification::party_auto.optout.reason') }}</th>
                                <th class="text-start">{{ __('notification::party_auto.optout.by') }}</th>
                                <th class="text-start">{{ __('notification::party_auto.optout.when') }}</th>
                                <th class="text-end">{{ __('notification::party_auto.optout.action') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($optouts as $o)
                                <tr data-optout="{{ $o->id }}">
                                    <td>{{ $optoutNames[$o->party_type.':'.$o->party_id] ?? $o->party_name ?? '—' }}</td>
                                    <td>{{ $o->reason ?? '—' }}</td>
                                    <td>{{ $optoutBy[(int) $o->created_by] ?? '—' }}</td>
                                    <td class="whitespace-nowrap tabular-nums">{{ $o->created_at === null ? '—' : DateFormat::format($o->created_at) }}</td>
                                    <td class="text-end">
                                        <x-ui.row-actions :items="[[
                                            'label' => __('notification::party_auto.optout.remove'),
                                            'url' => route('notification.party_auto.optout.destroy', $o),
                                            'method' => 'delete',
                                            'tone' => 'danger',
                                            'confirm' => __('notification::party_auto.optout.remove_confirm'),
                                        ]]" />
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="py-6 text-center text-(--color-ink-muted)">{{ __('notification::party_auto.optout.empty') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            <x-ui.pager :rows="$optouts" />
        @endif
    </div>
</x-layouts.app>
