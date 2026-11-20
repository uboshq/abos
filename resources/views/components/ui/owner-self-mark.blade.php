@props(['paper' => null, 'approval' => null])
@php
    /*
     * ⭐ "নিজে লিখে নিজে অনুমোদিত (মালিক)" — স্থায়ী দাগ (মালিক, ২০ নভেম্বর ২০২৬, "ok")।
     *
     * ⓘ লেখক ≠ অনুমোদনকারী কর্মীদের নিয়ম; মালিককে আটকানো হয় না, তবে যে কাগজ তিনি নিজে লিখে নিজে অনুমোদন বা পোস্ট করলেন,
     * তার গায়ে এই ছোট ব্যাজ চিরকাল থাকে। হিসাবটা বদলানো-যায়-না এমন সারি থেকে ([[OwnerSelfApproval]]) — কাগজের পাতার মাথায়
     * ([[ui.page-header]], নিজে থেকে) আর সইয়ের ইতিহাসের অবস্থার ঘরে বসে।
     * ⚠️ দাগ না থাকলে একটা ফাঁকা অক্ষরও নয় — ফাইলে নির্দেশগুলোর মাঝে ফাঁকা লাইন নেই, কারণ পাতার মাথার শিরোনামের ভিতরে বসে
     * (SAP/D365 রূপের একটা পিক্সেলও বদলাবে না)।
     */
    $marks = app(\App\Core\Engines\Approval\OwnerSelfApproval::class);
    $marked = false;

    try {
        $marked = $approval instanceof \App\Models\Approval
            ? $marks->ofApproval($approval)
            : ($paper instanceof \Illuminate\Database\Eloquent\Model && $marks->ofPaper($paper));
    } catch (\Throwable $e) {
        // ⓘ দাগ পড়া না গেলে পাতা ভাঙে না — খবরটা লগে যায়
        report($e);
    }
@endphp
@if ($marked)
<span {{ $attributes->merge(['data-owner-self-mark' => true]) }} title="{{ __('core.owner_self.mark_hint') }}"><x-ui.badge tone="info">{{ __('core.owner_self.mark') }}</x-ui.badge></span>
@endif
