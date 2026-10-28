{{--
    দায়িত্ব হস্তান্তর — কে কার হয়ে, কোন সময় পর্যন্ত।

    ── ⭐ মালিকের নকশা, ২৪ সেপ্টেম্বর ২০২৬ ─────────────────────────────
    ছুটিতে যাওয়ার আগে সই দেওয়ার ভার অন্যকে দিয়ে যাওয়া যাবে, আর মেয়াদ
    শেষে সেটা **নিজে থেকেই** বন্ধ হবে।

    ── ⭐ অনুমোদন ভাগ ৩, দফা ১৮ (পরিকল্পনার পাতা ৫), ২৮ অক্টোবর ২০২৬ ──
    দুই কলাম: "আমি দিয়েছি" আর "আমাকে দেওয়া"। প্রতিটা কার্ডে লেখা চালু / আসছে / শেষ; কবে থেকে কবে, কোন কাজ, কেন;
    কার হয়ে কয়টা অনুমোদন হয়েছে (চালুতে "দেখুন", শেষে একই লাইনে); চালুটা "থামান" (জিজ্ঞাসা করে), আসছেটা "বদলান"
    (বাতিল বদলানোর ফর্মে)। মালিক, ২৯ অক্টোবর: "plan onuzayi 100%"। ⓘ ফর্মটা মাথার
    "+ দায়িত্ব দিন" চাপলে খোলে (`?new=1`) — জাভাস্ক্রিপ্ট ছাড়া, ভুল হলে খোলাই থাকে।

    ── ⚠️ কেন দুইটা তালিকা ─────────────────────────────────────────────
    ⓘ *"আমি কাকে দিয়েছি"* — ফিরে এসে বন্ধ করার জন্য, আর কে এখন আমার
    হয়ে সই দিচ্ছেন তা জানার জন্য।
    ⓘ *"আমার কাছে কার ভার"* — কারণ ইনবক্সে হঠাৎ অন্যের কাগজ দেখলে
    মানুষ ভাবেন কিছু একটা ভুল হয়েছে, আর সই দিতে ভয় পান।
--}}
@php
    $tones = [\App\Models\ApprovalDelegation::LIVE => 'success', \App\Models\ApprovalDelegation::COMING => 'pending', \App\Models\ApprovalDelegation::ENDED => 'draft'];
    $formOpen = $change !== null || request()->boolean('new') || $errors->any();
    $workOf = fn (\App\Models\ApprovalDelegation $row) => ($row->modules ?? []) === []
        ? __('approval::field.delegate_all_short')
        : implode(' · ', array_map(fn ($m) => $modules[$m] ?? $m, $row->modules));
    $span = fn (\App\Models\ApprovalDelegation $row) => \App\Core\Support\DateFormat::spokenDay($row->starts_on)
        .' – '.\App\Core\Support\DateFormat::spokenDay($row->ends_on);
    $labels = app(\App\Core\Engines\Approval\ApprovalLabel::class);
