{{--
    ⭐ খোঁজা ও কোপাইলটের খাতা — কে কী খুঁজল, কে কী জিজ্ঞেস করল, কোপাইলট কী বলল (মালিক, ১০ নভেম্বর ২০২৬; পূর্ণ পরিকল্পনা অংশ ১২)।
    ⓘ কেবল মালিকের চাবি; ছাঁকা যায় — ব্যক্তি, ধরন, তারিখ, লেখা; বদলানো বা মোছা যায় না।
--}}
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
                <select name="kind" class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
                    <option value="">{{ __('assistant::audit.any') }}</option>
                    @foreach (\App\Models\AssistantEvent::KINDS as $kind)
                        <option value="{{ $kind }}" @selected(($filters['kind'] ?? '') === $kind)>{{ __('assistant::audit.kinds.'.$kind) }}</option>
                    @endforeach
                </select>
            </label>
            <label class="grid gap-1">{{ __('assistant::audit.who') }}
                <select name="user_id" class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
                    <option value="">{{ __('assistant::audit.anyone') }}</option>
                    @foreach ($people as $person)
                        <option value="{{ $person->id }}" @selected((string) ($filters['user_id'] ?? '') === (string) $person->id)>{{ $person->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="grid gap-1">{{ __('assistant::audit.search') }}
                <input type="search" name="q" maxlength="60" value="{{ $filters['q'] ?? '' }}" class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
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
                            <th class="text-start">{{ __('assistant::audit.outcome') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr class="border-t border-(--color-border) align-top" data-assistant-event="{{ $row->kind }}">
                                <td class="whitespace-nowrap text-2xs tabular-nums">{{ \App\Core\Support\DateFormat::formatWithTime($row->created_at) }}</td>
                                <td class="text-2xs">{{ $row->user?->name ?? $name }}</td>
                                <td class="text-2xs">{{ __('assistant::audit.kinds.'.$row->kind) }}</td>
                                <td class="max-w-md text-2xs">
                                    {{ $row->text ?? __('assistant::audit.text_gone') }}
                                    @if ($row->answer)
                                        <details><summary class="cursor-pointer text-(--color-link)">{{ __('assistant::audit.answer') }}</summary><p class="mt-1 whitespace-pre-line">{{ $row->answer }}</p></details>
                                    @endif
                                    @if ($row->reason)<span class="block text-(--color-ink-muted)">{{ __('assistant::audit.reason') }}: {{ $row->reason }}</span>@endif
                                </td>
                                <td class="text-2xs">{{ $row->page ?? ($row->module ? __('assistant::page.module.'.$row->module) : '—') }}</td>
                                <td class="text-2xs">
                                    @if ($row->kind === \App\Models\AssistantEvent::SEARCH)
                                        {{ __('assistant::audit.hits', ['n' => (int) $row->outcome]) }}
                                    @else
                                        {{ $row->outcome ?? '—' }}
                                    @endif
                                    @if ($row->feedback !== null) {{ $row->feedback > 0 ? '👍' : '👎' }} @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <x-ui.pager :rows="$rows" />
        <x-ui.list-totals :rows="$rows" />
    </div>
</x-layouts.app>
