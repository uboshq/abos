{{--
    ⭐ অনুমতির ছক — মডিউল এক সারিতে একটা, ভাঁজ করা (মালিকের অনুমোদিত নমুনা, ২০ নভেম্বর ২০২৬ "ok", দফা ৫)।

    ⓘ শুরুতে সব বন্ধ; একটায় চাপলে খোলে, আগেরটা নিজে বন্ধ হয় (`name` — ব্রাউজারের নিজের এক-খোলা ভাঁজ, আর [[role-screen.js]]
      পুরনো ব্রাউজারের জন্য)। ⛔ তীর-চিহ্ন নেই — মালিক: "আন্তর্জাতিক নিয়মে এখানে নেই"।
    ⓘ মাথায়: রঙিন দাগ (মডিউলের রং), নাম, সরু অগ্রগতির দাগ, "দেওয়া / মোট", আর "সব" (আংশিক হলে মাঝামাঝি)।
    ⓘ ভেতরে: জিনিস × দেখা · তৈরি · সম্পাদনা · মোছা/বাতিল · অনুমোদন · পোস্ট · রপ্তানি · ছাপা; যে ঘরে কাজটাই নেই সেখানে "–"।
      আট কলামে যা পড়ে না, তা শেষের "অন্য কাজ" কলামে নামসহ — ⛔ কোনো অনুমতি বাদ পড়ে না ([[TheRolePageShowsEveryPermissionTest]])।
    ⓘ `$readonly` — "কে আসলে কী পারে": একই ছক, কেবল দেখা; ঘরে কোনো `permissions[]` নেই, তাই কিছু জমা হয় না।

    $grid · $chosen · $columns · $readonly · $prefix (`perm` / `eff`)
--}}
@php
    $chosenSet = array_flip($chosen);
