{{--
    এলাকার গাছ — দেশ › বিভাগ › অঞ্চল › এরিয়া › টেরিটরি › পয়েন্ট › রুট।

    হিসাবের ছকের মতোই গাছ, আর একই কারণে: "রুট-৩" একা কিছু বলে না,
    "ময়মনসিংহ › ত্রিশাল › রুট-৩" বলে।

    চালু স্তরগুলো শিরোনামের নিচে দেখানো হয়, কারণ অঞ্চল ও টেরিটরি বন্ধ
    থাকলে ব্যবহারকারী বুঝবে না কেন তার এরিয়ার বাবা সরাসরি বিভাগ।
--}}
@php
    $columns = [
                ['key' => 'code', 'label' => __('master_data::field.code'), 'width' => '9rem',
                 'render' => fn ($l) => view('master_data::location.partials.code', ['location' => $l])],
                ['key' => 'name_en', 'label' => __('master_data::field.path'),
                 'render' => fn ($l) => $l->path()],
                ['key' => 'level', 'label' => __('master_data::field.level'), 'width' => '9rem',
                 'render' => fn ($l) => __('master_data::level.' . $l->level)],
                ['key' => 'assigned_to', 'label' => __('master_data::field.assigned_to'), 'width' => '11rem',
                 'render' => fn ($l) => $l->assignee?->name ?? '—'],
            ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('master_data::menu.locations') }}</x-slot:title>

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
            <ul class="list-inside list-disc">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($total === 0)
        {{-- খালি তালিকা — দেশ ও বিভাগ সবার জন্য এক, তাই ওগুলো হাতে
             লিখতে বলার মানে নেই। ভুল বানানে ঢুকলে পরে রিপোর্টে দুইটা
             "ময়মনসিংহ" দেখা যেত। --}}
        <div data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-8 text-center">
            <h2 class="text-lg font-semibold">{{ __('master_data::message.empty_locations') }}</h2>

            <p class="mx-auto mt-2 max-w-(--spacing-prose-max) text-sm text-(--color-ink-muted)">
                {{ __('master_data::message.empty_locations_note') }}
            </p>

            @can('master_data.manage')
                <div class="mt-4 flex flex-wrap justify-center gap-2">
                    <form method="POST" action="{{ route('master_data.location.install') }}">
                        @csrf
                        <x-ui.button type="submit" tone="primary">
                            {{ __('master_data::action.install_bangladesh') }}
                        </x-ui.button>
                    </form>

                    <x-ui.button tone="secondary" :href="route('master_data.location.create')">
                        {{ __('master_data::action.new') }}
                    </x-ui.button>
                </div>
            @endcan
        </div>
    @else
        <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card)">
            <form method="GET" class="contents">
                <x-ui.toolbar :title="__('master_data::menu.locations')" :count="collect($ladder)->map(fn ($level) => __('master_data::level.' . $level))->implode(' › ')"
                :columns="$columns">
        <x-slot:actions>
            @can('master_data.manage')
                    @if ($total > 0)
                        <x-ui.button tone="primary" icon="plus" :href="route('master_data.location.create')">
                            {{ __('master_data::action.new') }}
                        </x-ui.button>
                    @endif
                @endcan
        </x-slot:actions>
                    <label class="flex min-h-(--spacing-touch) items-center gap-2 text-sm">
                        <input type="checkbox" name="inactive" value="1" @checked($showInactive) class="size-4">
                        {{ __('master_data::action.show_inactive') }}
                    </label>
                </x-ui.toolbar>
            </form>

            @if ($tooManyToShow)
                <div class="border-b border-(--color-border) bg-(--color-surface-app) px-4 py-3 text-sm">
                    {{ __('master_data::message.too_many', ['count' => $total]) }}
                </div>
            @endif

            @if ($q)
                <x-ui.table
            :compact="request()->boolean('compact')"
                    :empty="__('core.empty.no_results')"
                    :rows="$results"
                    :columns="$columns" />
            @elseif (! $tooManyToShow)
                <div class="overflow-x-auto">
                    <table class="ui-grid">
                        <thead>
                            <tr>
                                <th scope="col">
                                    {{ __('master_data::field.name') }}
                                </th>
                                <th scope="col" style="width: 9rem"
                                    class="hidden sm:table-cell">
                                    {{ __('master_data::field.level') }}
                                </th>
                                <th scope="col" style="width: 11rem"
                                    class="hidden lg:table-cell">
                                    {{ __('master_data::field.assigned_to') }}
                                </th>
                                <th scope="col" style="width: 5rem" 
                                    aria-label="{{ __('master_data::action.new') }}"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($tree as $node)
                                @include('master_data::location.partials.row', ['location' => $node, 'depth' => 0])
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endif
</x-layouts.app>
