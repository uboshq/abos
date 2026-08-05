{{--
    একটা অডিট ঘটনার বিস্তারিত।

    উপরে কে-কখন-কোথা থেকে, নিচে ঘর ধরে ধরে পুরাতন ও নতুন মান।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $trail->title() }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header
            :title="__('governance::action.' . $trail->action) . ' — ' . $trail->title()"
            :subtitle="$trail->created_at->format('d M Y, H:i')">
            <x-slot:actions>
                <x-ui.button :href="route('governance.audit.record', $trail->id)">
                    {{ __('governance::label.full_history') }}
                </x-ui.button>
                <x-ui.button :href="route('governance.audit.index')">
                    {{ __('governance::label.back_to_trail') }}
                </x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>

    <div class="grid gap-4 lg:grid-cols-[22rem_1fr]">
        <section class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <dl class="space-y-2 text-sm">
                @foreach ([
                    'governance::field.who' => $trail->user?->name ?? __('governance::message.system'),
                    'governance::field.when' => $trail->created_at->format('d M Y, H:i:s'),
                    'governance::field.action' => __('governance::action.' . $trail->action),
                    'governance::field.module' => $trail->moduleLabel(),
                    'governance::field.branch' => $trail->branch?->name(),
                    'governance::field.ip' => $trail->ip_address,
                    'governance::field.reason' => $trail->reason,
                ] as $label => $value)
                    <div class="flex items-start justify-between gap-2">
                        <dt class="text-(--color-ink-muted)">{{ __($label) }}</dt>
                        <dd class="text-end">{{ filled($value) ? $value : '—' }}</dd>
                    </div>
                @endforeach

                {{-- ডিভাইসের লেখাটা লম্বা, তাই নিচে আলাদা করে ছোট হরফে --}}
                @if (filled($trail->user_agent))
                    <div class="border-t border-(--color-border) pt-2">
                        <dt class="text-2xs text-(--color-ink-muted)">{{ __('governance::field.device') }}</dt>
                        <dd class="mt-1 break-words text-2xs">{{ $trail->user_agent }}</dd>
                    </div>
                @endif
            </dl>

            @if ($record === null)
                <p class="mt-3 border-t border-(--color-border) pt-3 text-2xs text-(--color-ink-muted)">
                    {{ __('governance::message.record_gone') }}
                </p>
            @endif
        </section>

        <section class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card)">
            <h2 class="border-b border-(--color-border) px-4 py-2 font-semibold">
                {{ __('governance::field.changes') }}
            </h2>

            @if ($trail->changes->isEmpty())
                <p class="p-4 text-sm text-(--color-ink-muted)">
                    {{ __('governance::message.no_field_changes') }}
                </p>
            @else
                <x-ui.table
                    :empty="__('governance::message.no_field_changes')"
                    :rows="$trail->changes"
                    :columns="[
                        ['key' => 'field', 'label' => __('governance::field.field'), 'width' => '14rem',
                         'render' => fn ($c) => $c->field],
                        ['key' => 'old_value', 'label' => __('governance::field.old_value'),
                         'render' => fn ($c) => $c->old_value ?? '—'],
                        ['key' => 'new_value', 'label' => __('governance::field.new_value'),
                         'render' => fn ($c) => $c->new_value ?? '—'],
                    ]" />
            @endif
        </section>
    </div>
</x-layouts.app>
