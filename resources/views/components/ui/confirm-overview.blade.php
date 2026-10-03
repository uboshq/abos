{{--
    ⭐ নিশ্চিতের আগে সারাংশের পপ-আপ — খোলস (মালিক, ৪ অক্টোবর ২০২৬; [[confirm-overview.js]], [[ConfirmOverview]])।
    ⓘ যে পাতায় "নিশ্চিত" আছে, সেখানে একবার বসান; ফর্মে `data-confirm-overview` (সারাংশের ঠিকানা), আর "নিশ্চিত"
    বোতামে `data-overview-trigger`। ভিতরের অংশ সার্ভার আঁকে ([[ui.confirm-overview-body]])।
    বোতাম: নিশ্চিত করুন · খসড়া রাখুন (ধূসর, মালিক ২৭ সেপ্টেম্বর) · ফিরে যান। `draft` মিথ্যা হলে খসড়ার বোতাম নেই।
--}}
@props(['draft' => true])

<dialog data-confirm-overview-dialog
        data-loading="{{ __('overview.loading') }}"
        data-failed="{{ __('overview.failed') }}"
        class="w-full max-w-lg rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-0">
    <div class="grid gap-3 p-4">
        <div data-confirm-overview-body class="grid gap-2 text-sm"></div>

        <div class="grid gap-2">
            <x-ui.button type="button" tone="primary" data-overview-confirm>{{ __('overview.confirm') }}</x-ui.button>
            @if ($draft)
                <x-ui.button type="button" tone="neutral" data-overview-draft>{{ __('overview.draft') }}</x-ui.button>
            @endif
            <x-ui.button type="button" data-overview-back>{{ __('overview.back') }}</x-ui.button>
        </div>
    </div>
</dialog>
