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
        private readonly AuthorityService $authority,
    ) {}

    /**
     * ⭐ সই দেওয়ার আগে দেখা — কোনগুলো সই হবে (সংখ্যা আর মোট টাকা), আর কোনগুলো কেন বাদ (দফা ১৩, মালিক ২১ অক্টোবর ২০২৬)।
     *
     * নিয়ম, ক্রমে — প্রতিটা বাদের একটা কারণ, পর্দায় লেখা থাকে:
     *   not_pending  — আগেই মীমাংসিত;
     *   money        — টাকা নড়ার কাজ (বেতন, পরিশোধ, ব্যাংক — মডিউলের `moves_money`): একটা একটা করে;
     *   own          — নিজের কাগজ;
     *   multi_step   — এক ধাপের বেশি: একসাথে সই কেবল এক-ধাপের কাগজে;
     *   not_allowed  — এই ধাপে আপনার পালা নয় ([[ApprovalEngine::canDecide()]]);
     *   no_limit     — ⛔ আপনার টাকার সীমাই ঠিক করা নেই (fe, ২২ অক্টোবর ২০২৬: একসাথে সই মানে চোখ না বুলিয়ে অনেক টাকা,
     *                  তাই এখানে কড়া দিক); মালিক বা সুপার অ্যাডমিন ছাড়া। একটা একটা সইয়ের নিয়ম বদলায় না;
     *   over_limit   — অঙ্ক আপনার সীমার বাইরে ([[AuthorityService::limitForPaper()]], `approval_limits.max_amount`)।
     *
     * @param  list<int>  $ids
     * @return array{sign: list<Approval>, skip: list<array{approval: Approval, why: string}>, total: string}
     */
    public function review(array $ids, User $user): array
    {
        $sign = [];
        $skip = [];
        $total = '0';
        $unbounded = $user->roles->contains('name', \App\Core\Services\PermissionSyncer::SUPER_ADMIN_ROLE);

        foreach (Approval::query()->whereKey($ids)->orderBy('id')->get() as $approval) {
            // ⓘ সীমার কারণ পালার আগে — [[ApprovalEngine::canDecide()]] সীমাও দেখে, তখন সীমার বাইরের কাগজ ভুল কারণে ("পালা নয়") বাদ যেত
            $why = match (true) {
                $approval->status !== Approval::PENDING => 'not_pending',
                $this->movesMoney($approval) => 'money',
                (int) $approval->requested_by === (int) $user->id => 'own',
                ($this->engine->standingOf($approval)['levels'] ?? 1) > 1 => 'multi_step',
                ! $unbounded && $this->limitReason($approval, $user) !== null => $this->limitReason($approval, $user),
                ! $this->engine->canDecide($approval, $user) => 'not_allowed',
                default => null,
            };

            if ($why !== null) {
                $skip[] = ['approval' => $approval, 'why' => $why];

                continue;
            }

            $sign[] = $approval;
            $total = bcadd($total, (string) ($approval->amount ?? '0'), 4);
        }

        return ['sign' => $sign, 'skip' => $skip, 'total' => $total];
    }

    /**
     * ⭐ যেগুলো পারা যায়, সেগুলো — আর বাকিগুলোর কারণ ([[review()]]-এর একই নিয়ম, তাই দেখা আর করা মেলে)।
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
        $review = $this->review($ids, $user);
        $done = 0;
        $skipped = [];

        foreach ($review['skip'] as $row) {
            $skipped[$row['why']] = ($skipped[$row['why']] ?? 0) + 1;
        }

        foreach ($review['sign'] as $approval) {
            $this->engine->approve($approval, $user, $remarks);
            $done++;
        }

        return ['done' => $done, 'skipped' => $skipped];
    }

    /** সীমার কারণ — সীমা নেই, নাকি সীমার বাইরে; ভেতরে হলে null */
    private function limitReason(Approval $approval, User $user): ?string
    {
        $limit = $this->authority->limitForPaper($user, $approval);

        if ($limit === null) {
            return 'no_limit';
        }

        return $approval->amount !== null && bccomp((string) $approval->amount, $limit, 4) > 0 ? 'over_limit' : null;
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
