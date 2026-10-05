{{--
    ⭐ পোস্ট হওয়া ভাউচার সম্পাদনার কারণ — বাধ্যতামূলক (মালিকের আদেশ, ৫ অক্টোবর ২০২৬; [[VoucherController::revise()]])।

    ⓘ কেবল সংশোধনের পাতায় আঁকা হয় (`$revising`); খসড়ার সাধারণ সম্পাদনায় নয়। কারণটা সংশোধনের ইতিহাসে থাকে, আর আগের
    দাখিলার উল্টো সারির বিবরণেও ([[RevisionKeeper]])।
--}}
@if ($revising ?? false)
    <section data-boxed data-revision-reason
             class="rounded-(--radius-card) border border-(--color-warning) bg-(--color-badge-warning-bg) p-4">
        <p class="text-sm text-(--color-badge-warning-ink)">{{ __('accounts::revision.note') }}</p>

        <label class="mt-3 flex flex-col gap-1 text-sm">
            <span class="font-medium">{{ __('accounts::revision.reason') }}</span>
            <textarea name="revision_reason" rows="2" required minlength="3" maxlength="500"
                      class="rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-3 py-2">{{ old('revision_reason') }}</textarea>
            <span class="text-2xs text-(--color-ink-muted)">{{ __('accounts::revision.reason_hint') }}</span>
        </label>
    </section>
@endif
