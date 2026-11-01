{{--
    ⭐ দুই রোল পাশাপাশি — কন্ট্রোল প্যানেলের পরিকল্পনা, দফা ১০ ([[RoleController::compare()]])।
    ⓘ কেবল যেখানে দুইটা আলাদা, মডিউল ধরে; মিলগুলো এক লাইনের সংখ্যায়। দেখা কেবল — বদল রোলের নিজের পাতায়।
--}}
@php
    $nameOf = fn ($r) => \App\Core\Support\RoleLabel::for($r->name);
@endphp
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('system_admin::permission.compare_roles') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('system_admin::permission.compare_roles')" :subtitle="__('system_admin::permission.compare_note')" />
    </x-slot:header>

    <form method="GET" action="{{ route('system_admin.role.compare') }}" data-role-compare-form
          class="mb-4 flex flex-wrap items-end gap-3 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
        @foreach (['a' => $a, 'b' => $b] as $key => $chosen)
            <label class="grid min-w-0 flex-1 gap-1 text-sm sm:max-w-xs">
                <span class="text-xs font-semibold text-(--color-ink-muted)">{{ $key === 'a' ? '1' : '2' }}</span>
                <select name="{{ $key }}" class="min-h-(--spacing-touch) rounded-(--radius-field) border-(--color-border) text-sm">
                    <option value="">{{ __('system_admin::permission.compare_pick') }}</option>
                    @foreach ($roles as $r)
                        <option value="{{ $r->id }}" @selected($chosen?->id === $r->id)>{{ $nameOf($r) }}</option>
                    @endforeach
                </select>
            </label>
        @endforeach
        <x-ui.button type="submit" tone="primary">{{ __('system_admin::permission.compare_show') }}</x-ui.button>
    </form>

    @if ($a && $b)
        @if ($rows === [])
            <p data-compare-same-all class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4 text-sm">
                {{ __('system_admin::permission.compare_no_difference') }}
            </p>
        @else
            <section data-boxed data-compare-table class="min-w-0 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
                <div class="table-responsive">
                    <table class="ui-list w-full">
                        <thead>
                            <tr>
                                <th class="text-start"></th>
                                <th class="text-center">{{ $nameOf($a) }}</th>
                                <th class="text-center">{{ $nameOf($b) }}</th>
                            </tr>
                        </thead>
                        @foreach ($rows as $moduleLabel => $list)
                            <tbody>
                                <tr class="bg-(--color-surface-muted)">
                                    <th colspan="3" scope="colgroup" class="text-start text-xs font-semibold">{{ $moduleLabel }}</th>
                                </tr>
                                @foreach ($list as $row)
                                    <tr data-compare-row="{{ $row['name'] }}">
                                        <td>{{ $row['label'] }}</td>
                                        @foreach (['a', 'b'] as $side)
                                            <td class="text-center" data-compare-{{ $side }}="{{ $row[$side] ? 'yes' : 'no' }}">
                                                {{-- ⭐ দফা ১৪: চিহ্নটা চোখের জন্য, পর্দা-পাঠক পড়ে কথাটা ("আছে" / "নেই") --}}
                                                @if ($row[$side])
                                                    <span class="font-semibold text-(--color-badge-success-ink)" aria-hidden="true">✓</span>
                                                    <span class="sr-only">{{ __('system_admin::permission.compare_has') }}</span>
                                                @else
                                                    <span class="text-(--color-ink-disabled)" aria-hidden="true">—</span>
                                                    <span class="sr-only">{{ __('system_admin::permission.compare_hasnt') }}</span>
                                                @endif
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        @endforeach
                    </table>
                </div>
            </section>
            @if ($same > 0)
                <p data-compare-same class="mt-3 text-sm text-(--color-ink-muted)">{{ __('system_admin::permission.compare_same', ['count' => $same]) }}</p>
            @endif
        @endif
    @endif
</x-layouts.app>
