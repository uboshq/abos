{{--
    আমার অনুরোধ — নতুন আগে।

    এখানে যিনি আসেন তিনি জানতে চান "আমারটার কী হলো", আর সেটা সাধারণত
    সবচেয়ে শেষ অনুরোধটা।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('approval::menu.mine') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header
            :title="__('approval::menu.mine')"
            :subtitle="trans_choice('core.count.records', $approvals->total(), ['count' => $approvals->total()])" />
    </x-slot:header>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    {{-- ⛔ কারণগুলো হিসাব করা হয় **ছকের আগে**, অ্যাট্রিবিউটের ভিতরে নয়।

         ── ⚠️ একটা নীরব ভুল, ২৪ সেপ্টেম্বর ২০২৬ ─────────────────
         ⓘ এখানে একটা বহু-লাইনের `function ($a) { … }` বসানো হয়েছিল
         কলামের অ্যাট্রিবিউটে। ⛔ ব্লেডের কম্পোনেন্ট-ট্যাগ পার্সার
         অ্যাট্রিবিউটে `{` সামলাতে পারে না — সে ট্যাগটা
         **কম্পাইলই করে না**, আর গোটা `<x-ui.table …>` কাঁচা লেখা
         হয়ে পাতায় ছাপা হয়।

         ⚠️ আর ভুলটা সম্পূর্ণ নীরব: কোনো ব্যতিক্রম নেই, কম্পাইল
         সবুজ (আউটপুটটা কেবল লেখা), পাতা ২০০ দেয়, শিরোনামের
         গণনা পর্যন্ত ঠিক থাকে — শুধু **ছকটা নেই**।

         ⓘ তাই নিয়ম: অ্যাট্রিবিউটে কেবল **এক লাইনের তির** (`fn () =>`),
         আর যা বড়, সেটা আগেই হিসাব করা। ⛔ পাহারা:
         [[NoBladeTagHidesABraceTest]]। --}}
    @php
        $why = [];

        foreach ($approvals as $row) {
            $last = $row->decisions->last();

            if ($last?->decision !== \App\Models\ApprovalDecision::REJECTED) {
                continue;
            }

            $text = $last->reason_code === null
                ? __('approval::reason.unstated')
                : __('approval::reason.'.$last->reason_code);

            $why[$row->id] = $last->reason_code === 'document'
                ? $text.' · '.__('approval::message.fix_and_resend')
                : $text;
        }
    @endphp

    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <x-ui.table
            :empty="__('approval::message.no_requests')"
            :rows="$approvals"
            :compact="request()->boolean('compact')"
            :columns="[
                ['key' => 'requested_at', 'label' => __('approval::field.requested_at'), 'width' => '11rem',
                 'render' => fn ($a) => $a->requested_at?->format('d M Y, H:i')],
                ['key' => 'module', 'label' => __('approval::field.action'),
                 'render' => fn ($a) => view('approval::inbox.partials.what', ['approval' => $a, 'labels' => $labels])],
                ['key' => 'amount', 'label' => __('approval::field.amount'), 'numeric' => true, 'width' => '9rem',
                 'render' => fn ($a) => $a->amount === null ? '—' : \App\Core\Support\Money::format($a->amount)],
                ['key' => 'status', 'label' => __('approval::field.status'), 'width' => '8rem',
                 'render' => fn ($a) => view('approval::inbox.partials.status', ['approval' => $a])],

                ['key' => 'why', 'label' => __('approval::field.reason_code'),
                 'render' => fn ($a) => $why[$a->id] ?? '—'],
                ['key' => 'open', 'label' => '', 'width' => '7rem',
                 'render' => fn ($a) => view('approval::inbox.partials.open', ['approval' => $a])],
            ]" />

        <x-ui.pager :rows="$approvals" />
    </div>
</x-layouts.app>
