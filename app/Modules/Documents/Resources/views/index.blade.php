{{--
    ডকুমেন্ট সেন্টার — আর একই পর্দায় "আমার ডকুমেন্ট" ও "সাম্প্রতিক" (§৪; ৮ অক্টোবর ২০২৬)।

    ⓘ তিনটা তালিকা একই ছাঁচ, একই কলাম — কেবল কোন সারিগুলো আসে তা আলাদা
    ([[DocumentFinder]])। ⚠️ আলাদা পর্দা বানালে একদিন একটায় কলাম যোগ হত, অন্যটায় নয়।

    ⓘ সেন্টারে মাথায় মালিকের ন'টা ফোল্ডার ট্যাব হয়ে বসে, প্রতিটার পাশে গোনা — একই দেয়াল আর
    গোপনীয়তার ভিতরে। কলাম: ক্রম · নম্বর · নাম · ধরন · ফোল্ডার · মালিক · ভার্সন · গোপনীয়তা ·
    অবস্থা · মেয়াদ · বদল (পরিকল্পনা §৪: Name, Type, Owner, Version, Status)।
--}}
@php
    use App\Modules\Documents\Models\Document;
    use App\Modules\Documents\Services\DocumentFinder;

    $columns = [
        [
            'key' => 'sl',
            'label' => __('core.table.serial'),
            'numeric' => true,
            'width' => '4rem',
            'render' => fn ($d, $i) => (string) (($documents->firstItem() ?? 1) + $i),
        ],
        [
            'key' => 'document_no',
            'label' => __('documents::field.document_no'),
            'width' => '9rem',
        ],
        [
            'key' => 'name',
            'label' => __('documents::field.name'),
            'width' => '22rem',
            'render' => fn ($d) => $d->name,
        ],
        [
            'key' => 'doc_type',
            'label' => __('documents::field.doc_type'),
            'width' => '8rem',
            'render' => fn ($d) => __('documents::catalog.type.'.$d->doc_type),
        ],
        [
            'key' => 'folder',
            'label' => __('documents::field.folder'),
            'width' => '9rem',
            'render' => fn ($d) => __('documents::catalog.folder.'.$d->folder),
        ],
        [
            'key' => 'owner_id',
            'label' => __('documents::field.owner'),
            'width' => '10rem',
            'render' => fn ($d) => $d->owner?->name ?? '—',
        ],
        [
            'key' => 'version',
            'label' => __('documents::field.version'),
            'width' => '5rem',
            'render' => fn ($d) => $d->currentVersion ? 'v'.$d->currentVersion->label() : '—',
        ],
        [
            'key' => 'confidentiality',
            'label' => __('documents::field.confidentiality'),
            'width' => '8rem',
            'render' => fn ($d) => view('documents::partials.level-badge', ['level' => $d->confidentiality]),
        ],
        [
            'key' => 'status',
            'label' => __('documents::field.status'),
            'width' => '7rem',
            'render' => fn ($d) => view('documents::partials.status-badge', ['status' => $d->status]),
        ],
        [
            'key' => 'expiry_date',
            'label' => __('documents::field.expiry_date'),
            'width' => '9rem',
            'render' => fn ($d) => view('documents::partials.expiry', ['document' => $d]),
        ],
        [
            'key' => 'updated_at',
            'label' => __('documents::field.updated_at'),
            'width' => '8rem',
            'render' => fn ($d) => \App\Core\Support\DateFormat::format($d->updated_at),
        ],
    ];

    /* ⓘ ফোল্ডারের ট্যাব — কেবল সেন্টারে; "সব" প্রথমে, তারপর মালিকের ক্রমে */
    $tabs = [];

    if ($view === DocumentFinder::CENTER) {
        $tabs[] = [
            'key' => 'all',
            'label' => __('documents::message.all_folders'),
            'url' => request()->fullUrlWithQuery(['folder' => null, 'page' => null]),
            'count' => array_sum($folderCounts),
            'active' => blank($filters['folder']),
        ];

        foreach ($folders as $key => $label) {
            $tabs[] = [
                'key' => $key,
                'label' => $label,
                'url' => request()->fullUrlWithQuery(['folder' => $key, 'page' => null]),
                'count' => $folderCounts[$key] ?? 0,
                'active' => $filters['folder'] === $key,
            ];
        }
    }
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $heading }}</x-slot:title>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    <x-ui.list-tabs :tabs="$tabs" :label="__('documents::field.folder')" />

    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form method="GET" class="contents">
            {{-- ⓘ বাছা ফোল্ডার ট্যাবের, ছাঁকনির নয় — ফর্ম জমা দিলেও ট্যাবটা থাকে --}}
            @if (filled($filters['folder']))
                <input type="hidden" name="folder" value="{{ $filters['folder'] }}">
            @endif

            <x-ui.toolbar :title="$heading"
                :count="trans_choice('documents::message.count', $documents->total(), ['count' => $documents->total()])"
                :columns="$columns"
                :search-placeholder="__('documents::message.search_placeholder')"
                :sort="$sortOptions"
                :quiet="['folder']">
                <x-slot:actions>
                    @can('create', Document::class)
                        <x-ui.button tone="primary" icon="plus"
                                     :href="route('documents.create', filled($filters['folder']) ? ['folder' => $filters['folder']] : [])">
                            {{ __('documents::action.upload') }}
                        </x-ui.button>
                    @endcan
                </x-slot:actions>

                <x-ui.select name="doc_type" :label="__('documents::field.doc_type')"
                             :options="$types" :selected="$filters['doc_type']" placeholder="—" />

                <x-ui.select name="department_id" :label="__('documents::field.department')"
                             :options="$departments" :selected="$filters['department_id']" placeholder="—" />

                <x-ui.select name="confidentiality" :label="__('documents::field.confidentiality')"
                             :options="$levels" :selected="$filters['confidentiality']" placeholder="—" />

                {{-- ⭐ মেয়াদের ছাঁকনি (§১২) — শেষ হয়ে গেছে, বা ৭/৩০/৯০ দিনের মধ্যে শেষ হবে --}}
                <x-ui.select name="expiry" :label="__('documents::field.expiry_date')"
                             :options="collect(\App\Modules\Documents\Support\DocumentCatalog::EXPIRY_WINDOWS)
                                 ->mapWithKeys(fn ($w) => [$w => __('documents::catalog.expiry.'.$w)])->all()"
                             :selected="$filters['expiry']" placeholder="—" />

                {{-- ⓘ আর্কাইভ করা কাগজ সেন্টারে দেখা যায় না — এই টিকে কেবল সেগুলোই, ফেরানোর জন্য --}}
                <label class="flex min-h-(--spacing-touch) items-center gap-2 text-sm">
                    <input type="checkbox" name="archived" value="1" @checked($filters['archived']) class="size-4">
                    {{ __('documents::action.show_archived') }}
                </label>
            </x-ui.toolbar>
        </form>

        <x-ui.table
            :view-url="fn ($d) => route('documents.show', $d)"
            :empty="filled($filters['q']) ? __('core.empty.no_results') : __('documents::message.none_yet')"
            :rows="$documents"
            :compact="request()->boolean('compact')"
            :columns="$columns" />

        <x-ui.pager :rows="$documents" />
        <x-ui.list-totals :rows="$documents" :columns="$columns" />
    </div>
</x-layouts.app>
