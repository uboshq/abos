{{--
    নোটিশের ছাঁচ — বারবার লেখা কথাগুলো একবার লিখে রাখা।

    ── ⚠️ কেন ছাঁচে অগ্রাধিকারও বসে ────────────────────────────────────
    ⓘ কেবল লেখাটা রাখলে অর্ধেক কাজ হত। ⛔ "সার্ভার রক্ষণাবেক্ষণ" নোটিশে
    প্রতিবার হাতে জরুরি বাছতে হলে কোনো একদিন কেউ ভুলতেন, আর ঐ নোটিশটা
    বারেই যেত না — অথচ ঠিক ওটাই বারে সবচেয়ে বেশি দরকার।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('core.notice.templates_title') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('core.notice.templates_title')" />
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

    <div class="grid gap-4 lg:grid-cols-[minmax(0,24rem)_minmax(0,1fr)]">

        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="mb-3 font-semibold">{{ __('core.notice.template_new') }}</h2>

            <form method="POST" action="{{ route('system_admin.notice.template.store') }}" class="space-y-3">
                @csrf

                <x-ui.field name="code" :label="__('core.table.code')" required maxlength="32" />
                <x-ui.field name="name_en" :label="__('core.table.name')" required maxlength="120" />
                <x-ui.field name="name_bn" :label="__('core.table.name')" maxlength="120" />

                <x-ui.select name="priority"
                             :label="__('core.notice.priority_label')"
                             :options="$priorities"
                             value="normal" />

                <x-ui.field name="title" :label="__('core.table.title_field')" maxlength="255" />
                <x-ui.field name="summary" :label="__('core.notice.summary_label')" maxlength="300" />

                <div>
                    <label for="body" class="mb-1 block text-sm">{{ __('core.notice.body_label') }}</label>
                    <textarea name="body" id="body" rows="5" maxlength="4000"
                              class="w-full rounded-(--radius-field) border border-(--color-border)
                                     bg-(--color-surface-app) px-3 py-2 text-sm">{{ old('body') }}</textarea>
                </div>

                <x-ui.button type="submit" tone="primary">{{ __('core.action.save') }}</x-ui.button>
            </form>
        </section>

        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
            <x-ui.table
                :empty="__('core.notice.no_templates')"
                :rows="$templates"
                compact
                :columns="[
                    ['key' => 'code', 'label' => __('core.table.code'), 'width' => '8rem'],
                    ['key' => 'name', 'label' => __('core.table.name'),
                     'render' => fn ($r) => $r->name()],
                    ['key' => 'priority', 'label' => __('core.notice.priority_label'), 'width' => '9rem',
                     'render' => fn ($r) => $r->priority?->label() ?? '—'],

                    /*
                     * শুরু করার বোতাম — ছাঁচটার একমাত্র কাজ।
                     *
                     * ⛔ এটা না থাকলে ছাঁচগুলো কেবল একটা তালিকা হয়ে পড়ে
                     * থাকত — লেখা হয়েছে, জোড়া লাগেনি।
                     */
                    ['key' => 'use', 'label' => '', 'width' => '9rem',
                     'render' => fn ($r) => view('system_admin::notice.template-use', ['row' => $r])],
                ]" />

            <x-ui.pager :rows="$templates" />
        </section>
    </div>
</x-layouts.app>
