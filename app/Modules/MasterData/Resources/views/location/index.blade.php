{{--
    এলাকার গাছ — দেশ › বিভাগ › জোন › রিজিয়ন › এরিয়া › পয়েন্ট › রুট।

    হিসাবের ছকের মতোই গাছ, আর একই কারণে: "রুট-৩" একা কিছু বলে না,
    "ময়মনসিংহ › ত্রিশাল › রুট-৩" বলে।

    চালু স্তরগুলো শিরোনামের নিচে দেখানো হয়, কারণ জোন ও এরিয়া বন্ধ
    থাকলে ব্যবহারকারী বুঝবে না কেন তার রিজিয়নের বাবা সরাসরি বিভাগ।
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
                // অবস্থা — নিষ্ক্রিয় সারি তালিকায় থাকে, মালিকের নির্দেশ, ২০ সেপ্টেম্বর ২০২৬
                ['key' => 'state', 'label' => __('core.table.status'), 'width' => '8rem',
                 'render' => fn ($l) => view('master_data::location.partials.state', ['location' => $l])],
                // সম্পাদনা · সক্রিয়/নিষ্ক্রিয় · মুছুন — মালিকের নির্দেশ, ১৯ সেপ্টেম্বর ২০২৬
                ['key' => 'actions', 'label' => __('core.table.actions'), 'width' => '5rem',
                 'render' => fn ($l) => view('master_data::location.partials.actions', ['location' => $l])],
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
                {{-- ⓘ মই-টা ("দেশ › বিভাগ › …") আর শিরোনামের পাশে লেখা নেই —
                     ১৯ সেপ্টেম্বর ২০২৬। নিচের ট্যাবগুলো ঠিক ঐ কথাটাই বলে,
                     আর বেশি বলে: প্রতিটায় ক্লিক করা যায়, পাশে সংখ্যা থাকে।
                     ⚠️ দুইটা একসাথে থাকলে একই মই পর্দায় দুইবার — মালিক ধূসর
                     লেখাটাকেই দাগিয়ে বলেছিলেন "এগুলো আলাদা ট্যাব হবে"। --}}
                <x-ui.toolbar :title="__('master_data::menu.locations')"
                :columns="$columns">
        <x-slot:actions>
            @can('master_data.manage')
                    {{-- ⓘ স্তরের পাতায় বোতামটা **ঐ স্তরেরই** — "নতুন পয়েন্ট",
                         কেবল "নতুন" নয়। ⚠️ আর উপরের স্তর খালি থাকলে বোতামটা
                         বসে না: নিচে একটা বার্তা আগে কোনটা বানাতে হবে বলে দেয়,
                         আর একটা মৃত বোতামের চেয়ে সেটাই কাজের। --}}
                    @if ($level !== null && ! $parentsMissing)
                        <x-ui.button tone="primary" icon="plus"
                                     :href="route('master_data.location.create', ['level' => $level])">
                            {{ __('master_data::action.new_level', ['level' => __('master_data::level.' . $level)]) }}
                        </x-ui.button>
                    @elseif ($level === null && $total > 0)
                        {{-- ⓘ গাছের ট্যাবেও বোতামটা নিজের স্তরের নাম বলে: ফর্মে
                             স্তর আর বদলানো যায় না, তাই সাদা "নতুন" চাপলে কী
                             তৈরি হবে তা আগেই জানা থাকা চাই। নিচের স্তরগুলো গাছের
                             সারির "+" বা নিজের ট্যাব থেকে। --}}
                        <x-ui.button tone="primary" icon="plus"
                                     :href="route('master_data.location.create', ['level' => $ladder[0]])">
                            {{ __('master_data::action.new_level', ['level' => __('master_data::level.' . $ladder[0])]) }}
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

            {{-- ⭐ স্তরের ট্যাব — মালিকের নির্দেশ, ১৯ সেপ্টেম্বর ২০২৬।

                 *"ei sob alada alada create hobe alada list hobe, Tree hobe"*।
                 ⓘ প্রথমটা "গাছ" — গোটা পিরামিড এক সাথে; বাকিগুলো মই-এর
                 ক্রমে, প্রতিটায় নিজের তালিকা আর নিজের "নতুন" বোতাম।

                 ⭐ পাশের সংখ্যাটাই পথ দেখায়: "এরিয়া ০" চোখে পড়লেই বোঝা যায়
                 পয়েন্ট বানানোর আগে কী লাগবে — আলাদা কোনো নির্দেশনা লিখতে হয় না।

                 ⓘ মই-টা সেটিংস থেকে আসে, তাই জোন বা এরিয়া বন্ধ থাকলে
                 তাদের ট্যাবও থাকে না। --}}
            <nav class="flex flex-wrap gap-1 border-b border-(--color-border) px-3 py-2"
                 aria-label="{{ __('master_data::message.levels_nav') }}">
                @php
                    $tab = 'inline-flex items-center gap-1.5 rounded-(--radius-field) px-3 py-1.5 text-sm transition-colors';
                    $tabOn = 'bg-(--color-brand-500) text-white';
                    $tabOff = 'text-(--color-ink-muted) hover:bg-(--color-surface-hover)';
                @endphp

                <a href="{{ route('master_data.location.index') }}"
                   @class([$tab, $level === null ? $tabOn : $tabOff])
                   @if ($level === null) aria-current="page" @endif>
                    {{ __('master_data::message.tree_tab') }}
                </a>

                @foreach ($ladder as $step)
                    <a href="{{ route('master_data.location.level', ['level' => $step]) }}"
                       @class([$tab, $level === $step ? $tabOn : $tabOff])
                       @if ($level === $step) aria-current="page" @endif>
                        {{ __('master_data::level.' . $step) }}
                        <span class="num text-xs opacity-80">{{ $counts[$step] ?? 0 }}</span>
                    </a>
                @endforeach
            </nav>

            @if ($level !== null)
                {{-- ⭐ উপরের স্তর খালি — পরের ধাপটা সোজা বলা। ⛔ নাহলে এই
                     পাতায় কেবল একটা খালি তালিকা থাকত আর কোনো বোতাম নয়,
                     আর মানুষ বুঝতেন না কেন কিছু বানানো যাচ্ছে না। --}}
                @if ($parentsMissing)
                    <div class="flex flex-wrap items-center gap-3 border-b border-(--color-border)
                                bg-(--color-badge-pending-bg) px-4 py-3 text-sm text-(--color-badge-pending-ink)"
                         role="status">
                        <span class="flex-1">
                            {{ __('master_data::message.need_parent_first', [
                                'parent' => __('master_data::level.' . $parentLevel),
                                'level' => __('master_data::level.' . $level),
                            ]) }}
                        </span>

                        @can('master_data.manage')
                            <x-ui.button tone="primary" icon="plus"
                                         :href="route('master_data.location.create', ['level' => $parentLevel])">
                                {{ __('master_data::action.new_level', ['level' => __('master_data::level.' . $parentLevel)]) }}
                            </x-ui.button>
                        @endcan
                    </div>
                @endif

                <x-ui.table
                    :compact="request()->boolean('compact')"
                    :empty="__('master_data::message.level_empty', ['level' => __('master_data::level.' . $level)])"
                    :rows="$rows"
                    :columns="$columns" />

                @if ($rows->hasPages())
                    <div class="border-t border-(--color-border) px-4 py-3">
                        {{ $rows->links() }}
                    </div>
                @endif
            @elseif ($tooManyToShow)
                <div class="border-b border-(--color-border) bg-(--color-surface-app) px-4 py-3 text-sm">
                    {{ __('master_data::message.too_many', ['count' => $total]) }}
                </div>
            @endif

            @if ($level === null && $q)
                <x-ui.table
            :compact="request()->boolean('compact')"
                    :empty="__('core.empty.no_results')"
                    :rows="$results"
                    :columns="$columns" />
            @elseif ($level === null && ! $tooManyToShow)
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
