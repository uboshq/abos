{{--
    ডকুমেন্ট আপলোড আর বিবরণ বদল — একটাই ফর্ম, দুই কাজে (§৬; ৮ অক্টোবর ২০২৬)।

    ⓘ তোলার সময় উপরে ফাইলের ঘর (একটা বা অনেকগুলো একসাথে, প্রতিটা নিজের ডকুমেন্ট হয়);
    বদলের সময় ফাইলের ঘর নেই — ⛔ ফাইল বদল মানে নতুন ভার্সন, সেটা বিস্তারিত পাতায় (§৯)।

    ⓘ দুই কলাম, সরবরাহকারীর ফর্মের মতো: বাঁয়ে **কাগজটা কী** (নাম, ধরন, ফোল্ডার, তারিখ,
    মেয়াদ, ট্যাগ, বিবরণ), ডানে **কার আর কে দেখবেন** (শাখা, বিভাগ, মালিক, গোপনীয়তা)।
    ⚠️ ১০৮০p-তে দুই কলাম পাশাপাশি, তার নিচে এক কলাম।

    ⛔ ফাইলের ধরন আর মাপ সার্ভার দেখে, বাইট পড়ে ([[DocumentFiles]]); `accept` কেবল বাছাইটা
    সহজ করে, পাহারা নয়।
--}}
@php
    $isNew = ! $document->exists;
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $isNew ? __('documents::menu.upload') : $document->name }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header
            :title="$isNew ? __('documents::menu.upload') : __('documents::action.edit_details')"
            :subtitle="$isNew ? __('documents::message.number_auto') : $document->document_no" />
    </x-slot:header>

    <form method="POST"
          action="{{ $isNew ? route('documents.store') : route('documents.update', $document) }}"
          @if ($isNew) enctype="multipart/form-data" @endif
          x-data="{ busy: false, docType: @js(old('doc_type', $document->doc_type)) }"
          @submit="busy ? $event.preventDefault() : (busy = true)"
          class="space-y-4">
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

        @if ($isNew)
            <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                <h2 class="mb-3 font-semibold">{{ __('documents::section.files') }}</h2>

                <label for="files" class="mb-1 block text-sm font-medium">
                    {{ __('documents::field.files') }}
                    <span class="text-(--color-danger)" aria-hidden="true">*</span>
                    <span class="sr-only">({{ __('core.form.required') }})</span>
                </label>

                {{-- ⭐ বাংলা বোতাম, ব্রাউজারের "Choose File" নয় (documents রিভিউ ⛔৫; [[x-ui.file-input]]) — নাম `files[]` থাকে --}}
                <x-ui.file-input id="files" name="files" :multiple="true" :required="true" :accept="$accept" aria-describedby="files-hint" />

                <p id="files-hint" class="mt-1 text-2xs text-(--color-ink-muted)">
                    {{ __('documents::message.files_hint', ['max' => $maxMb.' MB', 'count' => $maxFiles]) }}
                </p>
            </section>
        @endif

        <div class="grid gap-4 lg:grid-cols-2 lg:items-start">
            <section data-boxed class="space-y-3 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                <h2 class="font-semibold">{{ __('documents::section.what') }}</h2>

                <x-ui.field name="name" :label="__('documents::field.name')"
                            :value="old('name', $document->name)" :required="! $isNew" maxlength="191"
                            :hint="$isNew ? __('documents::message.name_hint') : null" />

                <div class="grid gap-3 sm:grid-cols-2">
                    <x-ui.select name="doc_type" :label="__('documents::field.doc_type')" :options="$types"
                                 :selected="old('doc_type', $document->doc_type)" required placeholder="—"
                                 x-on:change="docType = $event.target.value" />

                    <x-ui.select name="folder" :label="__('documents::field.folder')" :options="$folders"
                                 :selected="old('folder', $document->folder)" required placeholder="—" />
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <x-ui.field name="document_date" type="date" :label="__('documents::field.document_date')"
                                :value="old('document_date', $document->document_date?->toDateString())" />

                    <x-ui.field name="expiry_date" type="date" :label="__('documents::field.expiry_date')"
                                :value="old('expiry_date', $document->expiry_date?->toDateString())"
                                :hint="__('documents::message.expiry_hint')" />
                </div>

                <x-ui.field name="tags" :label="__('documents::field.tags')" maxlength="500" list="dms-tag-choices"
                            :value="old('tags', $document->tags)" :hint="__('documents::message.tags_hint')" />

                {{-- ⓘ প্রশাসনের ঠিক করা ট্যাগ — পরামর্শ, বাধ্য নয় (§২০ Tags) --}}
                <datalist id="dms-tag-choices">
                    @foreach ($tagChoices as $tag)
                        <option value="{{ $tag }}"></option>
                    @endforeach
                </datalist>

                <label class="block text-sm">
                    <span class="mb-1 block font-medium">{{ __('documents::field.description') }}</span>
                    <textarea name="description" rows="3" maxlength="5000"
                              class="w-full rounded-(--radius-field) border border-(--color-border)
                                     bg-(--color-surface-card) px-3 py-2">{{ old('description', $document->description) }}</textarea>
                </label>

                @if ($isNew)
                    <x-ui.field name="comment" :label="__('documents::field.comment')" maxlength="500"
                                :value="old('comment')" :hint="__('documents::message.comment_hint')" />
                @endif
            </section>

            <div class="space-y-4">
                <section data-boxed class="space-y-3 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                    <h2 class="font-semibold">{{ __('documents::section.whose') }}</h2>

                    {{-- ⛔ কেবল নিজের দেয়ালের শাখা; "গোটা কোম্পানি" কেবল যিনি সব শাখা দেখেন --}}
                    <x-ui.select name="branch_id" :label="__('documents::field.branch')" :options="$branches"
                                 :selected="old('branch_id', $document->branch_id)" :required="! $companyWide"
                                 :placeholder="$companyWide ? __('documents::message.company_wide') : '—'" />

                    <x-ui.select name="department_id" :label="__('documents::field.department')" :options="$departments"
                                 :selected="old('department_id', $document->department_id)" placeholder="—" />

                    <x-ui.select name="owner_id" :label="__('documents::field.owner')" :options="$owners"
                                 :selected="old('owner_id', $document->owner_id)" placeholder="—" />

                    {{-- ⭐ গোপনীয়তা (§১৪) — কেবল যে ধাপগুলো আপনি নিজে দেখেন --}}
                    <x-ui.select name="confidentiality" :label="__('documents::field.confidentiality')" :options="$levels"
                                 :selected="old('confidentiality', $document->confidentiality)" required
                                 :hint="__('documents::message.level_hint')" />
                </section>

                {{-- ⭐ বাড়তি ঘর (§২০ Metadata Fields) — কেবল বাছা ধরনের কাগজে যেগুলো আসে --}}
                @if ($metaFields->isNotEmpty())
                    <section data-boxed class="space-y-3 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                        <h2 class="font-semibold">{{ __('documents::section.metadata') }}</h2>

                        @foreach ($metaFields as $field)
                            <div @if (filled($field->doc_type)) x-show="docType === @js($field->doc_type)" @endif>
                                <x-ui.field :name="'meta['.$field->id.']'" :label="$field->name()"
                                            :type="$field->kind === 'number' ? 'number' : ($field->kind === 'date' ? 'date' : 'text')"
                                            step="any"
                                            :value="old('meta.'.$field->id, $metaValues[$field->id] ?? '')"
                                            :hint="$field->is_required ? __('documents::message.meta_required') : null" />
                            </div>
                        @endforeach
                    </section>
                @endif

                {{-- ⭐ বাতিল · সংরক্ষণ নিচের স্থির পট্টিতে ([[x-ui.form-actions]]; documents রিভিউ) --}}
                <x-ui.form-actions :cancel="$isNew ? route('documents.index') : route('documents.show', $document)">
                    <x-slot:submit>
                        <x-ui.button type="submit" tone="primary" :icon="$isNew ? 'attachment' : null"
                                     ::class="busy && 'pointer-events-none opacity-70'">
                            {{ $isNew ? __('documents::action.upload') : __('documents::action.save') }}
                        </x-ui.button>
                    </x-slot:submit>
                </x-ui.form-actions>
            </div>
        </div>
    </form>
</x-layouts.app>
