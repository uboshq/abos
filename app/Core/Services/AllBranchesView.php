<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\User;
use App\Models\UserDataScope;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;

/**
 * ⛔ "সব শাখা" = কেবল দেখা, লেখা নয় — মালিকের চূড়ান্ত সিদ্ধান্ত, ২০ নভেম্বর ২০২৬।
 *
 * ── কী ঘটেছিল ────────────────────────────────────────────────────────
 * উপরে "সব শাখা" বাছা থাকলে কেবল **দেখা** বদলায়; নতুন কাগজ বসে মানুষটার লুকানো কাজের শাখায় (`users.current_branch_id`,
 * [[WorkspaceController::switchBranch()]], [[FormBranchPin]])। লাইভে আদায় Super-এর বদলে TD-তে বসল, তারপর আবার লেখা হলো।
 *
 * ── ⭐ নিয়ম (সবার — মালিক, CEO, যাঁর নাগালে একাধিক শাখা) ────────────────────
 * "সব শাখা"-তে দেখা চলে (তালিকা, রিপোর্ট, কাগজ, ড্যাশবোর্ড), লেখা নয় — তৈরি, বদল, পোস্ট, বাতিল, মোছা কিছুই না।
 * ছাড় কেবল [[ALLOWED]]-এর দরজাগুলো (অনুমোদনের সিদ্ধান্ত — কাগজের শাখা আগেই বাঁধা; শাখা/কোম্পানি বদল; নিজের পছন্দ …)।
 * ⓘ যাঁর নাগালে একটাই শাখা, তাঁর "সব" মানে সেই শাখাই — তাঁকে থামানো হয় না।
 *
 * ⓘ সার্ভারের পাহারা [[RefusesWritesInAllBranchesView]]; পাতায় চিহ্ন আর ধূসর বোতাম খোলস থেকে ([[all-branches-view.js]])।
 */
