{{--
    ⭐ ফিল্ড ফোর্সের গাছ — মালিকের নমুনা, ১২ নভেম্বর ২০২৬: উপরে দেয়াল আর চালুর আগের তালিকা, বাঁয়ে গাছ, ডানে বাছা মানুষ
    (পদ, কার নিচে, ডিপো, চলমান এলাকা, কবে থেকে) আর "দেয়াল চালু হলে তিনি যা দেখবেন"।

    ⚠️ Alpine নেই, ইচ্ছাকৃত: সাধারণ ফর্ম আর লিংক — CSP-র ফাঁদে পড়ার মতো কিছু নেই।
--}}
@php
    $card = 'rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4';
    $field = 'h-(--spacing-field-compact) w-full rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2';
    $label = 'mb-1 block text-sm font-semibold';
    $hint = 'mt-1 text-xs text-(--color-ink-muted)';
    $areaName = fn ($l) => $l === null ? '—' : (app()->getLocale() === 'bn' && filled($l->name_bn) ? $l->name_bn : $l->name_en);
    $depotNames = $depots->mapWithKeys(fn ($b) => [(int) $b->id => $b->name()]);
    $list = $checklist;
    $tone = fn (int $n) => $n === 0 ? 'ok' : 'bad';
    $supervisorNow = $row['supervisor'] ?? $under;
    $areaIds = $row !== null ? $row['areas']->pluck('location_id')->map(fn ($id) => (int) $id)->all() : [];
    $depotIds = $row['depots'] ?? [];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('customer::fieldforce.menu') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('customer::fieldforce.title')">
            <x-slot:actions>
                <span class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-(--color-ink-muted)">
                    @foreach (array_reverse(\App\Modules\Customer\Models\StaffPosition::POSITIONS) as $pos)
                        <span class="inline-flex items-center gap-1"><span class="ff-pos" data-pos="{{ $pos }}">{{ $pos }}</span>{{ __('customer::fieldforce.legend_'.strtolower($pos)) }}</span>
                    @endforeach
                </span>
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>

    @if (session('saved'))
        <div role="status" class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    @if ($errors->any())
        <div role="alert" class="mb-4 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm text-(--color-badge-danger-ink)">
            {{ $errors->first() }}
        </div>
    @endif

    {{-- ── দেয়াল আর চালুর আগের তালিকা ───────────────────────────── --}}
    <section data-boxed data-wall class="{{ $card }} mb-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="text-lg font-bold">
                    {{ __('customer::fieldforce.wall') }}
                    <x-ui.badge :tone="$switchOn ? 'success' : 'danger'">{{ $switchOn ? __('customer::fieldforce.wall_on') : __('customer::fieldforce.wall_off') }}</x-ui.badge>
                </h2>
                <p class="text-sm text-(--color-ink-muted)">{{ __('customer::fieldforce.wall_hint') }}</p>
            </div>

            @if ($mayFlip)
                <form method="POST" action="{{ route('customer.fieldforce.wall') }}">
                    @csrf
                    <input type="hidden" name="on" value="{{ $switchOn ? 0 : 1 }}">
                    <x-ui.button type="submit" :tone="$switchOn ? 'secondary' : 'primary'"
                                 :disabled="! $switchOn && ($list['no_depot'] !== [] || $list['blind'] !== [])">
                        {{ $switchOn ? __('customer::fieldforce.wall_turn_off') : __('customer::fieldforce.wall_turn_on') }}
                    </x-ui.button>
                </form>
            @else
                <span class="text-xs text-(--color-ink-muted)">{{ __('customer::fieldforce.wall_owner_only') }}</span>
            @endif
        </div>

        <div class="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-4" data-checklist>
            <div class="ff-check" data-tone="{{ $list['positioned'] > 0 ? 'ok' : 'warn' }}"><strong class="tabular-nums">{{ $list['positioned'] }}</strong> {{ __('customer::fieldforce.positioned') }}</div>
            <div class="ff-check" data-tone="{{ $tone(count($list['no_depot'])) }}" data-no-depot="{{ count($list['no_depot']) }}"><strong class="tabular-nums">{{ count($list['no_depot']) }}</strong> {{ __('customer::fieldforce.no_depot') }}</div>
            <div class="ff-check" data-tone="{{ count($list['no_boss']) === 0 ? 'ok' : 'warn' }}"><strong class="tabular-nums">{{ count($list['no_boss']) }}</strong> {{ __('customer::fieldforce.no_boss') }}</div>
            <div class="ff-check" data-tone="{{ $tone($list['loose_dealers']) }}"><strong class="tabular-nums">{{ $list['loose_dealers'] }}</strong> {{ __('customer::fieldforce.loose') }}</div>
        </div>
        @if ($list['blind'] !== [])
            <p class="mt-2 text-sm text-(--color-badge-danger-ink)" data-blind="{{ count($list['blind']) }}">
                <strong class="tabular-nums">{{ count($list['blind']) }}</strong> {{ __('customer::fieldforce.blind') }}
            </p>
        @endif
    </section>

    <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_24rem]">
        {{-- ── গাছ ───────────────────────────────────────────── --}}
        <section data-boxed data-tree class="{{ $card }} min-w-0 self-start">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-lg font-bold">{{ __('customer::fieldforce.tree') }}</h2>
                <div class="flex flex-wrap gap-2">
                    <a href="{{ route('customer.fieldforce.index', ['under' => 0]) }}" class="rounded-(--radius-field) border border-(--color-border) px-3 py-1.5 text-sm font-semibold hover:border-(--color-brand-500)">{{ __('customer::fieldforce.add_person') }}</a>
                    <span class="rounded-(--radius-field) border border-(--color-border) px-3 py-1.5 text-sm font-semibold">{{ __('customer::fieldforce.loose_people', ['count' => $loose]) }}</span>
                </div>
            </div>

            @if ($roots === [])
                <p class="text-sm text-(--color-ink-muted)">{{ __('customer::fieldforce.tree_empty') }}</p>
            @else
                @include('customer::fieldforce.partials.node', ['ids' => $roots, 'depth' => 0, 'seen' => []])
            @endif

            <p class="mt-3 text-sm text-(--color-ink-muted)">{{ __('customer::fieldforce.tree_hint') }}</p>
        </section>

        {{-- ── বাছা মানুষ ────────────────────────────────────────── --}}
        <aside data-boxed data-person-panel class="{{ $card }} min-w-0 self-start">
            @if ($picked === null)
                <p class="text-sm text-(--color-ink-muted)">{{ __('customer::fieldforce.pick') }}</p>

                @if ($under !== null)
                    {{-- ⓘ "নতুন মানুষ যোগ" / "এঁর নিচে যোগ" — আগে মানুষটা বাছা, তারপর তাঁর ঘর খোলে --}}
                    <form method="GET" action="{{ route('customer.fieldforce.index') }}" class="mt-3 space-y-2" data-add-person>
                        @if ($under > 0)
                            <input type="hidden" name="under" value="{{ $under }}">
                        @endif
                        <label class="{{ $label }}" for="ff-new">{{ __('customer::binding.staff') }}</label>
                        <select id="ff-new" name="person" class="{{ $field }}" required>
                            <option value="">—</option>
                            @foreach ($outside as $u)
                                <option value="{{ $u->id }}">{{ $u->name }}</option>
                            @endforeach
                        </select>
                        <x-ui.button type="submit">{{ __('customer::binding.staff') }} →</x-ui.button>
                    </form>
                @endif
            @else
                <h2 class="text-lg font-bold">{{ $picked->name }}</h2>
                <p class="text-xs text-(--color-ink-muted)">
                    {{ __('customer::fieldforce.role', ['role' => $picked->roles->pluck('name')->implode(', ') ?: '—']) }}
                </p>

                <form method="POST" action="{{ route('customer.fieldforce.save', $picked->id) }}" class="mt-3 space-y-4" data-person-form>
                    @csrf

                    <fieldset>
                        <legend class="{{ $label }}">{{ __('customer::fieldforce.position') }}</legend>
                        <div class="ff-seg">
                            @foreach (\App\Modules\Customer\Models\StaffPosition::POSITIONS as $pos)
                                <label><input type="radio" name="position" value="{{ $pos }}" @checked(($row['position'] ?? null) === $pos)><span>{{ $pos }}</span></label>
                            @endforeach
                            <label><input type="radio" name="position" value="" @checked(($row['position'] ?? null) === null)><span>{{ __('customer::fieldforce.position_none') }}</span></label>
                        </div>
                        <p class="{{ $hint }}">{{ __('customer::fieldforce.position_hint') }}</p>
                    </fieldset>

                    <div>
                        <label class="{{ $label }}" for="ff-under">{{ __('customer::fieldforce.under') }}</label>
                        <select id="ff-under" name="supervisor_id" class="{{ $field }}">
                            <option value="">{{ __('customer::fieldforce.nobody') }}</option>
                            @foreach ($people as $id => $p)
                                @continue($id === $picked->id)
                                <option value="{{ $id }}" @selected($supervisorNow === $id)>{{ $p['position'] ? $p['position'].' · ' : '' }}{{ $p['user']->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <fieldset>
                        <legend class="{{ $label }}">{{ __('customer::fieldforce.depots') }}</legend>
                        <div class="ff-picks space-y-1">
                            @foreach ($depots as $depot)
                                <label class="flex items-center gap-2 text-sm">
                                    <input type="checkbox" name="depots[]" value="{{ $depot->id }}" @checked(in_array((int) $depot->id, $depotIds, true))>
                                    {{ $depot->name() }}
                                </label>
                            @endforeach
                        </div>
                        <p class="{{ $hint }}">{{ __('customer::fieldforce.depots_hint') }}</p>
                    </fieldset>

                    <div>
                        <label class="{{ $label }}" for="ff-areas">{{ __('customer::fieldforce.areas') }}</label>
                        <select id="ff-areas" name="areas[]" multiple size="6" class="w-full rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) p-1 text-sm">
                            @foreach ($areas as $area)
                                <option value="{{ $area->id }}" @selected(in_array((int) $area->id, $areaIds, true))>{{ str_repeat('· ', $area->depth) }}{{ $areaName($area) }}</option>
                            @endforeach
                        </select>
                        <p class="{{ $hint }}">{{ __('customer::fieldforce.areas_hint') }}</p>
                    </div>

                    <div>
                        <span class="{{ $label }}">{{ __('customer::fieldforce.extra') }}</span>
                        <p class="text-sm">
                            {{ ($row['extra'] ?? 0) > 0 ? __('customer::fieldforce.extra_count', ['count' => $row['extra']]) : __('customer::fieldforce.extra_none') }}
                            · <a href="{{ route('customer.binding.index', ['user_id' => $picked->id]) }}" class="text-(--color-brand-600) underline-offset-2 hover:underline">{{ __('customer::fieldforce.extra_add') }}</a>
                        </p>
                    </div>

                    <div>
                        <label class="{{ $label }}" for="ff-from">{{ __('customer::fieldforce.starts_on') }}</label>
                        <x-ui.date id="ff-from" name="starts_on" :value="$today" required />
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <x-ui.button type="submit" tone="primary">{{ __('customer::fieldforce.save') }}</x-ui.button>
                        <a href="{{ route('customer.binding.index') }}#handover" class="rounded-(--radius-field) border border-(--color-border) px-3 py-2 text-sm font-semibold hover:border-(--color-brand-500)">{{ __('customer::fieldforce.handover') }}</a>
                    </div>
                </form>

                {{-- ── দেয়াল চালু হলে তিনি যা দেখবেন ─────────────────────────── --}}
                <div class="mt-4 border-t border-(--color-border) pt-3" data-sees>
                    <h3 class="font-bold">{{ __('customer::fieldforce.sees') }}</h3>
                    @if ($row !== null && $row['walled'])
                        <dl class="mt-2 divide-y divide-(--color-border) text-sm">
                            <div class="flex justify-between gap-3 py-2"><dt>{{ __('customer::fieldforce.sees_own') }}</dt><dd class="tabular-nums font-semibold" data-own>{{ $own }}</dd></div>
                            <div class="flex justify-between gap-3 py-2"><dt>{{ __('customer::fieldforce.sees_below', ['count' => $below]) }}</dt><dd class="tabular-nums font-semibold" data-total>{{ $row['dealers'] }}</dd></div>
                            <div class="flex justify-between gap-3 py-2"><dt>{{ __('customer::fieldforce.sees_depots') }}</dt><dd class="font-semibold">{{ $depotIds === [] ? __('customer::fieldforce.sees_depots_none') : collect($depotIds)->map(fn ($id) => $depotNames[$id] ?? '')->filter()->implode(', ') }}</dd></div>
                            <div class="flex justify-between gap-3 py-2"><dt>{{ __('customer::fieldforce.sees_targets') }}</dt><dd class="font-semibold">{{ __('customer::fieldforce.sees_targets_value', ['count' => $below]) }}</dd></div>
                        </dl>
                        <p class="{{ $hint }}">{{ __('customer::fieldforce.sees_note') }}</p>
                    @else
                        <p class="mt-2 text-sm text-(--color-ink-muted)">{{ __('customer::fieldforce.sees_all') }}</p>
                    @endif
                </div>
            @endif
        </aside>
    </div>
</x-layouts.app>
