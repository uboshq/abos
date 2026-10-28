{{--
    অনুমোদনের নিয়ম — এক সারিতে একটা নিয়ম, খোলা নিয়মটা নিচে ধাপের কার্ডে।

    ⭐ অনুমোদন পর্দা ভাগ ৩, দফা ১৭ (মালিকের "হ্যাঁ", ২১ অক্টোবর ২০২৬; পরিকল্পনার পাতা ৪): কাজ · ধাপের রেখা · কত টাকা থেকে ·
    শেষ অনুমোদন · চালু/বন্ধের সুইচ · খুলুন। ⛔ আগে কার্ডের তালিকা আর আলাদা সম্পাদনার পাতা — কে কত টাকা থেকে অনুমোদন দেন, খুলে খুঁজতে হত।
    ⓘ আগের সব ঘর রয়ে গেছে: সংকেত, মডিউল, কারণ (খোলা নিয়মের মাথায়), খোঁজা, রপ্তানি, পাতা বদল, "বন্ধ" চিহ্ন।

    খালি থাকা মানে কোথাও অনুমোদন লাগছে না — আর সেটা একটা সিদ্ধান্তও হতে পারে। তাই খালি পর্দায় শুধু "কিছু নেই" নয়, কী হচ্ছে সেটাও লেখা।
--}}
@php
    $workOf = fn ($flow) => __($choices[$flow->module]['actions'][$flow->action] ?? $flow->action);

    // ⓘ ধাপের নাম থাকলে সেটা, নইলে রোল বা মানুষের নাম — ইনবক্সের যাত্রাপথের একই নিয়ম
    $whoOf = fn ($step) => filled($step->step_name) ? $step->step_name : ($names[$step->approver_type][$step->approver_id] ?? '#'.$step->approver_id);

    $levelsOf = fn ($flow) => $flow->steps->sortBy('level')->groupBy('level')
        ->map(fn ($atLevel) => ['who' => $atLevel->map($whoOf)->unique()->implode(' / '), 'from' => $atLevel->first()->from_amount]);
