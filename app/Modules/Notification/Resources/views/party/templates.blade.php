{{-- ⭐ পার্টিকে বার্তা — লেখাগুলোর তালিকা (মালিকের "ok", ১০ নভেম্বর ২০২৬) --}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('notification::party.templates_title') }}</x-slot:title>

    <div class="space-y-4">
        <x-ui.toolbar :title="__('notification::party.templates_title')" :search="false" :export="false" :share="false" />

        <div class="flex items-center justify-between gap-2">
            <p class="text-sm text-(--color-ink-muted)">{{ __('notification::party.templates_note') }}</p>
            <a href="{{ route('notification.party_templates.create') }}" class="shrink-0 rounded-(--radius-field) bg-(--color-brand-500) px-3 py-1.5 text-sm text-white">{{ __('notification::party.new') }}</a>
        </div>

        @include('notification::partials.flash')

        @if ($rows->isEmpty())
            <p class="text-sm text-(--color-ink-muted)">{{ __('notification::party.empty') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="ui-grid">
                    <thead class="bg-(--color-surface-sunken) text-2xs text-(--color-ink-muted)">
                        <tr>
                            <th class="text-start">{{ __('notification::party.name') }}</th>
                            <th class="text-start">{{ __('notification::party.channel') }}</th>
                            <th class="text-start">{{ __('notification::party.document_type') }}</th>
                            <th class="text-start">{{ __('notification::party.body_bn') }}</th>
                            <th class="num">{{ __('notification::party.sort') }}</th>
                            <th class="text-start">{{ __('notification::party.state') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr class="border-t border-(--color-border)" data-party-template="{{ $row->id }}">
                                <td><a href="{{ route('notification.party_templates.edit', $row) }}" class="text-(--color-brand-500) hover:underline">{{ $row->name }}</a></td>
                                <td class="text-2xs">{{ __('notification::party.channels.'.$row->channel) }}</td>
                                <td class="text-2xs">{{ $row->document_type === null ? __('notification::party.any_paper') : __('notification::party.types.'.$row->document_type) }}</td>
                                <td class="max-w-md truncate text-2xs" title="{{ $row->body_bn }}">{{ \Illuminate\Support\Str::limit(str_replace("\n", ' ', $row->body_bn), 90) }}</td>
                                <td class="num text-2xs">{{ $row->sort }}</td>
                                <td class="text-2xs">{{ __('notification::party.states.'.($row->is_active ? 'active' : 'inactive')) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-layouts.app>
