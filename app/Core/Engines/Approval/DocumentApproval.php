<?php

declare(strict_types=1);

namespace App\Core\Engines\Approval;

use App\Models\Approval;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * একটা কাগজ নিশ্চিত করার আগে অনুমোদন লাগে কি না।
 *
 * ── কেন এটা কোরে, প্রতিটা মডিউলে নয় ─────────────────────────────────
 * চেকলিস্টের §২-এর নিয়মটা এক লাইনের: *"প্রতিটা মডিউল কেবল নিজের Request
 * পাঠাবে; Rule · Matrix · UI এখানেই একবার।"* প্রশ্নটা — "অনুমোদন কি
 * আটকাচ্ছে?" — প্রতিটা মডিউলে হুবহু এক, আর ছয় জায়গায় ছয়বার লিখলে
 * একদিন একটা কপি অন্যদের থেকে আলাদা হয়ে যেত। ⚠️ সবচেয়ে বাজে দিকটা হলো
 * সেই আলাদা হওয়াটা **নীরব**: একটা মডিউল অনুমোদন চাওয়া বন্ধ করে দিলে
 * কিছুই ভাঙে না, কেবল সইটা আর চাওয়া হয় না।
 *
 * ⓘ Accounts-এর [[VoucherApproval]] এর আগে লেখা, আর সে ভাউচারের নিজের
 * ধরন-নির্দিষ্ট কাজও করে। ওটা এখানে টেনে আনা হয়নি — চলন্ত জিনিস ভাঙার
 * তাড়া নেই। নতুন যা যুক্ত হচ্ছে সবই এই পথে আসে।
 *
 * ── তিনটা অবস্থা, আর তিনটাই দরকারি ──────────────────────────────────
 *   `null` ফেরা      → এগিয়ে যাও (ছক নেই · অঙ্ক সীমার নিচে · অনুমোদন হয়ে গেছে)
 *   PENDING ফেরা     → কেউ সইয়ের অপেক্ষায়
 *   REJECTED ফেরা    → "না" বলা হয়েছে, আর কাগজটা তারপর বদলায়নি
 *
 * ⚠️ **ডিফল্ট পথটাই সবচেয়ে জরুরি।** কোনো কোম্পানি ছক না বসালে
 * `ApprovalEngine::request()` `null` ফেরায়, আর সব **আজকের মতোই** চলে।
 * এটা সুবিধা নয়, শর্ত: এই পাহারা বসানোর পর যেন এমন একটাও প্রতিষ্ঠান না
 * থাকে যেখানে ক্রয়াদেশ নিশ্চিত করা বন্ধ হয়ে গেল।
 */
final class DocumentApproval
{
    public function __construct(private readonly ApprovalEngine $approvals) {}

    /**
     * কাগজটা আটকাচ্ছে এমন কিছু আছে কি?
     *
     * @param  string|null  $amount  ছকের সীমা এর সাথেই মেলানো হয়; না জানা
     *                               থাকলে `null`, আর তখন সীমা যা-ই হোক
     *                               অনুমোদন লাগে (`ApprovalFlow::appliesTo`)
     */
    public function stopping(
        Model $document,
        string $module,
        string $action,
        ?string $amount = null,
        ?string $reason = null,
    ): ?Approval {
        $latest = $this->approvals->latestFor($document, $action);

        /*
         * অনুমোদন পাওয়া গেছে — এগোও, আর **নতুন অনুরোধ কোরো না**।
         *
         * ⚠️ `ApprovalEngine::approve()` নিজে কাজটা এগোয় না, কেবল
         * অনুরোধটাকে `approved` করে। অর্থাৎ মানুষটাকে ফিরে এসে আবার
         * "নিশ্চিত" চাপতে হয়। ওই দ্বিতীয় চাপে আবার `request()` ডাকলে
         * সে **পুরনো অনুরোধটা pending নয় বলে খুঁজে পেত না** আর একটা
         * নতুন অনুরোধ বানাত — কাগজটা তখন অসীম লুপে পড়ত: অনুমোদন পেলেই
         * আবার নতুন অনুমোদন লাগত।
         */
        if ($latest?->status === Approval::APPROVED) {
            return null;
        }

        /*
         * প্রত্যাখ্যাত — আর তারপর কাগজটা বদলায়নি।
         *
         * নীরবে আবার অনুরোধ পাঠালে **প্রত্যাখ্যানের কোনো মানে থাকত না**:
         * যিনি "না" শুনলেন তিনি আবার বোতামটা চেপে আবার পাঠাতেন। আবার
         * চাওয়ার পথ একটাই — কাগজটা বদলানো, অর্থাৎ "না"-র কারণটা মেটানো।
         */
        if ($latest?->status === Approval::REJECTED && ! $this->changedSince($document, $latest)) {
            return $latest;
        }

        return $this->approvals->request(
            document: $document,
            module: $module,
            action: $action,
            amount: $amount,
            reason: $reason,

            /*
             * ⚠️ কে চাইছেন — `auth()->id()`-র উপর ছেড়ে দেওয়া যায় না।
             *
             * `approvals.requested_by` null নিতে পারে না, আর কমান্ড লাইন ·
             * সিডার · কিউ ওয়ার্কার · ইমপোর্ট — কোথাওই লগইন করা কেউ নেই।
             * ওগুলোতেও কাগজ নিশ্চিত হয়, আর তখন সৎ উত্তর একটাই: **যিনি
             * কাগজটা লিখেছিলেন**।
             */
            userId: auth()->id() ?? $this->authorOf($document),
        );
    }

