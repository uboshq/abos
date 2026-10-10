{{--
    ⭐ নিয়ম লেখা — কোড ছাড়া: ঘটনা, শর্ত, প্রাপক, মাধ্যম, গুরুত্ব, টেমপ্লেট, সময়, মেয়াদ, চালু (মালিকের স্পেক §৮; ধাপ ৩)।

    ⓘ নিয়ম মডিউলের নিজের প্রাপকদের সরায় না — কেবল যোগ করে। প্রতিটা সংরক্ষণে সংস্করণ বাড়ে; নিচে সংস্করণের তালিকা।
    ⓘ "পরীক্ষা" নমুনা মান দিয়ে বলে নিয়মটা খাটত কি না আর কারা পেতেন — কিছু পাঠায় না।
    ⚠️ শর্তের সারি স্থির চারটা — জাভাস্ক্রিপ্ট ছাড়াই চলে; ফাঁকা সারি বাদ পড়ে।
--}}
@php
    $field = 'h-(--spacing-field) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm';
    $conditions = array_values((array) old('conditions', $rule->conditions ?? []));
    $recipients = (array) old('recipients', $rule->recipients ?? []);
    $channels = (array) old('channels', $rule->channels ?? []);
@endphp
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $rule->exists ? $rule->name : __('notification::rule.new') }}</x-slot:title>

    <div class="mx-auto max-w-5xl space-y-4">
        <div class="flex items-center justify-between gap-2">
            <h1 class="text-lg font-semibold">{{ $rule->exists ? $rule->name.' · v'.$rule->version : __('notification::rule.new') }}</h1>
            <a href="{{ route('notification.rules.index') }}" class="text-sm text-(--color-brand-500) hover:underline">{{ __('notification::rule.back') }}</a>
        </div>

        @include('notification::partials.flash')

        <form method="POST" action="{{ $rule->exists ? route('notification.rules.update', $rule) : route('notification.rules.store') }}"
              class="space-y-4 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            @csrf
            @if ($rule->exists) @method('PUT') @endif

            <div class="grid gap-3 md:grid-cols-3">
                <label class="grid gap-1 text-2xs text-(--color-ink-muted) md:col-span-2">
                    {{ __('notification::rule.name') }}
                    <input type="text" name="name" maxlength="120" required value="{{ old('name', $rule->name) }}" class="{{ $field }}">
                </label>
                <label class="flex items-center gap-2 self-end text-sm">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $rule->is_active))>
                    {{ __('notification::rule.active') }}
                </label>

                <label class="grid gap-1 text-2xs text-(--color-ink-muted) md:col-span-2">
                    {{ __('notification::rule.event') }}
                    <select name="event" required class="{{ $field }}">
                        @foreach ($events as $type => $label)
                            <option value="{{ $type }}" @selected(old('event', $rule->event) === $type)>
                                {{ \App\Core\Support\NotificationKinds::sourceLabel(\App\Core\Support\NotificationKinds::classify($type)['module']) }} · {{ __($label) }}
                            </option>
                        @endforeach
                    </select>
                </label>
                <label class="grid gap-1 text-2xs text-(--color-ink-muted)">
                    {{ __('notification::rule.scope_branch') }}
                    <select name="branch_id" class="{{ $field }}">
                        <option value="">{{ __('notification::rule.all_branches') }}</option>
                        @foreach ($choices['branches'] as $id => $name)
                            <option value="{{ $id }}" @selected((int) old('branch_id', $rule->branch_id) === (int) $id)>{{ $name }}</option>
                        @endforeach
                    </select>
                </label>
            </div>

            <fieldset class="space-y-2">
                <legend class="text-sm font-medium">{{ __('notification::rule.conditions') }}</legend>
                <p class="text-2xs text-(--color-ink-muted)">{{ __('notification::rule.conditions_note') }}</p>
                @for ($i = 0; $i < \App\Modules\Notification\Http\Controllers\NotificationRuleController::CONDITION_ROWS; $i++)
                    @php $c = (array) ($conditions[$i] ?? []); @endphp
                    <div class="grid gap-2 md:grid-cols-3" data-condition-row="{{ $i }}">
                        <select name="conditions[{{ $i }}][field]" aria-label="{{ __('notification::rule.field') }}" class="{{ $field }}">
                            <option value="">—</option>
                            @foreach (\App\Core\Notifications\NotificationVariables::CONDITION_FIELDS as $name)
                                <option value="{{ $name }}" @selected(($c['field'] ?? '') === $name)>{{ __('core.notify.var.'.$name) }}</option>
                            @endforeach
                        </select>
                        <select name="conditions[{{ $i }}][op]" aria-label="{{ __('notification::rule.op') }}" class="{{ $field }}">
                            <option value="">—</option>
                            @foreach (\App\Models\NotificationRule::OPERATORS as $op)
                                <option value="{{ $op }}" @selected(($c['op'] ?? '') === $op)>{{ __('notification::rule.ops.'.$op) }}</option>
                            @endforeach
                        </select>
                        <input type="text" name="conditions[{{ $i }}][value]" maxlength="191" value="{{ $c['value'] ?? '' }}"
                               aria-label="{{ __('notification::rule.value') }}" class="{{ $field }}">
                    </div>
                @endfor
            </fieldset>

            <fieldset class="space-y-2">
                <legend class="text-sm font-medium">{{ __('notification::rule.recipients') }}</legend>
                <p class="text-2xs text-(--color-ink-muted)">{{ __('notification::rule.recipients_note') }}</p>
                <div class="grid gap-3 md:grid-cols-3">
                    @foreach (['users', 'roles', 'branches', 'departments', 'groups'] as $kind)
                        @include('notification::partials.pick', ['label' => __('notification::rule.kinds.'.$kind), 'name' => 'recipients['.$kind.']', 'options' => $choices[$kind], 'chosen' => $recipients[$kind] ?? []])
                    @endforeach
                    <label class="flex items-center gap-2 self-start text-sm">
                        <input type="hidden" name="recipients[responsible]" value="0">
                        <input type="checkbox" name="recipients[responsible]" value="1" @checked(! empty($recipients['responsible']))>
                        {{ __('notification::rule.kinds.responsible') }}
                    </label>
                </div>
            </fieldset>

            <div class="grid gap-3 md:grid-cols-3">
                <fieldset class="space-y-1">
                    <legend class="text-2xs text-(--color-ink-muted)">{{ __('notification::rule.channels') }}</legend>
                    @foreach (\App\Models\NotificationChannel::ALL as $channel)
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" name="channels[]" value="{{ $channel }}" @checked(in_array($channel, $channels, true))>
                            {{ __('notification::channel.names.'.$channel) }}
                        </label>
                    @endforeach
                    <p class="text-2xs text-(--color-ink-muted)">{{ __('notification::rule.channels_note') }}</p>
                </fieldset>
                <label class="grid gap-1 self-start text-2xs text-(--color-ink-muted)">
                    {{ __('notification::rule.priority') }}
                    <select name="priority" class="{{ $field }}">
                        <option value="">{{ __('notification::rule.as_module') }}</option>
                        @foreach (\App\Core\Support\NotificationKinds::PRIORITIES as $priority)
                            <option value="{{ $priority }}" @selected(old('priority', $rule->priority) === $priority)>{{ __('core.notify.priority.'.$priority) }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="grid gap-1 self-start text-2xs text-(--color-ink-muted)">
                    {{ __('notification::rule.template') }}
                    <select name="template_id" class="{{ $field }}">
                        <option value="">{{ __('notification::rule.as_module') }}</option>
                        @foreach ($choices['templates'] as $id => $name)
                            <option value="{{ $id }}" @selected((int) old('template_id', $rule->template_id) === (int) $id)>{{ $name }}</option>
                        @endforeach
                    </select>
                </label>
            </div>

            <div class="grid gap-3 md:grid-cols-5">
                @foreach (['delay_minutes', 'expires_minutes', 'cooldown_minutes'] as $number)
                    <label class="grid gap-1 text-2xs text-(--color-ink-muted)">
                        {{ __('notification::rule.'.$number) }}
                        <input type="number" min="0" name="{{ $number }}" value="{{ old($number, $rule->{$number}) }}" class="{{ $field }}">
                    </label>
                @endforeach
                <label class="grid gap-1 text-2xs text-(--color-ink-muted)">
                    {{ __('notification::rule.effective_from') }}
                    <input type="date" name="effective_from" value="{{ old('effective_from', $rule->effective_from?->toDateString()) }}" class="{{ $field }}">
                </label>
                <label class="grid gap-1 text-2xs text-(--color-ink-muted)">
                    {{ __('notification::rule.effective_to') }}
                    <input type="date" name="effective_to" value="{{ old('effective_to', $rule->effective_to?->toDateString()) }}" class="{{ $field }}">
                </label>
            </div>

            <button type="submit" class="rounded-(--radius-field) bg-(--color-brand-500) px-3 py-1.5 text-sm text-white">{{ __('notification::rule.save') }}</button>
        </form>

        @if ($rule->exists)
            <form method="POST" action="{{ route('notification.rules.test', $rule) }}"
                  class="space-y-3 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                @csrf
                <h2 class="text-sm font-medium">{{ __('notification::rule.test_title') }}</h2>
                <p class="text-2xs text-(--color-ink-muted)">{{ __('notification::rule.test_note') }}</p>
                <div class="grid gap-2 md:grid-cols-4">
                    <label class="grid gap-1 text-2xs text-(--color-ink-muted)">
                        {{ __('notification::rule.scope_branch') }}
                        <select name="branch_id" class="{{ $field }}">
                            <option value="">—</option>
                            @foreach ($choices['branches'] as $id => $name)
                                <option value="{{ $id }}" @selected((int) (($sample ?? [])['branch_id'] ?? 0) === (int) $id)>{{ $name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="grid gap-1 text-2xs text-(--color-ink-muted)">
                        {{ __('notification::rule.priority') }}
                        <select name="priority" class="{{ $field }}">
                            <option value="">{{ __('notification::rule.as_module') }}</option>
                            @foreach (\App\Core\Support\NotificationKinds::PRIORITIES as $priority)
                                <option value="{{ $priority }}" @selected((($sample ?? [])['priority'] ?? '') === $priority)>{{ __('core.notify.priority.'.$priority) }}</option>
                            @endforeach
                        </select>
                    </label>
                    @foreach (array_diff(\App\Core\Notifications\NotificationVariables::CONDITION_FIELDS, ['priority', 'branch_id']) as $name)
                        <label class="grid gap-1 text-2xs text-(--color-ink-muted)">
                            {{ __('core.notify.var.'.$name) }}
                            <input type="text" name="values[{{ $name }}]" maxlength="191" value="{{ ($sample ?? [])['values'][$name] ?? '' }}" class="{{ $field }}">
                        </label>
                    @endforeach
                </div>
                <button type="submit" class="rounded-(--radius-field) border border-(--color-border) px-3 py-1.5 text-sm">{{ __('notification::rule.test') }}</button>

                @if ($tested)
                    <div data-rule-test="{{ $matches ? 'match' : 'no-match' }}" class="space-y-2 border-t border-(--color-border) pt-3 text-sm">
                        <p class="font-medium">{{ $matches ? __('notification::rule.test_match') : __('notification::rule.test_no_match') }}</p>
                        @if ($checks !== [])
                            <ul class="text-2xs">
                                @foreach ($checks as $check)
                                    <li>{{ $check['holds'] ? '✓' : '✗' }}
                                        {{ __('core.notify.var.'.($check['condition']['field'] ?? '')) }}
                                        {{ __('notification::rule.ops.'.($check['condition']['op'] ?? 'eq')) }}
                                        {{ $check['condition']['value'] ?? '' }}</li>
                                @endforeach
                            </ul>
                        @endif
                        @if ($matches)
                            <p class="text-2xs text-(--color-ink-muted)">{{ __('notification::rule.would_reach', ['count' => $wouldReach->count()]) }}</p>
                            <ul class="text-2xs">
                                @foreach ($wouldReach as $person)
                                    <li data-would-reach="{{ $person->id }}">{{ $person->name }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                @endif
            </form>

            <section class="space-y-2 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                <h2 class="text-sm font-medium">{{ __('notification::rule.versions') }}</h2>
                <table class="ui-grid">
                    <thead class="bg-(--color-surface-sunken) text-2xs text-(--color-ink-muted)">
                        <tr>
                            <th class="text-start">{{ __('notification::rule.version') }}</th>
                            <th class="text-start">{{ __('notification::rule.changed_by') }}</th>
                            <th class="text-start">{{ __('notification::rule.changed_at') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($versions as $v)
                            <tr class="border-t border-(--color-border)" data-rule-version="{{ $v->version }}">
                                <td class="text-2xs">v{{ $v->version }}</td>
                                <td class="text-2xs">{{ $v->changer?->name ?? '—' }}</td>
                                <td class="text-2xs">{{ $v->created_at?->format('d/m/Y H:i') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </section>
        @endif
    </div>
</x-layouts.app>
