{{--
    ছাঁচের সম্পাদক (§২ Document Editor; সপ্তম ধাপ, ৯ অক্টোবর ২০২৬)।

    ⓘ বাঁয়ে লেখা — সহজ নিয়ম: `# শিরোনাম`, `## ছোট শিরোনাম`, `**মোটা**`, ফাঁকা লাইনে অনুচ্ছেদ, `---` দাগ, আর
    `{{ নাম }}` ঘর; ডানে নিয়মগুলো আর নিজে থেকে ভরা ঘর। নিচে রাখা ছাঁচের নমুনা — ঘর ফাঁকা রেখে।
--}}
@php
    $isNew = ! $template->exists;
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $isNew ? __('documents::menu.editor') : $template->title }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$isNew ? __('documents::menu.editor') : $template->title" :subtitle="__('documents::message.editor_subtitle')" />
    </x-slot:header>

    @if (session('saved'))
        <div role="status" class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm text-(--color-badge-success-ink)">{{ session('saved') }}</div>
    @endif

    @if ($errors->any())
        <div role="alert" class="mb-4 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm text-(--color-badge-danger-ink)">
            <ul class="list-inside list-disc">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <form method="POST" action="{{ $isNew ? route('documents.templates.store') : route('documents.templates.update', $template) }}"
          class="grid gap-4 xl:grid-cols-3 xl:items-start">
        @csrf
        @unless ($isNew) @method('PUT') @endunless

        <section data-boxed class="space-y-3 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4 xl:col-span-2">
            <div class="grid gap-3 sm:grid-cols-4">
                <x-ui.field name="code" :label="__('documents::field.code')" :value="old('code', $template->code)" maxlength="24" required />
                <div class="sm:col-span-3">
                    <x-ui.field name="title" :label="__('documents::field.template_title')" :value="old('title', $template->title)" maxlength="120" required />
                </div>
            </div>
            <div class="grid gap-3 sm:grid-cols-2">
                <x-ui.select name="doc_type" :label="__('documents::field.doc_type')" :options="$types" :selected="old('doc_type', $template->doc_type)" required />
                <x-ui.select name="folder" :label="__('documents::field.folder')" :options="$folders" :selected="old('folder', $template->folder)" required />
            </div>
            <label class="block text-sm">
                <span class="mb-1 block font-medium">{{ __('documents::field.template_body') }}</span>
                <textarea name="body" rows="18" maxlength="20000" required data-template-editor
                          class="w-full rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-3 py-2 font-mono text-sm">{{ old('body', $template->body) }}</textarea>
            </label>
            @unless ($isNew)
                <label class="flex items-center gap-2 text-sm">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" name="is_active" value="1" class="size-4" @checked(old('is_active', $template->is_active))>
                    {{ __('documents::field.is_active') }}
                </label>
            @endunless
            <div class="flex justify-end gap-2">
                <x-ui.button :href="route('documents.templates')">{{ __('documents::action.cancel') }}</x-ui.button>
                <x-ui.button type="submit" tone="primary">{{ __('documents::action.save') }}</x-ui.button>
            </div>
        </section>

        <aside data-boxed class="space-y-2 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4 text-sm">
            <h2 class="font-semibold">{{ __('documents::section.editor_rules') }}</h2>
            <p class="whitespace-pre-line text-2xs text-(--color-ink-muted)">{{ __('documents::message.editor_rules') }}</p>
            <h3 class="pt-2 font-semibold">{{ __('documents::section.built_in_fields') }}</h3>
            <ul class="flex flex-wrap gap-1">
                @foreach ($builtIns as $field)
                    <li><code class="rounded-(--radius-badge) bg-(--color-surface-app) px-1 text-2xs">{{ '{'.'{ '.$field.' }'.'}' }}</code></li>
                @endforeach
            </ul>
        </aside>
    </form>

    @if ($preview !== null)
        <section data-boxed class="mt-4 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
            <h2 class="border-b border-(--color-border) bg-(--color-section-head) px-4 py-3 font-semibold">{{ __('documents::section.preview') }}</h2>
            {{-- ⓘ নিরাপদ করা লেখা ([[DocumentTemplates::html()]]) — কেবল আমাদের চিহ্ন HTML --}}
            <div class="max-w-none [&_h2]:mb-2 [&_h2]:text-lg [&_h2]:font-semibold [&_h3]:mb-1 [&_h3]:font-semibold [&_p]:mb-2 [&_hr]:my-3 px-6 py-4 text-sm" data-template-preview>{!! $preview !!}</div>
        </section>
    @endif
</x-layouts.app>