    /**
     * একই প্রশ্ন, কিন্তু উত্তরটা যখন পর্দায় বলতে হবে।
     *
     * ── কেন ব্যতিক্রম, ফেরত মান নয় ──────────────────────────────────
     * `confirm()` ধরনের মেথডগুলো ইতিমধ্যেই নিয়ম ভাঙলে
     * `ValidationException` ছোঁড়ে ("কেবল খসড়া নিশ্চিত হয়"), আর পর্দাগুলো
     * সেটা দেখাতে জানে। অনুমোদনের বার্তাটা অন্য পথে পাঠালে প্রতিটা
     * কন্ট্রোলারে নতুন একটা শাখা লাগত, আর যে কন্ট্রোলার ভুলে যেত সেখানে
     * কাগজটা নীরবে **অনুমোদন ছাড়াই** থেমে থাকত — কোনো বার্তা ছাড়া।
     *
     * @param  string  $field  ফর্মের কোন ঘরের নিচে বার্তাটা বসবে
     */
    public function assertClear(
        Model $document,
        string $module,
        string $action,
        string $field,
        ?string $amount = null,
        ?string $reason = null,
    ): void {
        $stopping = $this->stopping($document, $module, $action, $amount, $reason);

        if ($stopping === null) {
            return;
        }

        throw ValidationException::withMessages([
            $field => $stopping->status === Approval::REJECTED
                ? __('core.approval.rejected', [
                    'reason' => (string) $stopping->decisions()->latest('id')->value('remarks'),
                ])
                : __('core.approval.awaiting'),
        ]);
    }

    /**
     * সিদ্ধান্তের পর কাগজটা বদলেছে কি?
     *
     * `decided_at` না থাকলে ধরে নেওয়া হয় বদলায়নি — অর্থাৎ **আটকে থাকাই
     * ডিফল্ট**। উল্টোটা ধরলে একটা অসম্পূর্ণ সারি প্রত্যাখ্যানটাকে নীরবে
     * অকেজো করে দিত।
     */
    private function changedSince(Model $document, Approval $approval): bool
    {
        $changed = $document->getAttribute('updated_at');

        if ($approval->decided_at === null || $changed === null) {
            return false;
        }

        return $changed->greaterThan($approval->decided_at);
    }

    /**
     * কাগজটা কে লিখেছিলেন।
     *
     * প্রতিটা কাগজে `created_by` থাকে না (কিছু কাগজ যন্ত্রের তৈরি), তাই
     * না পেলে `null` — আর তখন ইঞ্জিন নিজেই ব্যর্থ হবে, নীরবে ভুল
     * কারো নামে বসিয়ে দেওয়ার চেয়ে যা ভালো।
     */
    private function authorOf(Model $document): ?int
    {
        $author = $document->getAttribute('created_by');

        return $author === null ? null : (int) $author;
    }
}
