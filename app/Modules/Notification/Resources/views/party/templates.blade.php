{{--
    ⭐ পার্টিকে বার্তা — লেখা (মালিকের "ok", ১০ নভেম্বর ২০২৬; ধাপ ৩ — ২৩ নভেম্বর ২০২৬, নমুনা HUXqpfhxng8823eEFrvH54 ট্যাব খ)।

    মাথা: নাম · ট্যাব (সব / WhatsApp / SMS / বন্ধ) … "+ নতুন লেখা" — এক সারিতে, নিচে ব্যাখ্যার লাইন নেই (মালিক, ১৯ নভেম্বর ২০২৬ রাত)।
    বাঁয়ে তালিকা (নাম · পথ · কোন কাগজ · অবস্থা), পাশে বাছা লেখার সম্পাদক ([[notification::party.form]]); সরু পর্দায় নিচে।
--}}
@php
    $tabs = collect(\App\Modules\Notification\Http\Controllers\PartyMessageController::TEMPLATE_TABS)->map(fn ($key) => [
        'key' => $key,
        'label' => __('notification::party.tabs.'.$key),
        'count' => $counts[$key] ?? null,
        'url' => route('notification.party_templates.index', array_filter(['tab' => $key !== 'all' ? $key : null])),
        'active' => $tab === $key,
    ])->all();
    $tabQuery = $tab !== 'all' ? ['tab' => $tab] : [];
@endphp
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('notification::party.templates_title') }}</x-slot:title>

    <div class="space-y-4">
        <x-ui.page-header :title="__('notification::party.templates_title')">
            <x-slot:actions>
                <x-ui.button tone="primary" icon="plus" :href="route('notification.party_templates.create', $tabQuery)" data-new-template>
                    {{ __('notification::party.new') }}
                </x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>

        <x-ui.list-tabs :label="__('notification::party.tabs_label')" :tabs="$tabs" />

        @include('notification::partials.flash')

        <div class="grid gap-3.5 min-[900px]:grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)]" data-template-split>
            <div class="min-w-0 self-start overflow-x-auto rounded-(--radius-card) border border-(--color-border)">
                @if ($rows->isEmpty())
                    <p class="p-3 text-sm text-(--color-ink-muted)">{{ __('notification::party.empty') }}</p>
                @else
                    <table class="ui-grid w-full">
                        <thead class="text-2xs text-(--color-ink-muted)">
                            <tr>
                                <th class="text-start">{{ __('notification::party.name') }}</th>
                                <th class="text-start">{{ __('notification::party.channel') }}</th>
                                <th class="text-start">{{ __('notification::party.document_type') }}</th>
                                <th class="text-start">{{ __('notification::party.state') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $row)
                                @php
                                    $chosen = $template->exists && (int) $template->id === (int) $row->id;
                                    $isSms = $row->channel === \App\Models\PartyMessageTemplate::SMS;
                                @endphp
                                <tr class="border-t border-(--color-border)" data-party-template="{{ $row->id }}" @if ($chosen) aria-current="true" @endif>
                                    <td>
                                        <a href="{{ route('notification.party_templates.edit', ['template' => $row->id] + $tabQuery) }}"
                                           @class(['hover:underline', 'font-semibold text-(--color-ink)' => $chosen, 'text-(--color-brand-600)' => ! $chosen])>{{ $row->name }}</a>
                                    </td>
                                    <td class="whitespace-nowrap">
                                        <span class="inline-flex items-center gap-1 text-sm font-semibold" data-template-channel="{{ $row->channel }}">
                                            <x-ui.icon :name="$isSms ? 'sms' : 'chat'" :size="14" :class="$isSms ? 'text-(--color-brand-600)' : 'text-(--color-badge-success-ink)'" />
                                            {{ __('notification::party.channels.'.$row->channel) }}
                                        </span>
                                    </td>
                                    <td class="text-sm">{{ $row->document_type === null ? __('notification::party.any_paper') : __('notification::party.types.'.$row->document_type) }}</td>
                                    <td>
                                        <span @class([
                                            'inline-flex whitespace-nowrap rounded-full px-2.5 py-px text-xs font-semibold',
                                            'bg-(--color-badge-success-bg) text-(--color-badge-success-ink)' => $row->is_active,
                                            'bg-(--color-surface-sunken) text-(--color-ink-muted)' => ! $row->is_active,
                                        ]) data-template-state="{{ $row->is_active ? 'active' : 'inactive' }}">{{ __('notification::party.states.'.($row->is_active ? 'active' : 'inactive')) }}</span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>

            @include('notification::party.form', ['template' => $template, 'types' => $types, 'tabQuery' => $tabQuery, 'metaSubmit' => $metaSubmit])
        </div>
    </div>
</x-layouts.app>