final class AllBranchesView
{
    /**
     * ⭐ "সব শাখা"-তেও যে দরজাগুলো খোলা — রুটের নাম ধরে, হাতে দেখা তালিকা (ঢিলা ছাঁচ নয়)।
     *
     * ⓘ GET কখনো থামে না (দেখা, খোঁজা, ছাপা, CSV/PDF) — তাই এখানে কেবল লেখার পদ্ধতির (POST/PUT/PATCH/DELETE) দরজা।
     * ⚠️ নতুন দরজা এখানে না থাকলে "সব শাখা"-তে থামে — সেটাই নিরাপদ দিক।
     *
     * @var list<string>
     */
    public const ALLOWED = [
        // ── দেখা বদলানো আর বেরোনো — এগুলো ছাড়া "সব শাখা" থেকে বেরোনোর পথই থাকত না
        'logout',
        'company.switch',
        'branch.switch',
        'executive.open',                       // সংখ্যা থেকে উৎসে — কোম্পানি/শাখা বদলে পাতা
        'executive.refresh',                    // মালিকের পর্দার সংখ্যা নতুন করে গোনা — কোনো কাগজ নয়

        // ── অনুমোদনের সিদ্ধান্ত — কাগজের শাখা আগেই বাঁধা (SAP/D365-এর একই রীতি)
        'approval.inbox.approve',
        'approval.inbox.confirm_all',
        'approval.inbox.reject',
        'approval.inbox.send_back',
        'approval.inbox.forward',
        'approval.inbox.withdraw',
        'approval.inbox.bulk',
        'accounts.count.approve',
        'finance.insurance.claim.approve',
        'finance.insurance.claim.reject',
        'hr.leave.approve',
        'hr.leave.reject',
        'inventory.count.approve',
        'promotion.approve',
        'promotion.send_back',
        'promotion.withdraw',
        'purchase.requisition.approve',
        'purchase.payment_schedule.sign_all',
        'sales.quotation.approve',
        'sales.quotation.reject',
        'supplier.bank.sign',
        'supplier.bank.reject',
        'supplier.bank.withdraw',
        'system_admin.setting-change.sign',
        'system_admin.setting-change.reject',
        'system_admin.notice.sign',

        // ── নিজের জিনিস — প্রোফাইল, চেহারা, ভাষা, দুই ধাপ, নিজের সেশন
        'profile.update',
        'profile.avatar',
        'profile.avatar.remove',
        'profile.password',
        'profile.email.request',
        'appearance.save',
        'locale.switch',
        'theme.switch',
        'home.layout',
        'rail.layout',
        'mfa.begin',
        'mfa.confirm',
        'mfa.destroy',
        'governance.session.others',
        'governance.session.destroy',
        'system_admin.look.preview',
        'system_admin.look.preview.stop',

        // ── খবর আর পপআপ — পড়া, সরানো, পরে দেখানো
        'notifications.read',
        'notifications.read-all',
        'notifications.bulk',
        'notifications.archive',
        'notifications.banner.close',
        'notifications.push.subscribe',
        'notifications.push.unsubscribe',
        'notifications.settings.update',
        'notification.quiet.save',
        'governance.error.acknowledge',
        'governance.error.acknowledge_all',
        'system_admin.notice.dismiss',
        'executive.dayclose.snooze',

        // ── তালিকার সংরক্ষিত দৃশ্য, কোপাইলটের প্রশ্ন, কাগজ পাঠানো (ছাপার মতোই — কিছু লেখে না)
        'views.store',
        'views.default',
        'views.destroy',
        'assistant.quick',
        'assistant.dismiss',
        'assistant.feedback',
        'paper.share',
        'paper.revoke',
        'paper.whatsapp',
        'paper.sms',

        // ── বার্তা ও কল — সহকর্মীর সাথে কথা, কোনো শাখার কাগজ বা হিসাব নয় (মালিক, ২৩ নভেম্বর ২০২৬: "ওয়েবে বার্তা লেখা যায় না" —
        //    "সব শাখা"-তে পাঠানো 409 পাচ্ছিল, তাই কথোপকথন খুলত না আর লেখার ঘর দেখাত না)। ওয়েবের দুই রাস্তা আর ফোনের API একসাথে।
        'messaging.send', 'messaging.direct', 'messaging.groups', 'messaging.members.add', 'messaging.forward', 'messaging.papers',
        'messaging.read', 'messaging.delivered', 'messaging.typing', 'messaging.mute', 'messaging.message.delete', 'messaging.audit.open',
        'messaging.calls.start', 'messaging.calls.answer', 'messaging.calls.decline', 'messaging.calls.end',
        'messaging.x.send', 'messaging.x.direct', 'messaging.x.groups', 'messaging.x.members.add', 'messaging.x.forward', 'messaging.x.papers',
        'messaging.x.read', 'messaging.x.typing', 'messaging.x.mute', 'messaging.x.message.delete',
        'messaging.x.calls.start', 'messaging.x.calls.answer', 'messaging.x.calls.decline', 'messaging.x.calls.end',
        'api.messaging.send', 'api.messaging.direct', 'api.messaging.groups', 'api.messaging.members.add', 'api.messaging.forward', 'api.messaging.papers',
        'api.messaging.read', 'api.messaging.delivered', 'api.messaging.typing', 'api.messaging.mute', 'api.messaging.message.delete',
        'api.messaging.calls.start', 'api.messaging.calls.answer', 'api.messaging.calls.decline', 'api.messaging.calls.end',
    ];

    public function __construct(private readonly DataScope $scope) {}

    /**
     * এই মানুষ এখন "সব শাখা" দেখছেন, আর তাঁর নাগালে একাধিক শাখা — অর্থাৎ লেখা বন্ধ।
     *
     * ⓘ তিনটা শর্ত, তিনটাই দরকার: কর্মী (কোম্পানির প্রসঙ্গসহ), `view_all_branches`, নাগালে একের বেশি চালু শাখা — শেষেরটা
     * সুইচারের "সব শাখা" লেখার একই হিসাব ([[ShellFacts::branchLabel()]], [[shell.company-switcher]])।
     * ⓘ সুইচ `abos.branch_pin.all_branches_read_only` বন্ধ হলে কখনো নয় — পরীক্ষার ঘর ([[TestCase]]) আর জরুরি অবস্থার জন্য।
     */
    public function on(mixed $user): bool
    {
        if (! (bool) config('abos.branch_pin.all_branches_read_only', true)) {
            return false;
        }

        $companyId = CompanyContext::id();

        if (! $user instanceof User || $companyId === null || ! (bool) ($user->view_all_branches ?? true)) {
            return false;
        }

        return $this->severalInReach($user, $companyId);
    }

