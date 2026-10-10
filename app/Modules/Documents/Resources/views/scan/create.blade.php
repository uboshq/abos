{{--
    স্ক্যান ও OCR (§৭; পঞ্চম ধাপ, ৯ অক্টোবর ২০২৬)।

    ⓘ বাঁয়ে পাতা — ক্যামেরা বা ফাইল, বারবার যোগ, ছোট ছবিতে দেখা; ডানে পড়া লেখা আর চারটা তথ্য (বিল নম্বর,
    তারিখ, পক্ষ, অঙ্ক) — মানুষ দেখে ঠিক করেন; নিচে কাগজের বিবরণ। জমা দিলে পাতাগুলো এক PDF, লেখা তার সাথে।

    ⛔ লেখা পড়া ব্যবহারকারীর ব্রাউজারে ([[document-scan.js]]); tesseract.js-এর সব ফাইল `/vendor/tesseract/`
    থেকে — আমাদের সার্ভার। ⓘ কেবল ছাপা লেখা; হাতের লেখা নয়।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('documents::menu.scan') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('documents::menu.scan')" :subtitle="__('documents::message.scan_subtitle')" />
    </x-slot:header>

    @if ($errors->any())
        <div role="alert"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm text-(--color-badge-danger-ink)">
            <ul class="list-inside list-disc">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('documents.scan.store') }}" enctype="multipart/form-data"
          x-data="documentScan"
          data-max-pages="{{ $maxPages }}"
          data-ocr-base="{{ asset('vendor/tesseract') }}"
          data-ocr-languages="{{ $language }}"
          data-fields-url="{{ route('documents.ocr.fields') }}"
          data-csrf="{{ csrf_token() }}"
          data-word-loading="{{ __('documents::message.ocr_loading') }}"
          data-word-reading="{{ __('documents::message.ocr_reading') }}"
          data-word-done="{{ __('documents::message.ocr_done') }}"
          data-word-failed="{{ __('documents::message.ocr_failed') }}"
          class="space-y-4">
        @csrf
        <input type="hidden" name="ocr_language" value="{{ $language }}">
        <input type="hidden" name="ocr_confidence" x-bind:value="confidence">

        <div class="grid gap-4 xl:grid-cols-2 xl:items-start">
            {{-- ── পাতা ── --}}
            <section data-boxed class="space-y-3 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                <h2 class="font-semibold">{{ __('documents::section.pages') }}</h2>

                <label class="block text-sm">
                    <span class="mb-1 block font-medium">{{ __('documents::field.add_pages') }}</span>
                    {{-- ⓘ `capture` — ফোনে সরাসরি ক্যামেরা খোলে; কম্পিউটারে সাধারণ ফাইল বাছাই --}}
                    {{-- ⭐ বাংলা বোতাম (documents রিভিউ ⛔৫)। ⓘ `form` এমন একটা ফর্মের নাম যা নেই — বাছা ছবি এই ঘর দিয়ে জমা যায় না
                         (নইলে নিচের আসল ঘরের সাথে দুইবার যেত); কেবল `addPages` পড়ে --}}
                    <x-ui.file-input id="scan-pick" name="scan_pick" :multiple="true" accept="image/jpeg,image/png,image/webp"
                                     capture="environment" form="no-such-form" x-on:change="addPages" />
                </label>

                {{-- ⓘ আসল জমার ঘর — সব পাতা এখানে জোড়া হয় ([[documentScan.syncInput]]) --}}
                <input type="file" name="pages[]" multiple x-ref="pages" class="sr-only" tabindex="-1" aria-hidden="true">

                <p class="text-2xs text-(--color-ink-muted)">{{ __('documents::message.scan_pages_hint', ['count' => $maxPages]) }}</p>

                <ol class="grid grid-cols-3 gap-2 sm:grid-cols-4" data-scan-pages>
                    <template x-for="(page, index) in pages" :key="page.url">
                        <li class="relative overflow-hidden rounded-(--radius-field) border border-(--color-border)">
                            <img :src="page.url" :alt="page.name" class="h-28 w-full object-cover">
                            <button type="button" x-on:click="removePage(index)"
                                    class="absolute end-1 top-1 rounded-(--radius-badge) bg-(--color-surface-card) px-1.5 text-xs text-(--color-danger)">
                                ×<span class="sr-only">{{ __('documents::action.remove') }}</span>
                            </button>
                        </li>
                    </template>
                </ol>

                <div class="flex flex-wrap items-center gap-3">
                    @if ($enabled)
                        <x-ui.button type="button" icon="search" x-on:click="readText" ::disabled="busy || ! pages.length">
                            {{ __('documents::action.read_text') }}
                        </x-ui.button>
                        <span class="text-sm text-(--color-ink-muted)" x-text="status" aria-live="polite"></span>
                        <span class="num text-sm" x-show="busy" x-text="progress + '%'"></span>
                    @else
                        <span class="text-sm text-(--color-ink-muted)">{{ __('documents::message.ocr_off') }}</span>
                    @endif
                </div>
                <p class="text-2xs text-(--color-ink-muted)">{{ __('documents::message.ocr_offline_hint') }}</p>
            </section>

            {{-- ── পড়া লেখা আর তথ্য — মানুষ দেখে ঠিক করেন ── --}}
            <section data-boxed class="space-y-3 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                <h2 class="font-semibold">{{ __('documents::section.ocr_review') }}</h2>

                <div class="grid gap-3 sm:grid-cols-2">
                    @foreach ($fields as $field)
                        <x-ui.field :name="'ocr_fields['.$field.']'" :label="__('documents::field.ocr_'.$field)"
                                    :type="$field === 'date' ? 'date' : 'text'" maxlength="120"
                                    :value="old('ocr_fields.'.$field)" :data-ocr-field="$field" />
                    @endforeach
                </div>

                <label class="block text-sm">
                    <span class="mb-1 block font-medium">{{ __('documents::field.ocr_text') }}</span>
                    <textarea name="ocr_text" rows="10" x-ref="text" maxlength="200000"
                              class="w-full rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-3 py-2 text-sm">{{ old('ocr_text') }}</textarea>
                </label>
            </section>
        </div>

        {{-- ── কাগজের বিবরণ ── --}}
        <section data-boxed class="grid gap-3 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4 lg:grid-cols-3">
            <x-ui.field name="name" :label="__('documents::field.name')" maxlength="191" :value="old('name')" />
            <x-ui.select name="doc_type" :label="__('documents::field.doc_type')" :options="$types"
                         :selected="old('doc_type', 'invoice')" required />
            <x-ui.select name="folder" :label="__('documents::field.folder')" :options="$folders"
                         :selected="old('folder', 'purchase')" required />
            <x-ui.select name="branch_id" :label="__('documents::field.branch')" :options="$branches"
                         :selected="old('branch_id', $defaultBranch)" :required="! $companyWide"
                         :placeholder="$companyWide ? __('documents::message.company_wide') : '—'" />
            <x-ui.select name="confidentiality" :label="__('documents::field.confidentiality')" :options="$levels"
                         :selected="old('confidentiality', $internal)" required />
            <x-ui.field name="document_date" type="date" :label="__('documents::field.document_date')" :value="old('document_date')" />

            <div class="flex items-end justify-end gap-2 lg:col-span-3">
                <x-ui.button :href="route('documents.index')">{{ __('documents::action.cancel') }}</x-ui.button>
                <x-ui.button type="submit" tone="primary" icon="attachment" ::disabled="busy || ! pages.length">
                    {{ __('documents::action.save_scan') }}
                </x-ui.button>
            </div>
        </section>
    </form>

    @push('scripts')
        {{-- ⛔ নিজের সার্ভারের ফাইল — CDN নয় ([[AScannedPageIsReadOnOurOwnServerTest]]) --}}
        <script src="{{ asset('vendor/tesseract/tesseract.min.js') }}" @nonce defer></script>
    @endpush
</x-layouts.app>
