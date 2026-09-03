{{--
    আমার সিদ্ধান্তের অপেক্ষায়।

    পুরনোটা উপরে — যেটা সবচেয়ে বেশিক্ষণ ঝুলে আছে সেটাই সবচেয়ে বেশি
    কাউকে আটকে রেখেছে। নতুনটা উপরে রাখলে পুরনো অনুরোধগুলো নিচে চাপা
    পড়ত, আর ঠিক ওগুলোই মানুষ ভুলে যায়।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('approval::menu.inbox') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header
            :title="__('approval::menu.inbox')"
            :subtitle="trans_choice('core.count.records', $approvals->count(), ['count' => $approvals->count()])" />
    </x-slot:header>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    {{--
        মডিউল ধরে ছাঁকনি — §২.২।

        ── কেন সংখ্যাটা চিপের গায়েই ────────────────────────────────────
        "ক্রয়" লেখা একটা চিপ চেপে খালি তালিকা পাওয়ার চেয়ে খারাপ কিছু
        নেই। সংখ্যাটা আগে থেকে দেখা গেলে মানুষ জানেন কোথায় কাজ আছে, আর
        যেখানে কিছু নেই সেই চিপটা দেখানোই হয় না।

        ── একটার বেশি মডিউল না থাকলে সারিটাই থাকে না ────────────────────
        একটা মাত্র বিকল্পের ছাঁকনি ছাঁকে না, শুধু জায়গা নেয় — আর নতুন
        প্রতিষ্ঠানে শুরুর দিনগুলোতে ঠিক তা-ই হত।
    --}}
    @if (count($modules) > 1)
        <div class="mb-3 flex flex-wrap items-center gap-2" role="group"
             aria-label="{{ __('approval::field.module') }}">
            @php
                $chip = 'inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-medium
                         transition-colors hover:bg-(--color-surface-hover)';
                $on = 'border-(--color-border) bg-(--color-surface-selected) text-(--color-ink)';
                $off = 'border-(--color-border) bg-(--color-surface-card) text-(--color-ink-body)';
            @endphp

            <a href="{{ route('approval.inbox.index') }}"
               @class([$chip, $selected === '' ? $on : $off])
               @if ($selected === '') aria-current="true" @endif>
                {{ __('approval::field.all_modules') }}
                <span class="text-(--color-ink-muted)">{{ $total }}</span>
            </a>

            @foreach ($modules as $code => $one)
                <a href="{{ route('approval.inbox.index', ['module' => $code]) }}"
                   @class([$chip, $selected === $code ? $on : $off])
                   @if ($selected === $code) aria-current="true" @endif>
                    {{ $one['label'] }}
                    <span class="text-(--color-ink-muted)">{{ $one['count'] }}</span>
                </a>
            @endforeach
        </div>
    @endif

    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <x-ui.table
            :empty="__('approval::message.nothing_waiting')"
            :rows="$approvals"
            :compact="request()->boolean('compact')"
            :columns="[
                ['key' => 'requested_at', 'label' => __('approval::field.requested_at'), 'width' => '11rem',
                 'render' => fn ($a) => $a->requested_at?->format('d M Y, H:i')],
                ['key' => 'module', 'label' => __('approval::field.action'),
                 'render' => fn ($a) => view('approval::inbox.partials.what', ['approval' => $a, 'labels' => $labels])],
                ['key' => 'requested_by', 'label' => __('approval::field.requested_by'), 'width' => '11rem',
                 'render' => fn ($a) => $a->requester?->name],
                ['key' => 'amount', 'label' => __('approval::field.amount'), 'numeric' => true, 'width' => '9rem',
                 'render' => fn ($a) => $a->amount === null ? '—' : \App\Core\Support\Money::format($a->amount)],
                ['key' => 'open', 'label' => '', 'width' => '7rem',
                 'render' => fn ($a) => view('approval::inbox.partials.open', ['approval' => $a])],
            ]" />
    </div>
</x-layouts.app>
