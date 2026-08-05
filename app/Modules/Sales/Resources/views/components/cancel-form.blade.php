{{--
    বাতিলের ফর্ম — কারণ ছাড়া বাতিল হয় না।

    কারণটা বাধ্যতামূলক, কারণ ছয় মাস পর নিরীক্ষায় "এই বিলটা বাতিল কেন
    হয়েছিল" প্রশ্নের উত্তর কারও মনে থাকে না।
--}}
<details class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
    <summary class="cursor-pointer text-sm font-medium">{{ __('sales::action.cancel_document') }}</summary>

    <form method="POST" action="{{ $action }}" class="mt-3 space-y-3">
        @csrf
        <x-ui.field name="reason" :label="__('sales::message.cancel_reason')" required />
        <x-ui.button type="submit" tone="danger">{{ __('sales::action.cancel_document') }}</x-ui.button>
    </form>
</details>
