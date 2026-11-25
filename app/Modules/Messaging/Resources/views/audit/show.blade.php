{{--
    ⛔ One thread, read under a written reason (already in the audit trail). Read-only; deleted messages shown and marked.
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('messaging::audit.title') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$thread->isGroup() ? $thread->title : $thread->members->map(fn ($m) => $m->user?->name)->filter()->implode(' · ')">
            <x-slot:actions>
                <x-ui.button :href="route('messaging.audit.index')">{{ __('messaging::audit.back') }}</x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>

    <x-ui.errors />

    <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
        <ol class="space-y-2 text-sm">
            @foreach ($messages as $m)
                <li class="flex gap-3">
                    <span class="w-36 shrink-0 whitespace-nowrap">{{ \App\Core\Support\DateFormat::formatWithTime($m->created_at) }}</span>
                    <span class="w-40 shrink-0 font-semibold">{{ $m->sender?->name ?? '—' }}</span>
                    <span class="min-w-0 flex-1 break-words">
                        @if ($m->kind === 'paper')
                            {{ $m->paper_type }} {{ $m->paper_no }}
                        @elseif ($m->kind === 'location')
                            {{ __('messaging::messenger.kind.location') }} {{ $m->lat }}, {{ $m->lng }}
                        @elseif ($m->file)
                            [{{ __('messaging::messenger.kind.'.($m->kind === 'image' ? 'image' : ($m->kind === 'voice' ? 'voice' : 'file'))) }}] {{ $m->file->name }}
                        @endif
                        {{ $m->text }}
                        @if ($m->forwarded) <x-ui.badge tone="gray">{{ __('messaging::audit.forwarded') }}</x-ui.badge> @endif
                        @if ($m->isDeleted()) <x-ui.badge tone="red">{{ __('messaging::audit.deleted') }}</x-ui.badge> @endif
                    </span>
                </li>
            @endforeach
        </ol>
    </section>

    <x-ui.pager :rows="$messages" />
    <x-ui.list-totals :rows="$messages" />
</x-layouts.app>
