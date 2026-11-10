{{--
    ⭐ খাতা — কে কী করল, কী খুঁজল, কোন পাতা খুলল, কী নামাল, কী বদলাল, কখন ঢুকল; কার আইডি থেকে, কখন, কোথা থেকে, কীভাবে
    (মালিক, ১০ ও ১১ নভেম্বর ২০২৬; পূর্ণ পরিকল্পনা অংশ ১২)। ⓘ তিন খাতা এক সময়-রেখায় ([[AssistantController::audit()]])।
    ⓘ কেবল মালিকের চাবি; ছাঁকা যায় — তারিখ, ধরন, ব্যক্তি, লেখা, IP; বদলানো বা মোছা যায় না।
--}}
@php
    $tone = [
        'change' => 'bg-(--color-badge-warning-bg) text-(--color-badge-warning-ink)',
        'login' => 'bg-(--color-badge-success-bg) text-(--color-badge-success-ink)',
        'search' => 'bg-(--color-surface-selected) text-(--color-link)',
        'blocked' => 'bg-(--color-badge-danger-bg) text-(--color-badge-danger-ink)',
    ];
    $field = 'h-(--spacing-field) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm';
@endphp
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('assistant::audit.title', ['name' => $name]) }}</x-slot:title>

    <div class="space-y-4" data-assistant-audit>
        <x-ui.toolbar :title="__('assistant::audit.title', ['name' => $name])" :search="false" :export="false" :share="false" />

        <p class="text-sm text-(--color-ink-muted)">{{ __('assistant::audit.note') }}</p>

        <form method="GET" class="flex flex-wrap items-end gap-3 text-2xs text-(--color-ink-muted)">
            <label class="grid gap-1">{{ __('assistant::audit.from') }}
                <x-ui.date name="from" :value="$filters['from'] ?? null" />
            </label>
            <label class="grid gap-1">{{ __('assistant::audit.to') }}
                <x-ui.date name="to" :value="$filters['to'] ?? null" />
            </label>
            <label class="grid gap-1">{{ __('assistant::audit.kind') }}
                <select name="kind" class="{{ $field }}">
                    <option value="">{{ __('assistant::audit.any') }}</option>
                    @foreach ($kinds as $kind)
                        <option value="{{ $kind }}" @selected(($filters['kind'] ?? '') === $kind)>{{ __('assistant::audit.kinds.'.$kind) }}</option>
                    @endforeach
                </select>
            </label>
            <label class="grid gap-1">{{ __('assistant::audit.who') }}
                <select name="user_id" class="{{ $field }}">
                    <option value="">{{ __('assistant::audit.anyone') }}</option>
                    @foreach ($people as $person)
                        <option value="{{ $person->id }}" @selected((string) ($filters['user_id'] ?? '') === (string) $person->id)>{{ $person->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="grid gap-1">{{ __('assistant::audit.search') }}
                <input type="search" name="q" maxlength="60" value="{{ $filters['q'] ?? '' }}" class="{{ $field }}">
            </label>
            <label class="grid gap-1">IP
                <input type="search" name="ip" maxlength="45" value="{{ $filters['ip'] ?? '' }}" class="{{ $field }} w-36">
            </label>
            <button type="submit" class="h-(--spacing-field) rounded-(--radius-field) bg-(--color-brand-500) px-3 text-sm text-white">{{ __('assistant::audit.filter') }}</button>
        </form>

        @if ($rows->isEmpty())
            <p class="text-sm text-(--color-ink-muted)">{{ __('assistant::audit.empty') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="ui-grid">
                    <thead class="bg-(--color-surface-sunken) text-2xs text-(--color-ink-muted)">
                        <tr>
                            <th class="text-start">{{ __('assistant::audit.when') }}</th>
                            <th class="text-start">{{ __('assistant::audit.who') }}</th>
                            <th class="text-start">{{ __('assistant::audit.kind') }}</th>
                            <th class="text-start">{{ __('assistant::audit.what') }}</th>
                            <th class="text-start">{{ __('assistant::audit.page') }}</th>
                            <th class="text-start">{{ __('assistant::audit.how') }}</th>
                            <th class="text-start">{{ __('assistant::audit.where') }}</th>
                            <th class="text-start">{{ __('assistant::audit.outcome') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            @php
                                $person = $who->get($row->user_id);
                                $channel = $row->channel ?? null;
                            @endphp
                            <tr class="border-t border-(--color-border) align-top" data-audit-row="{{ $row->kind }}">
                                <td class="whitespace-nowrap text-2xs tabular-nums">{{ \App\Core\Support\DateFormat::formatWithTime(\Illuminate\Support\Carbon::parse($row->at)) }}</td>
                                <td class="text-2xs">
                                    @if ($row->user_id === null)
                                        {{ $name }}
                                    @else
                                        <span class="block font-medium">{{ $person?->name ?? '#'.$row->user_id }}</span>
                                        <span class="block text-(--color-ink-muted)">ID {{ $row->user_id }}@if ($person?->email) · {{ $person->email }}@endif</span>
                                    @endif
                                </td>
                                <td class="text-2xs"><span class="rounded-(--radius-badge) px-1.5 py-0.5 {{ $tone[$row->kind] ?? 'bg-(--color-surface-sunken) text-(--color-ink)' }}">{{ __('assistant::audit.kinds.'.$row->kind) }}</span></td>
                                <td class="max-w-md text-2xs">
                                    @if ($row->kind === 'visit' && str_starts_with((string) $row->what, '/') === false && isset($pages[strtok((string) $row->page, '?')]))
                                        {{ $pages[strtok((string) $row->page, '?')] }}
                                    @else
                                        {{ $row->what ?? __('assistant::audit.text_gone') }}
                                    @endif
                                    @if ($row->reason)<span class="block text-(--color-ink-muted)">{{ __('assistant::audit.reason') }}: {{ $row->reason }}</span>@endif
                                </td>
                                <td class="max-w-xs break-all text-2xs">{{ $row->kind === 'change' ? class_basename((string) $row->page) : ($row->page ?? '—') }}</td>
                                <td class="whitespace-nowrap text-2xs" title="{{ $row->user_agent }}">
                                    {{ \App\Modules\Assistant\Support\Device::name($row->user_agent, $channel) }}
                                    @if ($channel === 'app' || $channel === 'web')<span class="block text-(--color-ink-muted)">{{ __('assistant::audit.channels.'.$channel) }}</span>@endif
                                </td>
                                <td class="whitespace-nowrap text-2xs tabular-nums">
                                    {{ $row->ip_address ?? '—' }}
                                    @if ($row->place)<span class="block text-(--color-ink-muted)">{{ $row->place }}</span>@endif
                                    @if ($row->branch_id && isset($branches[$row->branch_id]))<span class="block text-(--color-ink-muted)">{{ $branches[$row->branch_id] }}</span>@endif
                                </td>
                                <td class="text-2xs">
                                    @if ($row->kind === 'search')
                                        {{ __('assistant::audit.hits', ['n' => (int) $row->outcome]) }}
                                    @elseif ($row->kind === 'login')
                                        {{ $row->outcome === 'ok' ? __('assistant::audit.login_ok') : __('assistant::audit.login_failed').' · '.$row->outcome }}
                                    @elseif ($row->kind === 'change')
                                        {{ \Illuminate\Support\Facades\Lang::has('assistant::audit.actions.'.$row->outcome) ? __('assistant::audit.actions.'.$row->outcome) : str_replace('_', ' ', (string) $row->outcome) }}
                                    @else
                                        {{ $row->outcome ?? '—' }}
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <x-ui.pager :rows="$rows" />
    </div>
</x-layouts.app>
