{{--
    মাস বন্ধ করা ও খোলা।

    পাতাটা মাসের তালিকা দেখায়, নতুন আগে — কারণ যে মাসটা এইমাত্র শেষ
    হয়েছে, সেটাই বন্ধ করার কথা। পুরনোগুলো নিচে, আর সেগুলো সাধারণত
    আগেই বন্ধ।

    বন্ধ করা এক ক্লিক, খোলা নয়: খুলতে কারণ লিখতে হয়, আর সেটাই ছয় মাস
    পরে নিরীক্ষকের প্রশ্নের একমাত্র উত্তর।
--}}
@php
    /*
        কলাম ধরে — `x-ui.table` স্লট পড়ে না, সারি আসে :rows থেকে।

        সারিগুলো মডেল নয়, একটা অ্যারে (`$month['label']` ধাঁচে), তাই
        প্রতিটা কলামেই `render` লাগে — কম্পোনেন্টের ডিফল্ট পাঠ
        অবজেক্টের বৈশিষ্ট্য খোঁজে।
    */
    $columns = [
        [
            'key' => 'label',
            'label' => __('accounts::field.month'),
            'render' => fn ($m) => $m['label'],
        ],
        [
            'key' => 'state',
            'label' => __('accounts::field.state'),
            'width' => '8rem',
            'render' => fn ($m) => view('accounts::period.partials.state', ['month' => $m]),
        ],
        [
            'key' => 'reason',
            'label' => __('accounts::field.reason'),
            'render' => fn ($m) => $m['lock']?->reason ?: '—',
        ],
        [
            'key' => 'actions',
            'label' => __('core.table.actions'),
            'render' => fn ($m) => view('accounts::period.partials.actions', ['month' => $m]),
        ],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('accounts::menu.periods') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('accounts::menu.periods')"
                          :subtitle="__('accounts::message.period_note')" />
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

    @if ($months === [])
        <x-ui.empty-state :message="__('accounts::message.no_current_year')" />
    @else
    <x-ui.table :rows="$months"
                :columns="$columns"
                :empty="__('core.empty.no_results')" />
    @endif
</x-layouts.app>
