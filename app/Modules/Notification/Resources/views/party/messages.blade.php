{{--
    ⭐ পার্টিকে বার্তা — খাতা: কে, কবে, কোন কাগজ, কোন পার্টি, কোন নম্বরে, কোন ভাষায়, ঠিক কোন লেখা (মালিকের "ok", ১০ নভেম্বর ২০২৬)।
    ⓘ শাখার দেয়ালের ভিতরে ([[ScopedToUserBranch]])।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('notification::party.messages_title') }}</x-slot:title>

    <div class="space-y-4">
        <x-ui.toolbar :title="__('notification::party.messages_title')" :search="false" :export="false" :share="false" />

        <p class="text-sm text-(--color-ink-muted)">{{ __('notification::party.messages_note') }}</p>

        <form method="GET" class="flex flex-wrap items-end gap-3 text-2xs text-(--color-ink-muted)">
            <label class="grid gap-1">{{ __('notification::party.from') }}
                <x-ui.date name="from" :value="$filters['from'] ?? null" />
            </label>
            <label class="grid gap-1">{{ __('notification::party.to') }}
                <x-ui.date name="to" :value="$filters['to'] ?? null" />
            </label>
            <label class="grid gap-1">{{ __('notification::party.paper') }}
                <select name="document_type" class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
                    <option value="">{{ __('notification::party.any_paper') }}</option>
                    @foreach (array_keys(\App\Core\Services\PaperTrail::DOCUMENT_ROUTES) as $type)
                        <option value="{{ $type }}" @selected(($filters['document_type'] ?? '') === $type)>{{ __('notification::party.types.'.$type) }}</option>
                    @endforeach
                </select>
            </label>
            <label class="grid gap-1">{{ __('notification::party.who') }}
                <select name="sent_by" class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
                    <option value="">{{ __('notification::party.anyone') }}</option>
                    @foreach ($senders as $sender)
                        <option value="{{ $sender->id }}" @selected((string) ($filters['sent_by'] ?? '') === (string) $sender->id)>{{ $sender->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="grid gap-1">{{ __('notification::party.search') }}
                <input type="search" name="q" maxlength="60" value="{{ $filters['q'] ?? '' }}" class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
            </label>
            <button type="submit" class="h-(--spacing-field) rounded-(--radius-field) bg-(--color-brand-500) px-3 text-sm text-white">{{ __('notification::party.filter') }}</button>
        </form>

        @if ($rows->isEmpty())
            <p class="text-sm text-(--color-ink-muted)">{{ __('notification::party.log_empty') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="ui-grid">
                    <thead class="bg-(--color-surface-sunken) text-2xs text-(--color-ink-muted)">
                        <tr>
                            <th class="text-start">{{ __('notification::party.when') }}</th>
                            <th class="text-start">{{ __('notification::party.who') }}</th>
                            <th class="text-start">{{ __('notification::party.paper') }}</th>
                            <th class="text-start">{{ __('notification::party.party') }}</th>
                            <th class="text-start">{{ __('notification::party.phone') }}</th>
                            <th class="text-start">{{ __('notification::party.language') }}</th>
                            <th class="text-start">{{ __('notification::party.text') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr class="border-t border-(--color-border) align-top" data-party-message="{{ $row->id }}">
                                <td class="whitespace-nowrap text-2xs tabular-nums">{{ \App\Core\Support\DateFormat::formatWithTime($row->created_at) }}</td>
                                <td class="text-2xs">{{ $row->sender?->name ?? '—' }}</td>
                                <td class="text-2xs">{{ __('notification::party.types.'.$row->document_type) }} <span class="tabular-nums">{{ $row->document_no }}</span></td>
                                <td class="text-2xs">{{ $row->party_name ?? '—' }}</td>
                                <td class="whitespace-nowrap text-2xs tabular-nums">+{{ $row->phone }}</td>
                                <td class="text-2xs">{{ __('notification::party.locales.'.$row->locale) }}</td>
                                <td class="text-2xs">
                                    <details>
                                        <summary class="cursor-pointer">{{ $row->template?->name ?? \Illuminate\Support\Str::limit($row->body, 40) }}</summary>
                                        <p class="mt-1 max-w-md whitespace-pre-line">{{ $row->body }}</p>
                                    </details>
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
