{{--
    যে কাগজগুলো এখনো বেরোয়নি।

    ── কেন এই পর্দাটা "ছাপাও" বোতাম দেয় না ─────────────────────────────
    সার্ভার নিজে কাগজ বের করতে পারে না — ছাপা হয় ব্রাউজারে, ব্যবহারকারীর
    প্রিন্টারে। বোতাম বসালে সেটা মিথ্যা বলত: চাপলে সারিটা "ছাপা হয়েছে"
    হয়ে যেত, আর কাগজ বেরোত না।

    তাই প্রতিটা সারি ছাপার পাতাটার দিকে একটা লিংক। কাগজ সত্যিই বেরোলে
    ওই পাতাটাই গোনাটা বাড়ায়, প্রথমবারের মতোই।
--}}
@php
    /* কলাম ধরে — `x-ui.table` স্লট পড়ে না, সারি আসে :rows থেকে। */
    $columns = [
        [
            'key' => 'document_no',
            'label' => __('core.table.document'),
            'render' => fn ($j) => $j->document_no ?: '—',
        ],
        [
            'key' => 'paper',
            'label' => __('sales::field.paper'),
            'width' => '7rem',
        ],
        [
            'key' => 'status',
            'label' => __('sales::field.status'),
            'width' => '9rem',
            'render' => fn ($j) => view('sales::print_queue.partials.status', ['job' => $j]),
        ],
        /*
            ব্যর্থতার কারণটা দেখানো হয়, লুকানো হয় না। "কাগজ ফুরিয়েছে"
            আর "প্রিন্টার বন্ধ" দুইটা আলাদা কাজ চায়, আর কর্মী সেটা
            জানলে দ্বিতীয়বার একই ভুল করেন না।
        */
        [
            'key' => 'failure',
            'label' => __('sales::field.print_failure'),
            'render' => fn ($j) => $j->failure ?: '—',
        ],
        [
            'key' => 'actions',
            'label' => '',
            'render' => fn ($j) => view('sales::print_queue.partials.actions', ['job' => $j]),
        ],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('sales::menu.print_queue') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('sales::menu.print_queue')"
                          :subtitle="__('sales::message.print_queue_note')" />
    </x-slot:header>

    @if (session('status'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('status') }}
        </div>
    @endif

    @if ($jobs->isEmpty())
        {{--
            খালি থাকাই স্বাভাবিক — এটা রোজকার কাজের পর্দা নয়, প্রিন্টার
            বিগড়ানোর দিনের। তাই বার্তাটা "কিছু নেই" নয়, "সব বেরিয়ে গেছে"।
        --}}
        <x-ui.empty-state icon="printer" :message="__('sales::message.print_queue_empty')" />
    @else
        <x-ui.table :rows="$jobs"
                    :columns="$columns"
                    :empty="__('core.empty.no_results')" />

    @endif
</x-layouts.app>
