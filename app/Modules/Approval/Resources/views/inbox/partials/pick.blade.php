{{--
    একটা সারি বাছাই করার ঘর।

    ── ⓘ কেন একটা আলাদা ফাইল ───────────────────────────────────────────
    পর্দার কলামের ভেতরে HTML লিখলে লেবেলটা বাদ পড়ত, আর স্ক্রিন-রিডারে
    পঞ্চাশটা নামহীন চেকবক্স শোনা যেত — সবগুলো "checkbox", কোনটা কোন
    কাগজ তা না বলে।

    ⚠️ `ids[]` নামটা [[ApprovalInboxController::bulkApprove()]] পড়ে, আর
    "সব বাছাই" ঘরটাও এই নাম ধরেই খোঁজে।
--}}
<label class="flex min-h-(--spacing-touch) items-center justify-center">
    <input type="checkbox" name="ids[]" value="{{ $approval->id }}" class="size-4">
    <span class="sr-only">{{ __('approval::field.pick') }} #{{ $approval->id }}</span>
</label>