    /**
     * ⭐ একটা শাখা বাছা থাকলে ফর্মের মাথার লাইনের শাখার নাম — "আপনি এখন X শাখায় লিখছেন" (মালিক, ২০ নভেম্বর ২০২৬)।
     *
     * ⓘ কেবল যাঁর নাগালে একাধিক শাখা (একটা হলে প্রশ্নই নেই), আর কেবল নতুন/বদলের ফর্মে (`*.create`, `*.edit`)।
     * ⓘ নামটা কাজের শাখার ([[CompanyContext::branchId()]]) — নতুন কাগজ যেখানে বসে, ছাপও সেটাই ([[FormBranchPin::stamp()]])।
     */
    public function writingBranch(mixed $user, ?string $routeName): ?string
    {
        $companyId = CompanyContext::id();
        $branchId = CompanyContext::branchId();
        $route = (string) $routeName;

        if (! $user instanceof User || $companyId === null || $branchId === null
            || (bool) ($user->view_all_branches ?? true)
            || ! (str_ends_with($route, '.create') || str_ends_with($route, '.edit'))
            || ! $this->severalInReach($user, $companyId)) {
            return null;
        }

        return Branch::query()->whereKey($branchId)->first()?->name();
    }

    /**
     * ⓘ উত্তরটা অনুরোধের গায়ে রাখা — তালিকার প্রতিটা সারির মেনু ([[ui.paper-row-menu]]) জিজ্ঞেস করে, প্রশ্ন একবারই; পরের
     * অনুরোধে (নাগাল বদলালে) আবার তাজা। ⚠️ বস্তুর নিজের জমায় নয় — `app()` প্রতিবার নতুন বস্তু দেয়।
     */
    private function severalInReach(User $user, int $companyId): bool
    {
        $key = 'abos.branches_in_reach.'.$companyId.':'.$user->getKey();
        $attributes = app()->bound('request') ? request()->attributes : null;

        if ($attributes !== null && $attributes->has($key)) {
            return (bool) $attributes->get($key);
        }

        $several = $this->reachableBranches($user) > 1;
        $attributes?->set($key, $several);

        return $several;
    }

    /** এই দরজা "সব শাখা"-তেও খোলা কি না — GET/HEAD/OPTIONS সবসময়, বাকিগুলো কেবল [[ALLOWED]]-এ থাকলে। */
    public function lets(Request $request): bool
    {
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return true;
        }

        return in_array((string) $request->route()?->getName(), self::ALLOWED, true);
    }

    /**
     * ⭐ খোলা দরজাগুলোর ঠিকানার ছাঁচ — পাতার JS এটা দেখেই ফর্ম ধূসর করে বা ছাড়ে ([[all-branches-view.js]])।
     *
     * ⓘ একটাই তালিকা দুই জায়গায়: সার্ভার নাম মেলায়, পাতা ঠিকানা — `{param}` যেকোনো এক টুকরো।
     *
     * @return list<string>
     */
    public function allowedPathPatterns(): array
    {
        $routes = app('router')->getRoutes();
        $patterns = [];

        foreach (self::ALLOWED as $name) {
            $route = $routes->getByName($name);

            if (! $route instanceof Route) {
                continue;
            }

            $uri = '/'.ltrim($route->uri(), '/');
            $quoted = preg_quote($uri === '/' ? '/' : rtrim($uri, '/'), '#');
            $patterns[] = '^'.(string) preg_replace('#\\\\\{[^}]+\\\\\}#', '[^/]+', $quoted).'/?$';
        }

        return array_values(array_unique($patterns));
    }

    /** নাগালের চালু শাখা কয়টা — সুইচারের তালিকার একই প্রশ্ন ([[ShellFacts::branches()]])। */
    private function reachableBranches(User $user): int
    {
        $allowed = $this->scope->idsFor($user, UserDataScope::BRANCH);

        return Branch::query()
            ->where('is_active', true)
            ->when($allowed !== null, fn ($q) => $q->whereIn('id', $allowed))
            ->count();
    }
}
