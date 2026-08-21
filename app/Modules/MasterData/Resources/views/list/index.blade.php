{{--
    ছয়টা মাস্টার তালিকার যেকোনো একটা।

    কলামগুলো সংজ্ঞা থেকে আসে (spec.columns), তাই সপ্তম তালিকা যোগ করতে
    এই ফাইলটা ছুঁতে হয় না — কন্ট্রোলারের KINDS-এ একটা সারি লিখলেই হয়।

    ডিফল্ট সারিটা আলাদা করে চিহ্নিত: নতুন লেনদেনে ওটাই আপনা থেকে বসে,
    আর কোনটা বসবে তা না জানলে ভুল ধরা যায় না।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __($spec['title']) }}</x-slot:title>

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

    @if ($canInstallDefaults)
        {{-- খালি তালিকা — এখান থেকেই শুরু। একক, কর, শর্ত ও কারণ কোড
             ছাড়া প্রথম বিলটাই লেখা যায় না, তাই "নিজে বানান" বলাটা
             কাজ ঠেলে দেওয়া। --}}
        <div data-boxed class="mb-4 rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card) p-8 text-center">
            <h2 class="text-lg font-semibold">{{ __('master_data::message.empty_lists') }}</h2>

            <p class="mx-auto mt-2 max-w-(--spacing-prose-max) text-sm text-(--color-ink-muted)">
                {{ __('master_data::message.empty_lists_note') }}
            </p>

            @can('master_data.manage')
                <form method="POST" action="{{ route('master_data.' . $spec['route'] . '.install') }}"
                      class="mt-4">
                    @csrf
                    <x-ui.button type="submit" tone="primary">
                        {{ __('master_data::action.install_defaults') }}
                    </x-ui.button>
                </form>
            @endcan
        </div>
    @endif

    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form method="GET" class="contents">
            <x-ui.toolbar :title="__($spec['title'])" :count="trans_choice('master_data::message.count', $records->count(), ['count' => $records->count()])" :sort="$sortOptions">
        <x-slot:actions>
            @can('master_data.manage')
                    <x-ui.button tone="primary" icon="plus"
                                 :href="route('master_data.' . $spec['route'] . '.create')">
                        {{ __('master_data::action.new') }}
                    </x-ui.button>
                @endcan
        </x-slot:actions>
                <label class="flex min-h-(--spacing-touch) items-center gap-2 text-sm">
                    <input type="checkbox" name="inactive" value="1" @checked(request()->boolean('inactive')) class="size-4">
                    {{ __('master_data::action.show_inactive') }}
                </label>
            </x-ui.toolbar>
        </form>

        <x-ui.table
            :compact="request()->boolean('compact')"
            :empty="$q ? __('core.empty.no_results') : __('master_data::message.none_yet')"
            :rows="$records"
            :columns="array_merge(
                [
                    ['key' => 'code', 'label' => __('master_data::field.code'), 'width' => '9rem',
                     'render' => fn ($r) => view('master_data::list.partials.code', ['record' => $r, 'spec' => $spec])],
                    ['key' => 'name_en', 'label' => __('master_data::field.name'),
                     'render' => fn ($r) => view('master_data::list.partials.name', ['record' => $r])],
                ],
                collect($spec['columns'])->map(fn ($column) => [
                    'key' => $column,
                    'label' => __($spec['fields'][$column]['label']),
                    'numeric' => ($spec['fields'][$column]['type'] ?? '') === 'number',
                    'width' => '10rem',
                    'render' => fn ($r) => view('master_data::list.partials.value', [
                        'record' => $r, 'column' => $column,
                        'field' => $spec['fields'][$column], 'options' => $options,
                    ]),
                ])->all(),
                [
                    ['key' => 'actions', 'label' => '—', 'width' => '9rem',
                     'render' => fn ($r) => view('master_data::list.partials.actions', ['record' => $r, 'spec' => $spec])],
                ],
            )" />
    </div>
</x-layouts.app>
