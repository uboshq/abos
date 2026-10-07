{{--
    এলাকা তৈরি ও সম্পাদনা।

    স্তরটা বদলানো যায় না — নতুনেও না (কোন স্তর, তা বলে দেয় কোন ট্যাবের
    বোতাম চাপা হল), সম্পাদনাতেও না। ঘরটা সবসময় readonly: একটা রিজিয়নকে
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
            :title="$isNew
                ? __('master_data::action.new_level', ['level' => __('master_data::level.' . $location->level)])
                : __('master_data::action.edit')"
            :subtitle="$isNew ? null : __('master_data::level.' . $location->level)" />
    </x-slot:header>

    <form method="POST"
          action="{{ $isNew
              ? route('master_data.location.store')
              : route('master_data.location.update', $location) }}"
          x-data="{ busy: false }"
          @submit="busy ? $event.preventDefault() : (busy = true)"
          class="max-w-screen-2xl space-y-4">
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
                            :value="old('code', $location->code)"
                            :placeholder="__('core.create.code_auto')"
                            :hint="__('core.create.code_auto_hint')" />

                <x-ui.field name="name_en" :label="__('master_data::field.location_name_en', ['level' => __('master_data::level.' . $location->level)])"
                            :value="old('name_en', $location->name_en)" required />

                <x-ui.field name="name_bn" :label="__('master_data::field.location_name_bn', ['level' => __('master_data::level.' . $location->level)])"
                            :value="old('name_bn', $location->name_bn)" />
            </div>
        </section>

        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="mb-3 font-semibold">{{ __('master_data::section.placement') }}</h2>

            <div class="grid gap-3 sm:grid-cols-2">
                {{-- ⭐ স্তরটা বাঁধা — নতুনেও, সম্পাদনাতেও। মালিকের নির্দেশ,
                     ১৯ সেপ্টেম্বর ২০২৬: *"alada alada create"*।

                     ⛔ আগে নতুন ফর্মে স্তরের একটা ড্রপডাউন ছিল। ⚠️ লাইভে
                     মালিক "নতুন এরিয়া" (আজকের নামে রিজিয়ন) খুলে ওটা বিভাগে বদলে দেন — ফলে একটা
                     বাড়তি বিভাগ তৈরি হল, আর রিজিয়নটা বসল তার নিচে। কোড ঠিকই
                     চলেছে; পর্দাটাই ভুলটা করতে ডেকেছিল।

                     ⭐ এখন প্রতিটা স্তরের নিজের বোতাম ("নতুন পয়েন্ট"), আর
                     ফর্মে স্তরটা কেবল লেখা, সাথে একটা লুকানো ঘর। অন্য স্তর
                     চাইলে অন্য ট্যাবের বোতাম।

                     ⓘ সম্পাদনায় বদলানো যায় না, আগের মতোই — একটা রিজিয়নকে রুট
                     বানালে তার নিচের সব সবচেয়ে নিচের স্তরের নিচে ঝুলত। --}}
                <label class="block">
                    <span class="mb-1 block text-sm font-medium">{{ __('master_data::field.level') }}</span>

                    <input type="text" readonly
                           value="{{ __('master_data::level.' . $location->level) }}"
                           class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                  border-(--color-border) bg-(--color-surface-app) px-3
                                  text-(--color-ink-muted)">

                    @if ($isNew)
                        <input type="hidden" name="level" value="{{ $location->level }}">
                    @endif
                </label>

                {{-- ⭐ উপরের স্তর খালি — অন্ধ গলির বদলে পরের ধাপ।
                     মালিকের অভিযোগ, ১৯ সেপ্টেম্বর ২০২৬: *"point creat hoyna"*।

                     ⛔ আগে এখানে বাবার ড্রপডাউনটা বসতই — একটাও বিকল্প ছাড়া,
                     অথচ `required`। ⚠️ ব্রাউজার জমা আটকাত একটা ছোট্ট ভাসমান
                     লেখা দিয়ে ("Please select an item"), আর মানুষ বুঝতেন না
                     কেন — ড্রপডাউন খুললে তো কিছুই নেই।

                     ⭐ এখন পর্দা সোজা বলে: আগে একটা এরিয়া লাগবে, আর
                     বোতামটা সেখানেই নিয়ে যায়। ⓘ মালিকের নিজের কথাই এটা —
                     *"1st e hobe Country Create, Divi Create, ei vabe…"*। --}}
                @if ($parentLevel !== null && $parents->isEmpty())
                    <div class="rounded-(--radius-field) border border-(--color-badge-pending-ink)/30
                                bg-(--color-badge-pending-bg) px-3 py-3 text-sm text-(--color-badge-pending-ink)
                                sm:col-span-2" role="status">
                        <p>
                            {{ __('master_data::message.need_parent_first', [
                                'parent' => __('master_data::level.' . $parentLevel),
                                'level' => __('master_data::level.' . $location->level),
                            ]) }}
                        </p>

                        <x-ui.button tone="primary" icon="plus" class="mt-2"
                                     :href="route('master_data.location.create', ['level' => $parentLevel])">
                            {{ __('master_data::action.new_level', ['level' => __('master_data::level.' . $parentLevel)]) }}
                        </x-ui.button>
                    </div>
                @elseif ($parentLevel !== null)
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
                     একটা রিজিয়নের একজন সুপারভাইজার থাকতে পারে। --}}
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
                             ? route('master_data.location.level', ['level' => $location->level])
                             : route('master_data.location.show', $location)">
                {{ __('core.action.cancel') }}
            </x-ui.button>
        </div>
    </form>
</x-layouts.app>
