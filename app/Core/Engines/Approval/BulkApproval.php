<?php

declare(strict_types=1);

namespace App\Core\Engines\Approval;

use App\Core\Module\ModuleRegistry;
use App\Models\Approval;
use App\Models\User;

/**
 * একসাথে অনেকগুলো সই — কিন্তু টাকা নড়ার কাজে নয়।
 *
 * ── ⭐ মালিকের সিদ্ধান্ত, ২৪ সেপ্টেম্বর ২০২৬ ─────────────────────────
 * *"টাকা নড়ার কাজে নিষিদ্ধ"* — পরিশোধ, উত্তোলন, টাকা স্থানান্তর,
 * আদায়, বছর বন্ধ। ⓘ ওগুলো একটা একটা করে দেখে সই দিতে হবে।
 *
 * ── ⚠️ কেন এই সীমাটা আসল ───────────────────────────────────────────
 * ⓘ bulk-এর গোটা সুবিধাটাই হলো **না দেখে সই দেওয়া** — পনেরোটা টিক
 * দিয়ে একটা বোতাম। ⛔ ছোট কাগজে সেটা সময় বাঁচায়; টাকা বেরিয়ে যাওয়ার
 * কাগজে সেটাই বিপদ, কারণ ভুলটা ফেরানো যায় না।
 *
 * ── ⛔ বোতাম লুকানো নিরাপত্তা নয় ─────────────────────────────────────
 * ⚠️ পর্দায় বোতামটা না দেখানো যথেষ্ট নয় — কেউ সরাসরি POST করলেও
 * আটকাতে হবে। ⓘ তাই নিয়মটা এখানে, কন্ট্রোলারে নয়।
 */
final class BulkApproval
{
    public function __construct(
        private readonly ApprovalEngine $engine,
        private readonly ModuleRegistry $modules,
    ) {}

    /**
     * ⭐ যেগুলো পারা যায়, সেগুলো — আর বাকিগুলোর কারণ।
     *
     * ── ⚠️ গোটা কাজ ব্যর্থ হয় না ────────────────────────────────────
     * ⓘ পনেরোটার মধ্যে একটায় অনুমতি না থাকলে চৌদ্দটা আটকে দেওয়া মানে
     * ব্যবহারকারীকে একটা একটা করে খুঁজে বের করতে বলা। ⛔ তখন তিনি
     * bulk ব্যবহারই বন্ধ করে দেন।
     *
     * @param  list<int>  $ids
     * @return array{done: int, skipped: array<string, int>}
     */
    public function approve(array $ids, User $user, ?string $remarks = null): array
    {
        $done = 0;
        $skipped = [];

        foreach (Approval::query()->whereKey($ids)->get() as $approval) {
            if ($this->movesMoney($approval)) {
                $skipped['money'] = ($skipped['money'] ?? 0) + 1;

                continue;
            }

            if ($approval->status !== Approval::PENDING) {
                $skipped['not_pending'] = ($skipped['not_pending'] ?? 0) + 1;

                continue;
            }

            /*
             * ⓘ প্রতিটা আলাদা করে [[ApprovalEngine::canDecide()]] দিয়ে
             * যায় — কর্তৃত্বের সীমা, নিজের অনুরোধ, আগেই সই দেওয়া, সব।
             * ⛔ bulk বলে কোনো নিয়ম শিথিল হয় না।
             */
            if (! $this->engine->canDecide($approval, $user)) {
                $skipped['not_allowed'] = ($skipped['not_allowed'] ?? 0) + 1;

                continue;
            }

            $this->engine->approve($approval, $user, $remarks);
            $done++;
        }

        return ['done' => $done, 'skipped' => $skipped];
    }

    /**
     * ⛔ এই কাজে টাকা নড়ে?
     *
     * ── ⚠️ উত্তরটা মডিউল নিজে বলে, এই ফাইল নয় ───────────────────────
     * ⓘ `module.php`-এ `'moves_money' => ['payment', 'withdrawal', …]`।
     * ⛔ এখানে একটা তালিকা হাতে লিখলে নতুন একটা টাকার কাজ যোগ হলে কেউ
     * এখানে বসাতে ভুলে যেত, আর সেটা **নীরবে bulk-এ ঢুকে পড়ত**
     * ([[never-supply-the-name-yourself]])।
     */
    public function movesMoney(Approval $approval): bool
    {
        $module = $this->modules->get($approval->module);

        if ($module === null) {
            /*
             * ⚠️ মডিউলটাই চেনা গেল না — তখন কড়া দিকটা।
             * ⓘ সন্দেহে bulk বন্ধ রাখা, খোলা রাখা নয়।
             */
            return true;
        }

        return in_array($approval->action, $module->movesMoney, true);
    }
}
