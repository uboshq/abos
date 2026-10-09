{{--
    ডকুমেন্ট প্রশাসন (§২০; দ্বিতীয় ধাপ, ৯ অক্টোবর ২০২৬)।

    ⓘ চারটা বাক্স, ২×২ — ধরন, ফোল্ডার, ট্যাগ, বাড়তি ঘর; প্রতিটায় তালিকা আর নিচে যোগের সারি।
    উপরে একটা সারি: ফাইলের সীমা (নিয়ন্ত্রণ প্যানেল), নম্বর সিরিজ — ⭐ ABOS-এর নিজের পর্দায় লিংক,
    নতুন করে বানানো নয়।

    ⓘ মালিকের ন'টা ফোল্ডার আর এগারোটা ধরন কোডে; এখানে কেবল কোম্পানির নিজের যোগ। ⛔ কোড
    একবার বসলে বদলায় না — চালু/বন্ধ হয়।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('documents::menu.admin') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('documents::menu.admin')" :subtitle="__('documents::message.admin_subtitle')" />
    </x-slot:header>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    @if ($errors->any())
        <div role="alert"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm text-(--color-badge-danger-ink)">
            <ul class="list-inside list-disc">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- ── ফাইলের সীমা আর বাকি ব্যবস্থা ── --}}
    <section data-boxed class="mb-4 flex flex-wrap items-center gap-x-6 gap-y-2 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) px-4 py-3 text-sm">
        <span>
            <span class="text-(--color-ink-muted)">{{ __('documents::field.max_size') }}:</span>
            <strong class="num">{{ $storage['max'] }}</strong>
        </span>
        <span class="min-w-0 flex-1 truncate" title="{{ implode(', ', $storage['kinds']) }}">
            <span class="text-(--color-ink-muted)">{{ __('documents::field.allowed_kinds') }}:</span>
            {{ implode(', ', $storage['kinds']) }}
        </span>
        {{-- ⭐ ABOS-এর নিজের পর্দা — ফাইলের সীমা নিয়ন্ত্রণ প্যানেলে, DOC-0001 নম্বর সিরিজের পর্দায় --}}
        <a href="{{ route('system_admin.control-panel', ['tab' => 'documents']) }}" class="text-(--color-link) hover:underline">
            {{ __('documents::action.open_settings') }}
        </a>
        <a href="{{ route('master_data.series.index') }}" class="text-(--color-link) hover:underline">
            {{ __('documents::action.open_number_series') }}
        </a>
    </section>

    <div class="grid gap-4 xl:grid-cols-2 xl:items-start">
        @foreach (['types' => $types, 'categories' => $categories] as $kind => $rows)
            <section id="{{ $kind }}" data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
                <h2 class="border-b border-(--color-border) bg-(--color-section-head) px-4 py-3 font-semibold">
                    {{ __('documents::section.admin_'.$kind) }}
                </h2>
                <p class="px-4 pt-2 text-2xs text-(--color-ink-muted)">{{ __('documents::message.admin_builtin_'.$kind) }}</p>

                <table class="ui-list w-full border-collapse text-sm">
                    <thead>
                        <tr>
                            <th class="text-start">{{ __('documents::field.code') }}</th>
                            <th class="text-start">{{ __('documents::field.name_bn') }}</th>
                            <th class="text-start">{{ __('documents::field.name_en') }}</th>
                            <th class="text-end">{{ __('core.table.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $row)
                            <tr data-admin-row="{{ $row->code }}" @class(['opacity-60' => ! $row->is_active])>
                                <td class="num">{{ $row->code }}</td>
                                <td>{{ $row->name_bn }}</td>
                                <td>{{ $row->name_en }}</td>
                                <td class="text-end">
                                    <form method="POST" action="{{ route('documents.admin.toggle', [$kind, $row->id]) }}">
                                        @csrf
                                        <button type="submit" class="text-(--color-link) hover:underline">
                                            {{ $row->is_active ? __('documents::action.turn_off') : __('documents::action.turn_on') }}
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-(--color-ink-muted)">{{ __('documents::message.admin_none') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>

                <form method="POST" action="{{ route('documents.admin.store', $kind) }}"
                      class="grid gap-2 border-t border-(--color-border) px-4 py-3 sm:grid-cols-[8rem_minmax(0,1fr)_minmax(0,1fr)_auto] sm:items-end">
                    @csrf
                    <x-ui.field name="code" :label="__('documents::field.code')" maxlength="24" required />
                    <x-ui.field name="name_bn" :label="__('documents::field.name_bn')" maxlength="120" required />
                    <x-ui.field name="name_en" :label="__('documents::field.name_en')" maxlength="120" required />
                    <x-ui.button type="submit" tone="primary" icon="plus">{{ __('documents::action.add') }}</x-ui.button>
                </form>
            </section>
        @endforeach

        {{-- ── ট্যাগ ── --}}
        <section id="tags" data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
            <h2 class="border-b border-(--color-border) bg-(--color-section-head) px-4 py-3 font-semibold">
                {{ __('documents::section.admin_tags') }}
            </h2>

            <div class="flex flex-wrap gap-2 px-4 py-3">
                @forelse ($tags as $tag)
                    <form method="POST" action="{{ route('documents.admin.toggle', ['tags', $tag->id]) }}" class="inline-flex"
                          data-confirm="{{ __('documents::message.tag_remove_confirm') }}">
                        @csrf
                        <button type="submit" data-tag="{{ $tag->name }}"
                                class="inline-flex items-center gap-1 rounded-(--radius-badge) bg-(--color-badge-info-bg) px-2 py-0.5 text-xs text-(--color-badge-info-ink)">
                            {{ $tag->name }} <span aria-hidden="true">×</span>
                            <span class="sr-only">{{ __('documents::action.remove') }}</span>
                        </button>
                    </form>
                @empty
                    <p class="text-sm text-(--color-ink-muted)">{{ __('documents::message.admin_none') }}</p>
                @endforelse
            </div>

            <form method="POST" action="{{ route('documents.admin.store', 'tags') }}"
                  class="grid gap-2 border-t border-(--color-border) px-4 py-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">
                @csrf
                <x-ui.field name="name" :label="__('documents::field.tag')" maxlength="40" required />
                <x-ui.button type="submit" tone="primary" icon="plus">{{ __('documents::action.add') }}</x-ui.button>
            </form>
        </section>

        {{-- ── বাড়তি ঘর ── --}}
        <section id="fields" data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
            <h2 class="border-b border-(--color-border) bg-(--color-section-head) px-4 py-3 font-semibold">
                {{ __('documents::section.admin_fields') }}
            </h2>

            <table class="ui-list w-full border-collapse text-sm">
                <thead>
                    <tr>
                        <th class="text-start">{{ __('documents::field.code') }}</th>
                        <th class="text-start">{{ __('documents::field.name_bn') }}</th>
                        <th class="text-start">{{ __('documents::field.field_kind') }}</th>
                        <th class="text-start">{{ __('documents::field.doc_type') }}</th>
                        <th class="text-end">{{ __('core.table.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($fields as $field)
                        <tr data-admin-row="{{ $field->code }}" @class(['opacity-60' => ! $field->is_active])>
                            <td class="num">{{ $field->code }}</td>
                            <td>{{ $field->name_bn }}@if ($field->is_required) <span class="text-(--color-danger)">*</span>@endif</td>
                            <td>{{ __('documents::catalog.field_kind.'.$field->kind) }}</td>
                            <td>{{ filled($field->doc_type) ? ($allTypes[$field->doc_type] ?? $field->doc_type) : __('documents::message.every_type') }}</td>
                            <td class="text-end">
                                <form method="POST" action="{{ route('documents.admin.toggle', ['fields', $field->id]) }}">
                                    @csrf
                                    <button type="submit" class="text-(--color-link) hover:underline">
                                        {{ $field->is_active ? __('documents::action.turn_off') : __('documents::action.turn_on') }}
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-(--color-ink-muted)">{{ __('documents::message.admin_none') }}</td></tr>
                    @endforelse
                </tbody>
            </table>

            <form method="POST" action="{{ route('documents.admin.store', 'fields') }}"
                  class="grid gap-2 border-t border-(--color-border) px-4 py-3 sm:grid-cols-3 sm:items-end">
                @csrf
                <x-ui.field name="code" :label="__('documents::field.code')" maxlength="24" required />
                <x-ui.field name="name_bn" :label="__('documents::field.name_bn')" maxlength="120" required />
                <x-ui.field name="name_en" :label="__('documents::field.name_en')" maxlength="120" required />
                <x-ui.select name="kind" :label="__('documents::field.field_kind')" required
                             :options="collect(\App\Modules\Documents\Models\MetadataField::KINDS)
                                 ->mapWithKeys(fn ($k) => [$k => __('documents::catalog.field_kind.'.$k)])->all()"
                             selected="text" />
                <x-ui.select name="doc_type" :label="__('documents::field.doc_type')" :options="$allTypes"
                             :placeholder="__('documents::message.every_type')" />
                <div class="flex items-end justify-between gap-2">
                    <label class="flex min-h-(--spacing-touch) items-center gap-2 text-sm">
                        <input type="hidden" name="is_required" value="0">
                        <input type="checkbox" name="is_required" value="1" class="size-4">
                        {{ __('documents::field.is_required') }}
                    </label>
                    <x-ui.button type="submit" tone="primary" icon="plus">{{ __('documents::action.add') }}</x-ui.button>
                </div>
            </form>
        </section>
    </div>
</x-layouts.app>
