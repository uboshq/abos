{{--
    একটা ডকুমেন্টের বিস্তারিত — প্রিভিউ, বিবরণ, ভার্সন, অডিট (§৫, §৯; ৮ অক্টোবর ২০২৬)।

    ⓘ চারটা অংশ চারটা `<section>`, প্রতিটার নিজের `<h2>` — মাথার নোঙর-পটি
    ([[x-ui.anchor-nav]]) নিজেই ওগুলো থেকে ট্যাব বানায়। ⚠️ অনুমোদন আর শেয়ারের ট্যাব
    পরিকল্পনায় আছে (§১০, §১৪) কিন্তু আজ নেই — কাজ-না-করা ট্যাবের চেয়ে না-থাকা ট্যাব ভালো।

    ⛔ ফাইল খোলে কেবল এই মডিউলের দরজা দিয়ে (প্রিভিউ, নামানো, ছাপা) — প্রতিটা দরজা নিজের
    চাবি চায় আর অডিটে লেখে। প্রিভিউ পাতার ভিতরে কেবল PDF আর ছবি; বাকি সব নামিয়ে দেখা।
--}}
@php
    $choices = app(\App\Modules\Documents\Services\DocumentChoices::class);
    use App\Core\Support\DateFormat;
    use App\Models\AuditTrail;

    $current = $document->currentVersion;
    $isPdf = $mime === 'application/pdf';
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $document->name }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$document->name"
                          :subtitle="$document->document_no.' · '.$choices->folderName($document->folder)">
            <x-slot:actions>
                @can('download', $document)
                    <x-ui.button icon="download" :href="route('documents.download', $document)">
                        {{ __('documents::action.download') }}
                    </x-ui.button>
                @endcan

                @if ($inline)
                    @can('print', $document)
                        <x-ui.button icon="printer" :href="route('documents.print', $document)" target="_blank" rel="noopener">
                            {{ __('documents::action.print') }}
                        </x-ui.button>
                    @endcan
                @endif

                @can('update', $document)
                    <x-ui.button icon="edit" :href="route('documents.edit', $document)">
                        {{ __('documents::action.edit_details') }}
                    </x-ui.button>
                @endcan
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

    @if ($document->isArchived())
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-info-bg) px-3 py-2 text-sm text-(--color-badge-info-ink)">
            {{ __('documents::message.is_archived', [
                'when' => DateFormat::format($document->archived_at),
                'who' => $document->archiver?->name ?? '—',
            ]) }}
        </div>
    @elseif ($document->isApproved())
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm text-(--color-badge-success-ink)">
            {{ __('documents::message.is_approved') }}
        </div>
    @endif

    <div class="grid gap-4 xl:grid-cols-3 xl:items-start">
        {{-- ── প্রিভিউ ── --}}
        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) xl:col-span-2">
            <h2 class="border-b border-(--color-border) bg-(--color-section-head) px-4 py-3 font-semibold">
                {{ __('documents::section.preview') }}
            </h2>

            <div class="p-4">
                @if ($current === null)
                    <p class="text-sm text-(--color-ink-muted)">{{ __('documents::message.no_file') }}</p>
                @elseif ($inline && $isPdf)
                    <iframe src="{{ route('documents.preview', $document) }}" title="{{ $document->name }}"
                            class="h-[70vh] w-full rounded-(--radius-field) border border-(--color-border)"></iframe>
                @elseif ($inline)
                    <img src="{{ route('documents.preview', $document) }}" alt="{{ $document->name }}"
                         class="mx-auto max-h-[70vh] max-w-full rounded-(--radius-field) border border-(--color-border)">
                @else
                    <p class="text-sm text-(--color-ink-muted)">{{ __('documents::message.no_preview') }}</p>
                @endif
            </div>
        </section>

        {{-- ── বিবরণ ── --}}
        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
            <h2 class="border-b border-(--color-border) bg-(--color-section-head) px-4 py-3 font-semibold">
                {{ __('documents::section.details') }}
            </h2>

            <dl class="grid grid-cols-[minmax(0,10rem)_minmax(0,1fr)] gap-x-3 gap-y-2 p-4 text-sm">
                <dt class="text-(--color-ink-muted)">{{ __('documents::field.document_no') }}</dt>
                <dd class="num">{{ $document->document_no }}</dd>

                <dt class="text-(--color-ink-muted)">{{ __('documents::field.doc_type') }}</dt>
                <dd>{{ $choices->typeName($document->doc_type) }}</dd>

                <dt class="text-(--color-ink-muted)">{{ __('documents::field.folder') }}</dt>
                <dd>{{ $choices->folderName($document->folder) }}</dd>

                <dt class="text-(--color-ink-muted)">{{ __('documents::field.version') }}</dt>
                <dd>{{ $current ? 'v'.$current->label() : '—' }}</dd>

                <dt class="text-(--color-ink-muted)">{{ __('documents::field.status') }}</dt>
                <dd>@include('documents::partials.status-badge', ['status' => $document->status])</dd>

                <dt class="text-(--color-ink-muted)">{{ __('documents::field.confidentiality') }}</dt>
                <dd>@include('documents::partials.level-badge', ['level' => $document->confidentiality])</dd>

                <dt class="text-(--color-ink-muted)">{{ __('documents::field.owner') }}</dt>
                <dd>{{ $document->owner?->name ?? '—' }}</dd>

                <dt class="text-(--color-ink-muted)">{{ __('documents::field.branch') }}</dt>
                <dd>{{ $document->branch?->name() ?? __('documents::message.company_wide') }}</dd>

                <dt class="text-(--color-ink-muted)">{{ __('documents::field.department') }}</dt>
                <dd>{{ $document->department?->name() ?? '—' }}</dd>

                <dt class="text-(--color-ink-muted)">{{ __('documents::field.document_date') }}</dt>
                <dd>{{ $document->document_date ? DateFormat::format($document->document_date) : '—' }}</dd>

                <dt class="text-(--color-ink-muted)">{{ __('documents::field.expiry_date') }}</dt>
                <dd>@include('documents::partials.expiry', ['document' => $document])</dd>

                <dt class="text-(--color-ink-muted)">{{ __('documents::field.tags') }}</dt>
                <dd class="flex flex-wrap gap-1">
                    @forelse ($document->tagList() as $tag)
                        <x-ui.badge tone="info">{{ $tag }}</x-ui.badge>
                    @empty
                        —
                    @endforelse
                </dd>

                {{-- ⭐ বাড়তি ঘর (§২০) — প্রশাসনে বানানো, যেমন "লাইসেন্স নম্বর" --}}
                @foreach ($document->metadata as $meta)
                    @if ($meta->field)
                        <dt class="text-(--color-ink-muted)">{{ $meta->field->name() }}</dt>
                        <dd>{{ $meta->value }}</dd>
                    @endif
                @endforeach

                <dt class="text-(--color-ink-muted)">{{ __('documents::field.created_by') }}</dt>
                <dd>{{ $document->creator?->name ?? '—' }} · {{ DateFormat::formatWithTime($document->created_at) }}</dd>

                <dt class="text-(--color-ink-muted)">{{ __('documents::field.updated_at') }}</dt>
                <dd>{{ DateFormat::formatWithTime($document->updated_at) }}</dd>
            </dl>

            @if (filled($document->description))
                <p class="border-t border-(--color-border) px-4 py-3 text-sm whitespace-pre-line">{{ $document->description }}</p>
            @endif

            {{-- ⓘ আর্কাইভ, ফেরানো, মোছা — প্রতিটা নিজের চাবিতে; মোছা আর আর্কাইভ একবার জিজ্ঞেস করে --}}
            @if (auth()->user()->can('archive', $document) || auth()->user()->can('unarchive', $document) || auth()->user()->can('delete', $document))
                <div class="flex flex-wrap gap-2 border-t border-(--color-border) px-4 py-3">
                    @can('archive', $document)
                        <form method="POST" action="{{ route('documents.archive', $document) }}"
                              data-confirm="{{ __('documents::message.archive_confirm') }}">
                            @csrf
                            <x-ui.button type="submit">{{ __('documents::action.archive') }}</x-ui.button>
                        </form>
                    @endcan

                    @can('unarchive', $document)
                        <form method="POST" action="{{ route('documents.unarchive', $document) }}">
                            @csrf
                            <x-ui.button type="submit" tone="primary">{{ __('documents::action.unarchive') }}</x-ui.button>
                        </form>
                    @endcan

                    @can('delete', $document)
                        <form method="POST" action="{{ route('documents.destroy', $document) }}"
                              data-confirm="{{ __('documents::message.delete_confirm') }}">
                            @csrf
                            @method('DELETE')
                            <x-ui.button type="submit" tone="danger" icon="trash">{{ __('documents::action.delete') }}</x-ui.button>
                        </form>
                    @endcan
                </div>
            @endif
        </section>
    </div>

    {{-- ── ভার্সন ── --}}
    <section id="versions" data-boxed class="mt-4 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <h2 class="border-b border-(--color-border) bg-(--color-section-head) px-4 py-3 font-semibold">
            {{ __('documents::section.versions') }}
        </h2>

        <div class="overflow-x-auto">
            <table class="ui-list w-full border-collapse text-sm">
                <thead>
                    <tr>
                        <th class="text-start">{{ __('documents::field.version') }}</th>
                        <th class="text-start">{{ __('documents::field.file') }}</th>
                        <th class="text-end">{{ __('documents::field.size') }}</th>
                        <th class="text-start">{{ __('documents::field.file_hash') }}</th>
                        <th class="text-start">{{ __('documents::field.author') }}</th>
                        <th class="text-start">{{ __('documents::field.date') }}</th>
                        <th class="text-start">{{ __('documents::field.comment') }}</th>
                        <th class="text-end">{{ __('core.table.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($versions as $version)
                        @php($isCurrent = (int) $version->id === (int) $document->current_version_id)
                        <tr data-version="{{ $version->label() }}">
                            <td class="whitespace-nowrap font-semibold">
                                v{{ $version->label() }}
                                @if ($isCurrent)
                                    <x-ui.badge tone="success">★ {{ __('documents::message.current') }}</x-ui.badge>
                                @endif
                            </td>
                            <td class="max-w-[18rem] truncate">{{ $version->attachment?->original_name ?? '—' }}</td>
                            <td class="num text-end">{{ $version->attachment?->humanSize() ?? '—' }}</td>
                            {{-- ⓘ SHA-256-এর প্রথম ১২ অক্ষর; পুরোটা ছোঁয়ালে দেখা যায় --}}
                            <td class="num text-2xs" title="{{ $version->file_hash }}">{{ $version->file_hash ? substr($version->file_hash, 0, 12).'…' : '—' }}</td>
                            <td>{{ $version->author?->name ?? '—' }}</td>
                            <td class="whitespace-nowrap">{{ DateFormat::formatWithTime($version->created_at) }}</td>
                            <td>
                                {{ $version->comment ?? '' }}
                                @if ($version->restoredFrom)
                                    <span class="block text-2xs text-(--color-ink-muted)">
                                        {{ __('documents::message.restored_from', ['version' => 'v'.$version->restoredFrom->label()]) }}
                                    </span>
                                @endif
                            </td>
                            <td class="text-end">
                                <div class="flex flex-wrap justify-end gap-2">
                                    @can('download', $document)
                                        <a href="{{ route('documents.version.download', [$document, $version]) }}"
                                           class="text-(--color-link) hover:underline">{{ __('documents::action.download') }}</a>
                                    @endcan

                                    @if (! $isCurrent)
                                        @can('restoreVersion', $document)
                                            <form method="POST" action="{{ route('documents.version.restore', [$document, $version]) }}"
                                                  data-confirm="{{ __('documents::message.restore_confirm', ['version' => 'v'.$version->label()]) }}">
                                                @csrf
                                                <button type="submit" class="text-(--color-link) hover:underline">
                                                    {{ __('documents::action.restore_version') }}
                                                </button>
                                            </form>
                                        @endcan
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- ⭐ নতুন ভার্সন — আগের ফাইল ছোঁয়া হয় না (§৯); অনুমোদিত কাগজেও বদলের পথ এটাই --}}
        @can('addVersion', $document)
            <form method="POST" action="{{ route('documents.version.store', $document) }}" enctype="multipart/form-data"
                  x-data="{ busy: false }" @submit="busy ? $event.preventDefault() : (busy = true)"
                  class="grid gap-3 border-t border-(--color-border) px-4 py-3 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] lg:items-end">
                @csrf
                <div>
                    <label for="version-file" class="mb-1 block text-sm font-medium">{{ __('documents::action.new_version') }}</label>
                    <input type="file" id="version-file" name="file" required
                           class="block w-full text-sm file:me-2 file:rounded-(--radius-field)
                                  file:border file:border-(--color-border) file:bg-(--color-surface-app)
                                  file:px-3 file:py-1.5 file:text-sm">
                    <label class="mt-2 flex items-center gap-2 text-sm">
                        <input type="checkbox" name="major" value="1" class="size-4">
                        {{ __('documents::message.major_hint') }}
                    </label>
                </div>

                <x-ui.field name="comment" :label="__('documents::field.comment')" maxlength="500" />

                <x-ui.button type="submit" tone="primary" icon="attachment"
                             ::class="busy && 'pointer-events-none opacity-70'">
                    {{ __('documents::action.upload_version') }}
                </x-ui.button>
            </form>
        @endcan
    </section>

    {{-- ── ⭐ অনুমোদন (§১০) — অবস্থা, কোন স্তরে, কে কী বলেছেন; সই ইনবক্সে ── --}}
    <section id="approval" data-boxed class="mt-4 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <h2 class="border-b border-(--color-border) bg-(--color-section-head) px-4 py-3 font-semibold">
            {{ __('documents::section.approval') }}
        </h2>

        <div class="flex flex-wrap items-center gap-x-6 gap-y-2 px-4 py-3 text-sm">
            <span>@include('documents::partials.status-badge', ['status' => $document->status])</span>

            @if ($approval)
                <span>
                    <span class="text-(--color-ink-muted)">{{ __('documents::field.level') }}:</span>
                    <span class="num">{{ $approval->current_level }}</span>
                </span>
                @if ($approval->due_at && $approval->status === \App\Models\Approval::PENDING)
                    <span>
                        <span class="text-(--color-ink-muted)">{{ __('documents::field.due_date') }}:</span>
                        {{ DateFormat::formatWithTime($approval->due_at) }}
                    </span>
                @endif
                <a href="{{ route('approval.inbox.show', $approval->id) }}" class="text-(--color-link) hover:underline">
                    {{ __('documents::action.open_in_inbox') }}
                </a>
            @else
                <span class="text-(--color-ink-muted)">{{ __('documents::message.never_submitted') }}</span>
            @endif
        </div>

        @if ($approval && $approval->decisions->isNotEmpty())
            <ul class="border-t border-(--color-border) px-4 py-3 text-sm">
                @foreach ($approval->decisions as $decision)
                    <li data-decision="{{ $decision->decision }}">
                        <span class="num">{{ $decision->level }}</span> ·
                        {{ $decision->user?->name ?? '—' }} ·
                        {{ __('documents::catalog.decision.'.$decision->decision) }} ·
                        {{ DateFormat::formatWithTime($decision->decided_at) }}
                        @if (filled($decision->remarks)) — {{ $decision->remarks }} @endif
                    </li>
                @endforeach
            </ul>
        @endif

        {{-- ⓘ তিনটা কাজ, প্রতিটা নিজের চাবি আর অবস্থায় --}}
        <div class="flex flex-wrap items-end gap-2 border-t border-(--color-border) px-4 py-3">
            @can('submit', $document)
                @if (\App\Modules\Documents\Services\DocumentWorkflow::canBeSubmitted($document))
                    <form method="POST" action="{{ route('documents.submit', $document) }}" class="flex flex-wrap items-end gap-2">
                        @csrf
                        <x-ui.field name="note" :label="__('documents::field.submit_note')" maxlength="255" />
                        <x-ui.button type="submit" tone="primary" icon="check_circle">{{ __('documents::action.submit') }}</x-ui.button>
                    </form>
                @elseif ($approval && $approval->status === \App\Models\Approval::PENDING && (int) $approval->requested_by === (int) auth()->id())
                    <form method="POST" action="{{ route('documents.withdraw', $document) }}"
                          data-confirm="{{ __('documents::message.withdraw_confirm') }}">
                        @csrf
                        <x-ui.button type="submit">{{ __('documents::action.withdraw') }}</x-ui.button>
                    </form>
                @endif
            @endcan

            @can('publish', $document)
                <form method="POST" action="{{ route('documents.publish', $document) }}">
                    @csrf
                    <x-ui.button type="submit" tone="primary">{{ __('documents::action.publish') }}</x-ui.button>
                </form>
            @endcan
        </div>
    </section>

    {{-- ── ⭐ কাগজ-ধরে অধিকার (§১৩) — কে এই কাগজে কী পারেন; দেখা সবসময় চালু ── --}}
    @can('grant', $document)
        <section id="access" data-boxed class="mt-4 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
            <h2 class="border-b border-(--color-border) bg-(--color-section-head) px-4 py-3 font-semibold">
                {{ __('documents::section.access') }}
            </h2>

            <p class="px-4 pt-3 text-2xs text-(--color-ink-muted)">{{ __('documents::message.access_hint') }}</p>

            <div class="overflow-x-auto">
                <table class="ui-list w-full border-collapse text-sm">
                    <thead>
                        <tr>
                            <th class="text-start">{{ __('documents::field.grantee') }}</th>
                            @foreach (array_keys(\App\Modules\Documents\Models\DocumentGrant::ABILITIES) as $ability)
                                <th class="text-center">{{ __('documents::catalog.ability.'.$ability) }}</th>
                            @endforeach
                            <th class="text-end">{{ __('core.table.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($document->grants as $grant)
                            <tr data-grant="{{ $grant->grantee_type }}-{{ $grant->grantee_id }}">
                                <td>
                                    {{ $grant->granteeName() }}
                                    <span class="text-2xs text-(--color-ink-muted)">· {{ __('documents::catalog.grantee.'.$grant->grantee_type) }}</span>
                                </td>
                                @foreach (\App\Modules\Documents\Models\DocumentGrant::ABILITIES as $column)
                                    <td class="text-center">{{ $grant->{$column} ? '✓' : '—' }}</td>
                                @endforeach
                                <td class="text-end">
                                    <form method="POST" action="{{ route('documents.grant.destroy', [$document, $grant]) }}"
                                          data-confirm="{{ __('documents::message.access_remove_confirm') }}">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-(--color-danger) hover:underline">{{ __('documents::action.remove') }}</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="text-(--color-ink-muted)">{{ __('documents::message.access_none') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <form method="POST" action="{{ route('documents.grant.store', $document) }}"
                  x-data="{ type: 'user' }"
                  class="grid gap-3 border-t border-(--color-border) px-4 py-3 lg:grid-cols-[10rem_minmax(0,1fr)_minmax(0,2fr)_auto] lg:items-end">
                @csrf
                <x-ui.select name="grantee_type" :label="__('documents::field.grantee_type')"
                             :options="['user' => __('documents::catalog.grantee.user'), 'role' => __('documents::catalog.grantee.role')]"
                             selected="user" x-model="type" />

                <div x-show="type === 'user'">
                    <x-ui.select name="grantee_id" :label="__('documents::field.grantee')" :options="$people" placeholder="—"
                                 x-bind:disabled="type !== 'user'" />
                </div>
                <div x-show="type === 'role'" x-cloak>
                    <x-ui.select name="grantee_id" :label="__('documents::field.grantee')" :options="$roles" placeholder="—"
                                 x-bind:disabled="type !== 'role'" />
                </div>

                <fieldset class="flex flex-wrap gap-3 text-sm">
                    <legend class="mb-1 font-medium">{{ __('documents::field.abilities') }}</legend>
                    @foreach (array_keys(\App\Modules\Documents\Models\DocumentGrant::ABILITIES) as $ability)
                        @continue($ability === 'view')
                        <label class="flex items-center gap-1">
                            <input type="checkbox" name="abilities[]" value="{{ $ability }}" class="size-4">
                            {{ __('documents::catalog.ability.'.$ability) }}
                        </label>
                    @endforeach
                </fieldset>

                <x-ui.button type="submit" tone="primary">{{ __('documents::action.grant') }}</x-ui.button>
            </form>
        </section>
    @endcan

    {{-- ── অডিট ── --}}
    <section data-boxed class="mt-4 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <h2 class="border-b border-(--color-border) bg-(--color-section-head) px-4 py-3 font-semibold">
            {{ __('documents::section.audit') }}
        </h2>

        <div class="overflow-x-auto">
            <table class="ui-list w-full border-collapse text-sm">
                <thead>
                    <tr>
                        <th class="text-start">{{ __('documents::field.when') }}</th>
                        <th class="text-start">{{ __('documents::field.who') }}</th>
                        <th class="text-start">{{ __('documents::field.what') }}</th>
                        <th class="text-start">{{ __('documents::field.note') }}</th>
                        <th class="text-start">{{ __('documents::field.ip') }}</th>
                        <th class="text-start">{{ __('documents::field.device') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($trail as $row)
                        <tr data-audit="{{ $row->action }}">
                            <td class="whitespace-nowrap">{{ DateFormat::formatWithTime($row->created_at) }}</td>
                            <td>{{ $row->user?->name ?? '—' }}</td>
                            <td>{{ AuditTrail::actionInWords($row->action) }}</td>
                            <td>{{ $row->reason ?? '' }}</td>
                            <td class="num whitespace-nowrap">{{ $row->ip_address ?? '—' }}</td>
                            <td class="max-w-[16rem] truncate text-2xs text-(--color-ink-muted)" title="{{ $row->user_agent }}">{{ $row->user_agent ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
</x-layouts.app>
