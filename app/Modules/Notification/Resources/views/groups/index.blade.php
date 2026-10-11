{{-- ⭐ প্রাপক-দলের তালিকা (মালিকের স্পেক §৪ "Recipient Management"; ধাপ ৩) --}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('notification::group.title') }}</x-slot:title>

    <div class="space-y-4">
        <x-ui.toolbar :title="__('notification::group.title')" :search="false" :export="false" :share="false" />

        <div class="flex items-center justify-between gap-2">
            <p class="text-sm text-(--color-ink-muted)">{{ __('notification::group.note') }}</p>
            <a href="{{ route('notification.groups.create') }}" class="shrink-0 rounded-(--radius-field) bg-(--color-brand-500) px-3 py-1.5 text-sm text-white">{{ __('notification::group.new') }}</a>
        </div>

        @include('notification::partials.flash')

        @if ($rows->isEmpty())
            <p class="text-sm text-(--color-ink-muted)">{{ __('notification::group.empty') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="ui-grid">
                    <thead class="bg-(--color-surface-sunken) text-2xs text-(--color-ink-muted)">
                        <tr>
                            <th class="text-start">{{ __('notification::group.name') }}</th>
                            @foreach (\App\Models\NotificationRecipientGroup::KINDS as $kind)
                                <th class="text-end">{{ __('notification::rule.kinds.'.$kind) }}</th>
                            @endforeach
                            <th class="text-start">{{ __('notification::rule.state') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $group)
                            <tr class="border-t border-(--color-border)" data-group="{{ $group->id }}">
                                <td><a href="{{ route('notification.groups.edit', $group) }}" class="hover:underline">{{ $group->name }}</a></td>
                                @foreach (\App\Models\NotificationRecipientGroup::KINDS as $kind)
                                    <td class="text-end text-2xs tabular-nums">{{ count($group->ids($kind)) }}</td>
                                @endforeach
                                <td class="text-2xs">{{ __('notification::rule.states.'.($group->is_active ? 'active' : 'inactive')) }}</td>
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