@endphp
<div class="role-mods" data-role-modules="{{ $prefix }}">
    @foreach ($grid as $module => $block)
        @php
            $on = count(array_filter($block['all'], fn ($n) => isset($chosenSet[$n])));
            $all = count($block['all']);
            $hasSpecial = collect($block['rows'])->contains(fn ($r) => $r['special'] !== []);
            $span = count($columns) + 1 + ($hasSpecial ? 1 : 0);
            $used = [];
            foreach ($block['rows'] as $r) {
                foreach (array_keys($r['cells']) as $c) {
                    $used[$c] = true;
                }
            }
        @endphp
        <details name="role-modules-{{ $prefix }}" class="role-mod" data-accordion="{{ $prefix }}"
                 @if ($readonly) data-effective-module="{{ $module }}" id="eff-{{ $module }}"
                 @else data-permission-module="{{ $module }}" id="perm-{{ $module }}" @endif
                 style="--edge: var(--color-module-{{ $module }}, var(--color-brand-500))">
            <summary class="role-mod-head">
                <b class="role-mod-name">{{ $block['label'] }}</b>
                <span class="role-mod-bar" aria-hidden="true"><i data-permission-bar style="width: {{ $all > 0 ? round($on * 100 / $all) : 0 }}%"></i></span>
                <span class="role-mod-count num" data-permission-count data-permission-total="{{ $all }}"
                      data-permission-label="{{ __('system_admin::permission.granted', ['on' => ':on', 'all' => ':all']) }}">{{ __('system_admin::permission.granted', ['on' => $on, 'all' => $all]) }}</span>
                @unless ($readonly)
                    <label class="role-mod-all" title="{{ __('system_admin::permission.select_all_module') }}">
                        <input type="checkbox" data-permission-all class="role-ck" @checked($on === $all && $all > 0)
                               @if ($on > 0 && $on < $all) data-indeterminate @endif>
                        {{ __('system_admin::permission.select_all') }}
                    </label>
                @endunless
            </summary>

            <div class="role-table-wrap">
                <table class="role-table">
                    <thead>
                        <tr>
                            <th scope="col" class="role-subject-col">{{ __('system_admin::permission.column_subject') }}</th>
                            @foreach ($columns as $column => $label)
                                <th scope="col">{{ $label }}</th>
                            @endforeach
                            @if ($hasSpecial)
                                <th scope="col" class="role-special-col">{{ __('system_admin::permission.column_special') }}</th>
                            @endif
                        </tr>
                        @unless ($readonly)
                            {{-- ⓘ "পুরো কলাম" — এই মডিউলের ঐ কলামের সব ঘর; আংশিক হলে মাঝামাঝি। manage-এর জোড়া ঘর এতে পড়ে না (actions.js) --}}
                            <tr class="role-colall">
                                <th scope="row">{{ __('system_admin::permission.whole_column') }}</th>
                                @foreach ($columns as $column => $label)
                                    <th>
                                        @isset($used[$column])
                                            <input type="checkbox" data-permission-column="{{ $column }}" class="role-ck"
                                                   aria-label="{{ $label }} — {{ __('system_admin::permission.select_all') }}">
                                        @endisset
                                    </th>
                                @endforeach
                                @if ($hasSpecial)
                                    <th></th>
                                @endif
                            </tr>
                        @endunless
                    </thead>
                    @foreach ($block['sections'] as $section => $part)
                        <tbody data-permission-section="{{ $section }}">
                            <tr data-permission-section-head class="role-grp">
                                <th scope="colgroup" colspan="{{ $span }}">
                                    @if ($readonly)
                                        {{ $part['label'] }}
                                    @else
                                        <label>
                                            <input type="checkbox" data-permission-section-all class="role-ck"
                                                   @checked(count(array_filter($part['all'], fn ($n) => isset($chosenSet[$n]))) === count($part['all']) && $part['all'] !== [])
                                                   aria-label="{{ $part['label'] }} — {{ __('system_admin::permission.select_all') }}">
                                            {{ $part['label'] }}
                                        </label>
                                    @endif
                                </th>
                            </tr>

                            @foreach ($part['rows'] as $row)
                                @php
                                    $haystack = mb_strtolower(implode(' ', array_filter([
                                        $block['label'], $row['label'], $row['key'],
                                        ...array_values($row['cells']), $row['manage'],
                                        ...array_keys($row['special']), ...array_values($row['special']),
                                    ])));
                                @endphp
                                <tr data-permission-row data-permission-text="{{ $haystack }}">
                                    <th scope="row">{{ $row['label'] }}</th>

                                    @foreach ($columns as $column => $label)
                                        @if ($column === 'create' && $row['manage'])
                                            <td colspan="3" class="role-span">
                                                @include('system_admin::role.partials.switch', [
                                                    'name' => $row['manage'],
                                                    'label' => $row['label'].' — '.__('system_admin::permission.verbs.manage'),
                                                    'checked' => isset($chosenSet[$row['manage']]),
                                                    'caption' => __('system_admin::permission.manage_spans'),
                                                    'cell' => 'manage',
                                                    'readonly' => $readonly,
                                                ])
                                            </td>
                                        @elseif ($row['manage'] && in_array($column, ['update', 'delete'], true))
                                            {{-- তিন কলাম জুড়ে বসেছে — উপরের ঘরেই --}}
                                        @elseif (isset($row['cells'][$column]))
                                            <td>
                                                @include('system_admin::role.partials.switch', [
                                                    'name' => $row['cells'][$column],
                                                    'label' => $row['label'].' — '.$label,
                                                    'checked' => isset($chosenSet[$row['cells'][$column]]),
                                                    'cell' => $column,
                                                    'readonly' => $readonly,
                                                ])
                                            </td>
                                        @else
                                            {{-- ⭐ "–" — এই পর্দায় এই কাজটাই নেই (নমুনার দাগ-কাটা ঘর); ⓘ কথাটা পর্দা-পাঠকের জন্যও লেখা --}}
                                            <td class="role-absent" data-permission-absent title="{{ __('system_admin::permission.state_absent') }}">
                                                <span class="role-dash" aria-hidden="true">–</span>
                                                <span class="sr-only">{{ __('system_admin::permission.state_absent') }}</span>
                                            </td>
                                        @endif
                                    @endforeach

                                    @if ($hasSpecial)
                                        <td class="role-special">
                                            @foreach ($row['special'] as $name => $verb)
                                                <label class="role-chip" title="{{ $name }}">
                                                    @if ($readonly)
                                                        <span @class(['role-mark', 'is-on' => isset($chosenSet[$name])]) aria-hidden="true">{{ isset($chosenSet[$name]) ? '✓' : '' }}</span>
                                                    @else
                                                        <input type="checkbox" name="permissions[]" value="{{ $name }}" class="role-ck"
                                                               data-cell-label="{{ $row['label'].' — '.$verb }}" @checked(isset($chosenSet[$name]))>
                                                    @endif
                                                    {{ $verb }}
                                                </label>
                                            @endforeach
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    @endforeach
                </table>
            </div>
        </details>
    @endforeach
</div>
