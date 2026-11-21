{{--
    ⛔ Messenger audit — who talked with whom, and when; never a word of what was said until a reason is written.
    ⓘ Each row opens only through the reason form ([[MessengerAuditController::open()]]), recorded in the audit trail.
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('messaging::audit.title') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('messaging::audit.title')" />
    </x-slot:header>

    <div class="space-y-4">
        <form method="GET" action="{{ route('messaging.audit.index') }}" class="flex flex-wrap items-end gap-2">
            <x-ui.field :label="__('messaging::audit.search')" name="q">
                <input type="search" name="q" value="{{ $q }}" class="w-72 rounded-(--radius-input) border border-(--color-border) bg-(--color-surface-card) px-3 py-2 text-sm">
            </x-ui.field>
            <x-ui.button type="submit">{{ __('messaging::audit.find') }}</x-ui.button>
        </form>

        <section data-boxed class="overflow-x-auto rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left">
                        <th class="px-3 py-2">{{ __('messaging::audit.who') }}</th>
                        <th class="px-3 py-2">{{ __('messaging::audit.kind') }}</th>
                        <th class="px-3 py-2">{{ __('messaging::audit.last_at') }}</th>
                        <th class="px-3 py-2">{{ __('messaging::audit.reason') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($threads as $thread)
                        <tr class="border-t border-(--color-border) align-top">
                            <td class="px-3 py-2">
                                @if ($thread->isGroup())
                                    <strong>{{ $thread->title }}</strong> ·
                                @endif
                                {{ $thread->members->map(fn ($m) => $m->user?->name)->filter()->implode(', ') }}
                            </td>
                            <td class="px-3 py-2">{{ __('messaging::audit.kind_'.$thread->kind) }}</td>
                            <td class="px-3 py-2 whitespace-nowrap">{{ $thread->last_at?->format('d-m-Y H:i') }}</td>
                            <td class="px-3 py-2">
                                <form method="POST" action="{{ route('messaging.audit.open', ['thread' => $thread->public_id]) }}" class="flex gap-2">
                                    @csrf
                                    <input type="text" name="reason" required minlength="10" maxlength="500"
                                        aria-label="{{ __('messaging::audit.reason') }}"
                                        placeholder="{{ __('messaging::audit.reason_placeholder') }}"
                                        class="w-72 rounded-(--radius-input) border border-(--color-border) bg-(--color-surface-card) px-2 py-1 text-sm">
                                    <x-ui.button type="submit">{{ __('messaging::audit.open') }}</x-ui.button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-3 py-6 text-center">{{ __('messaging::audit.empty') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>

        {{ $threads->links() }}
    </div>
</x-layouts.app>
