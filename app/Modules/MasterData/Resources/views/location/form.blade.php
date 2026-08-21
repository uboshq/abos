{{--
    এলাকা তৈরি ও সম্পাদনা।

    স্তরটা সম্পাদনায় বদলানো যায় না, আর ঘরটা তখন readonly: একটা এরিয়াকে
    রুট বানালে তার নিচের সব এমন এক বাবার নিচে পড়ত যে নিজেই সবচেয়ে
    নিচের স্তর — গাছটা তখন আর মই থাকত না।

    বাবার তালিকায় শুধু ঠিক উপরের চালু স্তরের এলাকাগুলো। অন্য স্তরের
    দেখালে ব্যবহারকারী বাছার পর ভুলের বার্তা পেত, আর কেন ভুল তা বোঝা
    কঠিন হত।
--}}
@php
    $isNew = ! $location->exists;
    $parentLevel = \App\Modules\MasterData\Models\Location::parentLevelOf($location->level);
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('master_data::menu.locations') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header
            :title="$isNew ? __('master_data::action.new') : __('master_data::action.edit')"
            :subtitle="__('master_data::level.' . $location->level)" />
    </x-slot:header>

    <form method="POST"
          action="{{ $isNew
              ? route('master_data.location.store')
              : route('master_data.location.update', $location) }}"
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

        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="mb-3 font-semibold">{{ __('master_data::section.identity') }}</h2>

            <div class="grid gap-3 sm:grid-cols-2">
                <x-ui.field name="code" :label="__('master_data::field.code')"
                            :value="old('code', $location->code)" required />

                <x-ui.field name="name_en" :label="__('master_data::field.name_en')"
                            :value="old('name_en', $location->name_en)" required />

                <x-ui.field name="name_bn" :label="__('master_data::field.name_bn')"
                            :value="old('name_bn', $location->name_bn)" />
            </div>
        </section>

        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="mb-3 font-semibold">{{ __('master_data::section.placement') }}</h2>

            <div class="grid gap-3 sm:grid-cols-2">
                <label class="block">
                    <span class="mb-1 block text-sm font-medium">
                        {{ __('master_data::field.level') }}
                        @if ($isNew)
                            <span class="text-(--color-danger)" aria-hidden="true">*</span>
                        @endif
                    </span>

                    @if ($isNew)
                        <select name="level" required
                                onchange="window.location = '{{ route('master_data.location.create') }}?level=' + this.value"
                                class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                       border-(--color-border) bg-(--color-surface-card) px-3">
                            @foreach ($ladder as $level)
                                <option value="{{ $level }}" @selected($location->level === $level)>
                                    {{ __('master_data::level.' . $level) }}
                                </option>
                            @endforeach
                        </select>
                    @else
                        {{-- সম্পাদনায় বদলানো যায় না — উপরের মন্তব্য দেখুন --}}
                        <input type="text" readonly
                               value="{{ __('master_data::level.' . $location->level) }}"
                               class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                      border-(--color-border) bg-(--color-surface-app) px-3
                                      text-(--color-ink-muted)">
                    @endif
                </label>

                @if ($parentLevel !== null)
                    <label class="block">
                        <span class="mb-1 block text-sm font-medium">
                            {{ __('master_data::level.' . $parentLevel) }}
                            <span class="text-(--color-danger)" aria-hidden="true">*</span>
                        </span>
                        <select name="parent_id" required
                                class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                       border-(--color-border) bg-(--color-surface-card) px-3">
                            <option value="">—</option>
                            @foreach ($parents as $parent)
                                <option value="{{ $parent->id }}"
                                        @selected(old('parent_id', $preselectedParent) == $parent->id)>
                                    {{ $parent->path() }}
                                </option>
                            @endforeach
                        </select>
                    </label>
                @endif

                {{-- দায়িত্ব — রুটে কে যায়। উপরের স্তরেও দেওয়া যায়:
                     একটা এরিয়ার একজন সুপারভাইজার থাকতে পারে। --}}
                <label class="block">
                    <span class="mb-1 block text-sm font-medium">{{ __('master_data::field.assigned_to') }}</span>
                    <select name="assigned_to"
                            class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                   border-(--color-border) bg-(--color-surface-card) px-3">
                        <option value="">—</option>
                        @foreach ($people as $person)
                            <option value="{{ $person->id }}"
                                    @selected(old('assigned_to', $location->assigned_to) == $person->id)>
                                {{ $person->name }}
                            </option>
                        @endforeach
                    </select>
                </label>
            </div>

            @if ($parentLevel === null)
                <p class="mt-3 text-2xs text-(--color-ink-muted)">
                    {{ __('master_data::message.levels_off') }}
                </p>
            @endif
        </section>

        <div class="flex flex-wrap gap-2">
            <x-ui.button type="submit" tone="primary"
                         ::class="busy && 'pointer-events-none opacity-70'">
                {{ __('core.action.save') }}
            </x-ui.button>

            <x-ui.button tone="secondary"
                         :href="$isNew
                             ? route('master_data.location.index')
                             : route('master_data.location.show', $location)">
                {{ __('core.action.cancel') }}
            </x-ui.button>
        </div>
    </form>
</x-layouts.app>
