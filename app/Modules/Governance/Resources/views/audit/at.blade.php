{{--
    সময়যন্ত্র — "ওইদিন এই কাগজটা কেমন ছিল"।

    ── কেন ইতিহাসের তালিকাটা যথেষ্ট ছিল না ─────────────────────────────
    পাশের পাতাটা বলে **কী কী হয়েছে** — চল্লিশটা সারি, নতুন আগে। কিন্তু
    নিরীক্ষার প্রশ্নটা ওটা নয়। প্রশ্নটা হয়: *"৩০ জুনের ব্যালেন্স শিটে
    এই বিলটা কত ছিল?"* — আর তার উত্তর পেতে চল্লিশটা সারি হাতে উল্টো
    দিকে মিলিয়ে যেতে হত, প্রতিবার।

    ── তিনটা নিশ্চয়তার মাত্রা, আর কেন সেটা লুকানো হয়নি ─────────────────
    সবচেয়ে সহজ পথ ছিল প্রতিটা ঘরে একটা করে মান দেখিয়ে দেওয়া। কিন্তু
    কিছু ঘর সম্পর্কে সত্যিই **কিছু জানা নেই** — যেগুলো অডিটে যায় না,
    বা যে সারিগুলো অডিট চালু হওয়ার আগের।

    ওখানে আজকের মানটা বসিয়ে দিলে পর্দাটা সম্পূর্ণ দেখাত আর মিথ্যা
    বলত — নীরবে, আত্মবিশ্বাসের সাথে। নিরীক্ষায় "জানি না" একটা বৈধ
    উত্তর; ভুল সংখ্যা নয়।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $trail->title() }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header
            :title="$trail->title()"
            :subtitle="__('governance::label.as_it_stood_on', ['on' => $on->format('d M Y')])">
            <x-slot:actions>
                <x-ui.button :href="route('governance.audit.record', $trail->id)">
                    {{ __('governance::label.full_history') }}
                </x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>

    {{--
        তারিখ বাছাই।

        GET, কারণ এটা একটা প্রশ্ন, কোনো পরিবর্তন নয় — আর তাই ঠিকানাটা
        কপি করে পাঠানো যায়, বুকমার্কও করা যায়।
    --}}
    <form method="GET" action="{{ route('governance.audit.at', $trail->id) }}"
          class="mb-4 flex flex-wrap items-end gap-3">
        <x-ui.date name="on"
                   :value="$on->toDateString()"
                   aria-label="{{ __('governance::field.as_on') }}"
                   class="h-(--spacing-field-compact) rounded-(--radius-field) border border-(--color-border)
                          bg-(--color-surface-app) px-2 text-sm" />

        <x-ui.button type="submit" variant="primary">
            {{ __('governance::label.show_that_day') }}
        </x-ui.button>
    </form>

    @if (! $state['existed'] && ! $state['deleted'])
        <p class="rounded-(--radius-field) bg-(--color-surface-hover) px-3 py-2 text-2xs text-(--color-ink-muted)">
            {{ __('governance::message.did_not_exist_yet', ['on' => $on->format('d M Y')]) }}
        </p>
    @elseif ($state['deleted'])
        <p class="rounded-(--radius-field) bg-(--color-surface-hover) px-3 py-2 text-2xs text-(--color-ink-muted)">
            {{ __('governance::message.was_already_deleted', ['on' => $on->format('d M Y')]) }}
        </p>
    @else
        @unless ($state['complete'])
            {{--
                ইতিহাসের শুরুটা নেই।

                সারিটা অডিট চালু হওয়ার আগের, তাই ভিত্তিটাই অজানা। এটা
                না লিখলে অসম্পূর্ণ একটা ছবি সম্পূর্ণ বলে চালিয়ে দেওয়া হত।
            --}}
            <p class="mb-4 rounded-(--radius-field) bg-(--color-surface-hover) px-3 py-2 text-2xs
                      text-(--color-ink-muted)">
                {{ __('governance::message.history_starts_late', [
                    'on' => $state['history_begins']?->format('d M Y') ?? '—',
                ]) }}
            </p>
        @endunless

        <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                               bg-(--color-surface-card)">
            <x-ui.table
                :empty="__('governance::message.nothing_yet')"
                :rows="collect($state['fields'])->map(fn ($cell, $field) => (object) [
                    'field' => $field,
                    'value' => $cell['value'],
                    'certainty' => $cell['certainty'],
                ])->values()"
                :columns="[
                    ['key' => 'field', 'label' => __('governance::field.the_field'), 'width' => '18rem',
                     'render' => fn ($r) => $r->field],
                    ['key' => 'value', 'label' => __('governance::field.value_then'),
                     'render' => fn ($r) => $r->certainty === \App\Core\Engines\Audit\TimeMachine::KNOWN
                        ? (string) $r->value
                        : '—'],
                    ['key' => 'certainty', 'label' => __('governance::field.how_sure'), 'width' => '14rem',
                     'render' => fn ($r) => __('governance::certainty.' . $r->certainty)],
                ]" />
        </div>

        <p class="mt-4 text-2xs text-(--color-ink-muted)">
            {{ __('governance::message.built_from_changes', [
                'applied' => $state['applied'],
                'later' => $state['later'],
            ]) }}
        </p>
    @endif
</x-layouts.app>
