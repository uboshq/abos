{{--
    ⭐ পার্টিকে বার্তা — খাতা: কে, কবে, কোন কাগজ, কোন পার্টি, কোন নম্বরে, কোন ভাষায়, ঠিক কোন লেখা (মালিকের "ok", ১০ নভেম্বর ২০২৬)।
    ⓘ শাখার দেয়ালের ভিতরে ([[ScopedToUserBranch]])।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('notification::party.messages_title') }}</x-slot:title>

    <div class="space-y-4">
        <x-ui.toolbar :title="__('notification::party.messages_title')" :info="__('notification::party.messages_note')" :search="false" :export="false" :share="false" />

        {{-- ⭐ ব্যাখ্যা নামের পাশে ⓘ-এ, লাইনটা "ABOS"/"Dynamic"-এ লুকায় ([[ui/page-info]]; মালিক, ১৯ নভেম্বর ২০২৬ রাত) --}}
        <p data-page-subtitle class="text-sm text-(--color-ink-muted)">{{ __('notification::party.messages_note') }}</p>

        {{-- ⭐ ছাঁকনি ডান পাশের প্যানেলে — আগের ঘরগুলো, একই চাবি (মালিক, ২৩ নভেম্বর ২০২৬; [[Facets]]); খোঁজার ঘর বারেই --}}
        @php
            $facetPanel = \App\Core\ListFilter\Facets::panel([
                \App\Core\ListFilter\Facets::dates(__('core.list_filter.date')),
                \App\Core\ListFilter\Facets::pick('document_type', __('notification::party.paper'),
                    collect(array_keys(\App\Core\Services\PaperTrail::DOCUMENT_ROUTES))->mapWithKeys(fn ($t) => [$t => __('notification::party.types.'.$t)])->all(),
                    __('notification::party.any_paper')),
                \App\Core\ListFilter\Facets::pick('sent_by', __('notification::party.who'),
                    collect($senders)->mapWithKeys(fn ($s) => [(string) $s->id => $s->name])->all(), __('notification::party.anyone')),
            ]);
        @endphp
        <x-ui.filter-layout :filters="$facetPanel" form="list-form">
        <form id="list-form" method="GET" class="contents">
            <x-ui.filter-bar :facets="$facetPanel">
                <label class="ms-auto">
                    <span class="sr-only">{{ __('notification::party.search') }}</span>
                    <input type="search" name="q" maxlength="60" value="{{ $filters['q'] ?? '' }}" placeholder="{{ __('notification::party.search') }}"
                           class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
                </label>
            </x-ui.filter-bar>
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
                            <th class="text-start">{{ __('notification::party.channel') }}</th>
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
                                {{-- ⓘ WhatsApp = খোলা হয়েছে; SMS = অফিসের ফোনের সারির অবস্থা (অপেক্ষায় → গেছে → পৌঁছেছে, বা যায়নি) --}}
                                <td class="text-2xs" data-party-channel="{{ $row->channel }}">
                                    {{ __('notification::party.channels.'.$row->channel) }}
                                    @if ($row->channel === 'sms' && ($sms = ($smsStates ?? collect())->get($row->id)))
                                        <span class="block text-(--color-ink-muted)" data-sms-state="{{ $sms->delivered_at !== null ? 'delivered' : $sms->status }}">{{ __('notification::sms.statuses.'.($sms->delivered_at !== null ? 'delivered' : $sms->status)) }}</span>
                                    @endif
                                </td>
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
        </x-ui.filter-layout>
    </div>
</x-layouts.app>
