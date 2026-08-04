{{--
    ছয়টা মাস্টারের যেকোনো একটার ফর্ম।

    ঘরগুলো সংজ্ঞা থেকে তৈরি হয় (spec.fields), তাই সপ্তম মাস্টার যোগ
    করতে এই ফাইলটা ছুঁতে হয় না। প্রতিটার ধরন ('text', 'number',
    'select', 'switch') ফর্ম ও যাচাই দুইটাই ঠিক করে — দুই জায়গায়
    দুইবার লিখলে একদিন একটা বদলাত আর অন্যটা থাকত।
--}}
@php $isNew = ! $record->exists; @endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __($spec['title']) }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header
            :title="$isNew ? __('master_data::action.new') : __('master_data::action.edit')"
            :subtitle="__($spec['title'])" />
    </x-slot:header>

    <form method="POST"
          action="{{ $isNew
              ? route('master_data.' . $spec['route'] . '.store')
              : route('master_data.' . $spec['route'] . '.update', $record->id) }}"
          x-data="{ busy: false }"
          @submit="busy ? $event.preventDefault() : (busy = true)"
          class="max-w-3xl space-y-4">
        @csrf
        @unless ($isNew) @method('PUT') @endunless

        @if ($errors->any())
            <div role="alert"
                 class="rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm
                        text-(--color-badge-danger-ink)">
                <ul class="list-inside list-disc">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <section class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="mb-3 font-semibold">{{ __('master_data::section.identity') }}</h2>

            <div class="grid gap-3 sm:grid-cols-2">
                <x-ui.field name="code" :label="__('master_data::field.code')"
                            :value="old('code', $record->code)" required />

                <x-ui.field name="name_en" :label="__('master_data::field.name_en')"
                            :value="old('name_en', $record->name_en)" required />

                <x-ui.field name="name_bn" :label="__('master_data::field.name_bn')"
                            :value="old('name_bn', $record->name_bn)" />
            </div>
        </section>

        @if ($spec['fields'] !== [])
            <section class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                <h2 class="mb-3 font-semibold">{{ __('master_data::section.details') }}</h2>

                <div class="grid gap-3 sm:grid-cols-2">
                    @foreach ($spec['fields'] as $name => $field)
                        @if ($field['type'] === 'switch')
                            <label class="flex min-h-(--spacing-touch) items-center gap-2 text-sm sm:col-span-2">
                                <input type="checkbox" name="{{ $name }}" value="1"
                                       @checked(old($name, $record->{$name})) class="size-4">
                                {{ __($field['label']) }}
                            </label>

                        @elseif ($field['type'] === 'select')
                            <label class="block">
                                <span class="mb-1 block text-sm font-medium">{{ __($field['label']) }}</span>
                                <select name="{{ $name }}"
                                        class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                               border-(--color-border) bg-(--color-surface-card) px-3">
                                    <option value="">—</option>

                                    @php
                                        $source = $field['options'];
                                        $list = $options[$source] ?? [];
                                        $selected = old($name, $record->{$name});
                                    @endphp

                                    @if (in_array($source, ['tax_kinds', 'applies', 'contexts'], true))
                                        {{-- ধ্রুবকের তালিকা — অনুবাদ হয়ে আসে, কাঁচা কোড নয় --}}
                                        @php
                                            $prefix = match ($source) {
                                                'tax_kinds' => 'master_data::kind.',
                                                'applies' => 'master_data::applies.',
                                                default => 'master_data::context.',
                                            };
                                        @endphp

                                        @foreach ($list as $value)
                                            <option value="{{ $value }}" @selected($selected === $value)>
                                                {{ __($prefix . $value) }}
                                            </option>
                                        @endforeach
                                    @else
                                        @foreach ($list as $option)
                                            <option value="{{ $option->id }}" @selected($selected == $option->id)>
                                                {{ $option->label() }}
                                            </option>
                                        @endforeach
                                    @endif
                                </select>
                            </label>

                        @else
                            <x-ui.field :name="$name" :label="__($field['label'])"
                                        :type="$field['type'] === 'number' ? 'number' : 'text'"
                                        step="{{ $field['step'] ?? 'any' }}"
                                        :value="old($name, $record->{$name})"
                                        :numeric="$field['type'] === 'number'" />
                        @endif
                    @endforeach
                </div>
            </section>
        @endif

        @if ($record::supportsDefault())
            <section class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                <label class="flex min-h-(--spacing-touch) items-center gap-2 text-sm">
                    <input type="checkbox" name="is_default" value="1"
                           @checked(old('is_default', $record->is_default)) class="size-4">
                    <span>
                        {{ __('master_data::field.is_default') }}
                        <span class="block text-2xs text-(--color-ink-muted)">
                            {{ __('master_data::message.default_hint') }}
                        </span>
                    </span>
                </label>
            </section>
        @endif

        <div class="flex flex-wrap gap-2">
            <x-ui.button type="submit" tone="primary"
                         ::class="busy && 'pointer-events-none opacity-70'">
                {{ __('core.action.save') }}
            </x-ui.button>

            <x-ui.button tone="secondary" :href="route('master_data.' . $spec['route'] . '.index')">
                {{ __('core.action.cancel') }}
            </x-ui.button>
        </div>
    </form>
</x-layouts.app>
