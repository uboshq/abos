{{--
    বেতনের খাতের তালিকা।

    আয় ও কর্তন এক তালিকায়, কারণ বেতনশিটে দুইটাই পাশাপাশি বসে — আলাদা
    দুইটা পর্দা হলে "মোট কর্তন কেন এত" প্রশ্নে দুই জায়গায় যেতে হত।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('hr::menu.salary_heads') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('hr::menu.salary_heads')">
            <x-slot:actions>
                <x-ui.button tone="primary" icon="+" :href="route('hr.salary_head.create')">
                    {{ __('hr::action.new_head') }}
                </x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>

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
        {{-- খালি তালিকা — এখান থেকেই শুরু। এই খাতগুলো ছাড়া কারও বেতনই
             বসানো যায় না, তাই "নিজে বানান" বলাটা কাজ ঠেলে দেওয়া। --}}
        <div class="mb-4 rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card) p-8 text-center">
            <h2 class="text-lg font-semibold">{{ __('hr::message.empty_heads') }}</h2>

            <p class="mx-auto mt-2 max-w-(--spacing-prose-max) text-sm text-(--color-ink-muted)">
                {{ __('hr::message.empty_heads_note') }}
            </p>

            <form method="POST" action="{{ route('hr.salary_head.install') }}" class="mt-4">
                @csrf
                <x-ui.button type="submit" tone="primary">
                    {{ __('hr::action.install_defaults') }}
                </x-ui.button>
            </form>
        </div>
    @endif

    <div class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form method="GET" class="contents">
            <x-ui.toolbar :search="false">
                <label class="flex min-h-(--spacing-touch) items-center gap-2 text-sm">
                    <input type="checkbox" name="inactive" value="1" @checked(request()->boolean('inactive'))
                           class="size-4">
                    {{ __('hr::action.show_inactive') }}
                </label>
            </x-ui.toolbar>
        </form>

        <x-ui.table
            :empty="__('hr::message.no_heads')"
            :rows="$heads"
            :columns="[
                ['key' => 'code', 'label' => __('hr::field.code'), 'width' => '9rem',
                 'render' => fn ($h) => view('hr::salary_head.partials.code', ['head' => $h])],
                ['key' => 'name_en', 'label' => __('hr::field.name'),
                 'render' => fn ($h) => $h->name()],
                ['key' => 'kind', 'label' => __('hr::field.kind'), 'width' => '8rem',
                 'render' => fn ($h) => __('hr::kind.' . $h->kind)],
                ['key' => 'calculation', 'label' => __('hr::field.calculation'), 'width' => '12rem',
                 'render' => fn ($h) => __('hr::kind.' . $h->calculation)],
                ['key' => 'account', 'label' => __('hr::field.account'), 'width' => '14rem',
                 'render' => fn ($h) => $h->account?->label() ?? '—'],
                ['key' => 'actions', 'label' => '—', 'width' => '8rem',
                 'render' => fn ($h) => view('hr::salary_head.partials.actions', ['head' => $h])],
            ]" />
    </div>
</x-layouts.app>