@endphp
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('approval::menu.delegation') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('approval::menu.delegation')" :subtitle="__('approval::message.delegation_subtitle')">
            <x-slot:actions>
                <x-ui.button tone="primary" :href="route('approval.delegation.index', ['new' => 1]).'#give'" data-delegation-new>
                    + {{ __('approval::action.delegate') }}
                </x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    @if ($errors->any())
        <div role="alert"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm
                    text-(--color-badge-danger-ink)">
            {{ $errors->first() }}
        </div>
    @endif

    {{-- ── দেওয়ার / বদলানোর ফর্ম — "+ দায়িত্ব দিন" বা "বদলান" চাপলে ── --}}
    @if ($formOpen)
        <section id="give" data-boxed data-delegation-form
                 class="mb-4 scroll-mt-24 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="mb-3 font-semibold">
                {{ $change !== null
                    ? __('approval::message.delegation_changing', ['name' => $change->to?->name ?? '—'])
                    : __('approval::action.delegate') }}
            </h2>

            <form method="POST" class="space-y-3"
                  action="{{ $change !== null ? route('approval.delegation.update', $change) : route('approval.delegation.store') }}">
                @csrf
                @if ($change !== null)
                    @method('PUT')
                @endif

                <div class="grid gap-3 md:grid-cols-4">
                    <x-ui.select name="to_user_id" :label="__('approval::field.delegate_to')" required
                                 :options="$people->pluck('name', 'id')"
                                 :selected="old('to_user_id', $change?->to_user_id)" />

                    {{--
                        ⛔ শুরুর তারিখ পিছনে যেতে পারে না।

                        ⚠️ পিছনের তারিখে ভার দিলে **ইতিমধ্যে হয়ে যাওয়া** সিদ্ধান্ত
                        বৈধ দেখাত — কেউ অনুমতি ছাড়া সই দিয়ে পরে ভারটা পিছিয়ে
                        বসিয়ে দিতে পারতেন। ⓘ বাধাটা কন্ট্রোলারেও আছে; এটা
                        কেবল সৌজন্য।
                    --}}
                    <x-ui.field name="starts_on" type="date" required
                                :label="__('approval::field.starts_on')"
                                :min="now()->format('Y-m-d')"
                                :value="old('starts_on', $change?->starts_on?->format('Y-m-d') ?? now()->format('Y-m-d'))" />

                    <x-ui.field name="ends_on" type="date" required
                                :label="__('approval::field.ends_on')"
                                :min="now()->format('Y-m-d')"
                                :value="old('ends_on', $change?->ends_on?->format('Y-m-d') ?? now()->addDays(7)->format('Y-m-d'))" />

                    <x-ui.field name="reason" :label="__('approval::field.delegate_reason')"
                                :value="old('reason', $change?->reason)" />
                </div>

                <fieldset>
                    <legend class="mb-1 block text-sm font-medium">
                        {{ __('approval::field.delegate_modules') }}
                    </legend>

                    {{--
                        ⓘ একটাও না বাছলে **সব** — সেটাই সবচেয়ে সাধারণ ব্যবহার
                        ("আমার সব অনুমোদন")। ⚠️ খালিকে "কিছুই না" ধরলে
                        জিনিসটা কাজই করত না।
                    --}}
                    <p class="mb-2 text-2xs text-(--color-ink-muted)">
                        {{ __('approval::field.delegate_all') }}
                    </p>

                    <div class="grid gap-x-3 sm:grid-cols-2 lg:grid-cols-4">
                        @foreach ($modules as $code => $label)
                            <label class="flex min-h-(--spacing-touch) items-center gap-2 text-sm">
                                <input type="checkbox" name="modules[]" value="{{ $code }}"
                                       @checked(in_array($code, old('modules', $change?->modules ?? []), true))
                                       class="size-4">
                                <span>{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>

                <div class="flex flex-wrap items-center gap-2">
                    <x-ui.button type="submit" tone="primary">
                        {{ $change !== null ? __('core.action.save') : __('approval::action.delegate') }}
                    </x-ui.button>
                    <x-ui.button :href="route('approval.delegation.index')">{{ __('core.action.cancel') }}</x-ui.button>

                    {{-- ⓘ আসছে দায়িত্ব বাতিল — কার্ডে নকশার বাইরে বোতাম না বসিয়ে এখানে; নিচের আলাদা ফর্মে যায়, একই জিজ্ঞাসা --}}
                    @if ($change !== null)
                        <button type="submit" form="delegation-cancel" data-delegation-cancel
                                class="ms-auto min-h-(--spacing-touch) rounded-(--radius-field) border border-(--color-danger) px-4 text-sm
                                       font-medium text-(--color-badge-danger-ink) hover:bg-(--color-badge-danger-bg)">
                            {{ __('approval::action.delegation_cancel') }}
                        </button>
                    @endif
                </div>
            </form>

            @if ($change !== null)
                <form id="delegation-cancel" method="POST" action="{{ route('approval.delegation.destroy', $change) }}"
                      data-confirm="{{ __('approval::message.confirm_delegation_stop') }}">
                    @csrf
                    @method('DELETE')
                </form>
            @endif
        </section>
    @endif

    <div class="grid gap-4 lg:grid-cols-2 lg:items-start">
        {{-- ── আমি দিয়েছি ─────────────────────────────────────── --}}
        <section class="min-w-0" data-delegation-given>
            <h2 class="mb-2 font-semibold">{{ __('approval::field.delegation_given') }}</h2>

            <div class="space-y-2">
                @forelse ($given as $row)
                    @php($state = $row->stateOn())
                    @php($acted = $actedFor[$row->id] ?? [])

                    <article data-delegation="{{ $row->id }}" data-state="{{ $state }}"
                             class="flex items-start justify-between gap-3 rounded-(--radius-card) border border-(--color-border)
                                    bg-(--color-surface-card) p-3 text-sm">
                        <div class="min-w-0">
                            <p class="flex flex-wrap items-center gap-2">
                                <span aria-hidden="true">→</span>
                                <strong>{{ $row->to?->name ?? '—' }}</strong>
                                {{-- ⚠️ অবস্থাটা লেখা থাকে, কারণ তারিখ দেখে মাথায় হিসাব করা ভুল হয় — বিশেষ করে যেদিন মেয়াদ শেষ। --}}
                                <x-ui.badge :tone="$tones[$state]">{{ __('approval::status.delegation_'.$state) }}</x-ui.badge>
                            </p>

                            <p class="mt-1 text-2xs text-(--color-ink-muted)">
                                <span class="num">{{ $span($row) }}</span>
                                · {{ __('approval::message.delegation_what', ['what' => $workOf($row)]) }}
                                @if (filled($row->reason))
                                    · {{ __('approval::message.delegation_why', ['why' => $row->reason]) }}
                                @endif
                                @if ($row->revoked_at !== null)
                                    · {{ __('approval::message.delegation_stopped_at', ['date' => \App\Core\Support\DateFormat::spoken($row->revoked_at)]) }}
                                @endif
                                {{-- ⓘ নকশায় শেষ কার্ডে গোনাটা একই লাইনে: "২–৪ অক্টোবর · কাজ: বিক্রয় · অনুমোদন: ২টা" --}}
                                @if ($state === \App\Models\ApprovalDelegation::ENDED)
                                    · <span data-acted="{{ count($acted) }}">{{ __('approval::message.delegation_acted_short', ['count' => count($acted)]) }}</span>
                                @endif
                            </p>

                            {{-- ⭐ চালুর নিচে "এ পর্যন্ত আমার হয়ে অনুমোদন: ৪টা দেখুন" — খুললে কাগজগুলো, প্রতিটা নিজের পাতায় যায়। ⓘ আসছেতে কিছুই হয়নি। --}}
                            @if ($state === \App\Models\ApprovalDelegation::LIVE)
                                @if ($acted === [])
                                    <p class="mt-1 text-2xs" data-acted="0">{{ __('approval::message.delegation_acted', ['count' => 0]) }}</p>
                                @else
                                    <details class="mt-1 text-2xs" data-acted="{{ count($acted) }}">
                                        <summary class="cursor-pointer list-none [&::-webkit-details-marker]:hidden">
                                            {{ __('approval::message.delegation_acted', ['count' => count($acted)]) }}
                                            <span class="text-(--color-link) underline">{{ __('approval::action.delegation_see') }}</span>
                                        </summary>
                                        <ul class="mt-1 space-y-1">
                                            @foreach ($acted as $one)
                                                <li>
                                                    <a href="{{ route('approval.inbox.show', $one['approval']->id) }}" class="text-(--color-link) underline">
                                                        <span class="num">{{ $facts[$one['approval']->id]['no'] ?? '' }}</span>
                                                        {{ $labels->of((string) $one['approval']->module, (string) $one['approval']->action) }}
                                                    </a>
                                                    @if ($one['approval']->amount !== null)
                                                        · <span class="num">{{ \App\Core\Support\Money::format($one['approval']->amount) }}</span>
                                                    @endif
                                                    · {{ __('approval::status.'.$one['decision']) }}
                                                    · <span class="num">{{ \App\Core\Support\DateFormat::spoken($one['at']) }}</span>
                                                </li>
                                            @endforeach
                                        </ul>
                                    </details>
                                @endif
                            @endif
                        </div>

                        @if ($state === \App\Models\ApprovalDelegation::LIVE)
                            <form method="POST" action="{{ route('approval.delegation.destroy', $row) }}"
                                  data-confirm="{{ __('approval::message.confirm_delegation_stop') }}">
                                @csrf
                                @method('DELETE')

                                {{-- ⓘ নকশার মতো লাল রেখার বোতাম — ভরাট লাল নয়, কারণ থামানো ফেরানো যায় (নতুন দিলেই হয়) --}}
                                <button type="submit"
                                        class="min-h-(--spacing-touch) rounded-(--radius-field) border border-(--color-danger) px-4 text-sm
                                               font-medium text-(--color-badge-danger-ink) hover:bg-(--color-badge-danger-bg)">
                                    {{ __('approval::action.delegation_stop') }}
                                </button>
                            </form>
                        @elseif ($state === \App\Models\ApprovalDelegation::COMING)
                            {{-- ⓘ নকশার মতো নীল রেখার "বদলান" একাই; আসছেটা বাতিল হয় বদলানোর ফর্ম থেকে ("এই দায়িত্ব বাতিল করুন")। --}}
                            <a href="{{ route('approval.delegation.index', ['change' => $row->id]) }}#give" data-delegation-change
                               class="inline-flex min-h-(--spacing-touch) shrink-0 items-center rounded-(--radius-field) border border-(--color-link) px-4
                                      text-sm font-medium text-(--color-link) hover:bg-(--color-surface-hover)">
                                {{ __('approval::action.delegation_change') }}
                            </a>
                        @endif
                    </article>
                @empty
                    <p class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-3 text-sm text-(--color-ink-muted)">
                        {{ __('approval::message.delegation_none') }}
                    </p>
                @endforelse
            </div>

            <x-ui.pager :rows="$given" />
            <x-ui.list-totals :rows="$given" />
        </section>

        {{-- ── আমাকে দেওয়া ───────────────────────────────────── --}}
        <section class="min-w-0" data-delegation-held>
            <h2 class="mb-2 font-semibold">{{ __('approval::field.delegation_held') }}</h2>

            <div class="space-y-2">
                @forelse ($held as $row)
                    @php($state = $row->stateOn())

                    <article data-held="{{ $row->id }}" data-state="{{ $state }}"
                             class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-3 text-sm">
                        <p class="flex flex-wrap items-center gap-2">
                            <span aria-hidden="true">←</span>
                            <strong>{{ $row->from?->name ?? '—' }}</strong>
                            <x-ui.badge :tone="$tones[$state]">{{ __('approval::status.delegation_'.$state) }}</x-ui.badge>
                        </p>
                        <p class="mt-1 text-2xs text-(--color-ink-muted)">
                            <span class="num">{{ $span($row) }}</span>
                            · {{ __('approval::message.delegation_what', ['what' => $workOf($row)]) }}
                            @if (filled($row->reason))
                                · {{ __('approval::message.delegation_why', ['why' => $row->reason]) }}
                            @endif
                        </p>
                        <p class="mt-1 text-2xs">{{ __('approval::message.delegation_held_where') }}</p>
                    </article>
                @empty
                    <p class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-3 text-sm text-(--color-ink-muted)">
                        {{ __('approval::message.delegation_held_none') }}
                    </p>
                @endforelse

                <p class="rounded-(--radius-field) border-s-4 border-(--color-badge-info-ink) bg-(--color-badge-info-bg) px-3 py-2 text-2xs
                          text-(--color-badge-info-ink)">
                    {{ __('approval::message.delegation_audit') }}
                </p>
            </div>
        </section>
    </div>
</x-layouts.app>