@endphp
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('approval::menu.flows') }}</x-slot:title>

    @include('approval::partials.rule-tabs')

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    @error('flow')
        <div role="alert"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm
                    text-(--color-badge-danger-ink)">
            {{ $message }}
        </div>
    @enderror

    {{-- ⓘ শিরোনাম, গোনা, খোঁজা, রপ্তানি আর "নতুন নিয়ম" টুলবারে (মালিক, ১৯ ও ২২ সেপ্টেম্বর ২০২৬); গোনার জায়গায় এখন পরিকল্পনার
         সারাংশ — কয়টা চালু, কয়টা বন্ধ। ⛔ ঘনত্ব বন্ধ: ওটা কেবল `x-ui.table` মানে। --}}
    <div data-boxed class="mb-3 overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form method="GET" class="contents">
            <x-ui.toolbar :title="__('approval::menu.flows')"
                          :count="__('approval::message.flows_summary', ['live' => $live, 'off' => $off])"
                          :density="false">
                <x-slot:actions>
                    <x-ui.button tone="primary" icon="plus" :href="route('approval.flow.create')">
                        {{ __('approval::action.new_flow') }}
                    </x-ui.button>
                </x-slot:actions>
            </x-ui.toolbar>
        </form>
    </div>

    {{-- ⚠️ খোঁজার পর খালি পাওয়া আর সত্যিই কিছু না থাকা — দুইটা আলাদা কথা। --}}
    @if ($flows->isEmpty() && $q !== '')
        <div data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-6">
            <p class="text-sm font-medium">{{ __('approval::message.no_match') }}</p>
            <p class="mt-1 text-sm text-(--color-ink-muted)">&ldquo;{{ $q }}&rdquo;</p>
        </div>
    @elseif ($flows->isEmpty())
        <div data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-6">
            <p class="text-sm font-medium">{{ __('approval::message.no_flows') }}</p>
            <p class="mt-1 max-w-(--spacing-prose-max) text-sm text-(--color-ink-muted)">
                {{ __('approval::message.no_flows_hint') }}
            </p>
        </div>
    @else
        <div data-boxed data-flow-list class="overflow-x-auto rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
            <table class="ui-grid">
                <thead class="bg-(--color-surface-sunken) text-2xs text-(--color-ink-muted)">
                    <tr>
                        <th class="text-start">{{ __('approval::field.work') }}</th>
                        <th class="text-start">{{ __('approval::field.steps') }}</th>
                        <th class="text-end">{{ __('approval::field.from_amount') }}</th>
                        <th class="text-start">{{ __('approval::field.last_signer') }}</th>
                        <th class="text-start">{{ __('approval::field.on_off') }}</th>
                        <th><span class="sr-only">{{ __('core.table.actions') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($flows as $flow)
                        @php
                            $levels = $levelsOf($flow);
                            $last = $levels->last();
                            $isPayroll = [$flow->module, $flow->action] === $payroll;
                        @endphp
                        <tr data-flow-row="{{ $flow->id }}" @class(['bg-(--color-surface-hover)' => $opened?->id === $flow->id])>
                            <td>
                                <span class="font-semibold">{{ $workOf($flow) }}</span>
                                {{-- ⭐ সংকেত — নিয়মকে নাম ধরে ডাকার জন্য (মালিক, ২২ সেপ্টেম্বর ২০২৬) --}}
                                <span class="block text-2xs text-(--color-ink-muted)">
                                    <span class="font-mono">{{ $flow->code }}</span> · {{ $choices[$flow->module]['label'] ?? $flow->module }}
                                </span>
                            </td>
                            <td>
                                @if ($levels->isEmpty())
                                    —
                                @else
                                    {{-- ⓘ একই ধাপে দুইজন "/" দিয়ে, পরের ধাপ "→" দিয়ে --}}
                                    {{ $levels->pluck('who')->implode(' → ') }}
                                    @if ($flow->steps->contains(fn ($s) => $s->requires_all || $s->min_approvals > 1))
                                        <span class="ms-1 rounded-full bg-(--color-badge-info-bg) px-2 py-0.5 text-2xs text-(--color-badge-info-ink)">
                                            {{ __('approval::field.requires_all') }}
                                        </span>
                                    @endif
                                @endif
                            </td>
                            <td class="num">
                                {{ $flow->threshold_amount === null ? __('approval::field.any_amount') : '৳'.\App\Core\Support\Money::format($flow->threshold_amount) }}
                            </td>
                            <td>
                                @if ($last === null)
                                    —
                                @else
                                    {{ $last['who'] }}
                                    @if ($last['from'] !== null)
                                        <span class="text-(--color-ink-muted)">{{ __('approval::message.from_or_more', ['amount' => \App\Core\Support\Money::format($last['from'])]) }}</span>
                                    @endif
                                @endif
                            </td>
                            <td>
                                {{-- ⭐ সুইচ — চাপলেই বদলায়; বন্ধ করার আগে নিশ্চিত। ⛔ বেতনের নিয়ম বন্ধ হয় না (মালিকের সিদ্ধান্ত) — সুইচটা
                                     কেবল দেখায়, পাশে কারণ। ⓘ বন্ধের লেখা "বন্ধ" — কভারেজ পর্দার একই শব্দ, একই রঙ। --}}
                                <span class="inline-flex flex-wrap items-center gap-2">
                                    @if ($isPayroll && $flow->is_active)
                                        <x-ui.state-toggle :active="true" size="sm" />
                                        <span class="text-2xs text-(--color-ink-muted)">{{ __('approval::message.payroll_locked') }}</span>
                                    @else
                                        <x-ui.state-toggle :active="$flow->is_active" size="sm" method="PATCH"
                                                           :action="route('approval.flow.switch', $flow->id)"
                                                           :label="$flow->is_active ? null : __('approval::message.coverage_off')"
                                                           :confirm="$flow->is_active ? __('approval::message.confirm_flow_off') : null" />
                                    @endif
                                </span>
                            </td>
                            <td class="text-end">
                                {{-- ⓘ সম্পাদনার ঠিকানাই — একই পাতা, নিয়মটা নিচে খোলা; খোঁজা আর পাতার নম্বর সাথে যায় (`?open=` দিলে
                                     টুলবার সেটাকে ছাঁকনির চিপ ভাবত) --}}
                                <a href="{{ route('approval.flow.edit', [$flow->id, ...request()->except('open')]) }}#rule"
                                   class="text-(--color-link) hover:underline">{{ __('approval::action.open_rule') }}</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{ $flows->links() }}
    <x-ui.list-totals :rows="$flows" />

    @if ($opened !== null)
        @include('approval::flow.partials.editor', ['flow' => $opened])
    @endif
</x-layouts.app>
