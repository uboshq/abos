<?php

declare(strict_types=1);

namespace App\Modules\Documents\Services;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\User;
use App\Models\UserDataScope;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentGrant;
use App\Modules\Documents\Support\DocumentCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * কে কোন কাগজ দেখবেন, আর কাগজে কী করতে পারবেন (§১৩, §১৪; ৮-৯ অক্টোবর ২০২৬)।
 *
 * ── ⓘ সিঁড়ি ───────────────────────────────────────────────────────────
 * সবার জন্য আর অভ্যন্তরীণ — DOC দেখার চাবি (`documents.view`) থাকলেই।
 * গোপন → `documents.confidential`; অতি গোপন → `documents.highly_confidential`;
 * সংরক্ষিত → `documents.restricted` (পরিকল্পনা §১৪: *"restricted-এ বাড়তি permission"*)।
 * ⚠️ উপরের ধাপের চাবি নিচের সব ধাপ খোলে।
 *
 * ── ⭐ দেয়ালের ক্রম: কোম্পানি → শাখা → বিভাগ → কর্মী → কাগজ (দ্বিতীয় ধাপ) ──
 * কোম্পানি আর শাখা — মডেলের গ্লোবাল স্কোপ, এখানে নয়।
 * বিভাগ — ব্যবহারকারীর পর্দায় বসানো "বিভাগ"-এর সীমা (`user_data_scopes`, ধরন
 *   [[DEPARTMENT_SCOPE]]); সীমা থাকলে কেবল ঐ বিভাগগুলোর কাগজ, আর বিভাগহীন কাগজ।
 * কর্মী — কাগজের মালিক আর যিনি তুলেছেন, ধাপ বা বিভাগ যা-ই হোক নিজের কাগজ দেখেন।
 * কাগজ — কাগজ-ধরে অধিকার ([[DocumentGrant]]): একজন মানুষ বা একটা ভূমিকাকে একটা কাগজ।
 *
 * ── ⛔ এক নিয়ম, প্রতিটা পথে ──────────────────────────────────────────
 * তালিকা ([[visibleScope()]]), বিস্তারিত, প্রিভিউ, নামানো, ছাপা, খোঁজ, রিপোর্ট — সবাই এই
 * একটা ক্লাস পড়ে। ⚠️ দুই জায়গায় দুইবার লিখলে একদিন একটা বদলাত আর অন্যটা নয়।
 */
final class DocumentAccess
{
    /** বিভাগের সীমার ধরন — module.php-র `data_scopes`-এ ঘোষিত */
    public const DEPARTMENT_SCOPE = 'department';

    /**
     * ধাপ => যে চাবি সেই ধাপ (আর তার নিচের সব) খোলে।
     *
     * @var array<string, string>
     */
    private const KEYS = [
        DocumentCatalog::CONFIDENTIAL => 'documents.confidential',
        DocumentCatalog::HIGHLY_CONFIDENTIAL => 'documents.highly_confidential',
        DocumentCatalog::RESTRICTED => 'documents.restricted',
    ];

    /** @var array<string, list<int>> অনুরোধের ভিতরে — একই মানুষের ভূমিকা বারবার না পড়া */
    private array $roles = [];

    public function __construct(private readonly DataScope $scope) {}

    /**
     * এই মানুষ কোন ধাপগুলো দেখেন — নিজের কাগজ আর অধিকার বাদে।
     *
     * @return list<string>
     */
    public function levelsFor(User $user): array
    {
        $top = 1; // ⓘ LEVELS-এর ১ = internal: চাবি ছাড়া এ পর্যন্ত

        foreach (self::KEYS as $level => $key) {
            if ($user->can($key)) {
                $top = max($top, (int) array_search($level, DocumentCatalog::LEVELS, true));
            }
        }

        return array_slice(DocumentCatalog::LEVELS, 0, $top + 1);
    }

    /**
     * বিভাগের সীমা — null মানে সীমা নেই।
     *
     * @return list<int>|null
     */
    public function departmentsFor(User $user): ?array
    {
        return $this->scope->idsFor($user, self::DEPARTMENT_SCOPE);
    }

    /** এই মানুষ এই কাগজটা দেখতে পান কি না — নিজের, অধিকার, নয়তো ধাপ আর বিভাগ। */
    public function canSee(User $user, Document $document): bool
    {
        // ⭐ সইকারী শাখার দেয়ালের বাইরেও পড়েন — ABOS-এর সইয়ের নিয়ম ([[ApprovalFacts::VIEW_WALLS]])
        if ($this->isSigner($user, $document)) {
            return true;
        }

        /*
         * ⛔ শাখার দেয়াল — তালিকায় আর ঠিকানায় এটা মডেলের গ্লোবাল স্কোপ; কিন্তু খবরের প্রাপক বাছাই আর
         * নির্ধারিত কাজ কাগজটা স্কোপ ছাড়াই হাতে পায়। ⚠️ এখানে না দেখলে অন্য শাখার মানুষ কাগজ-ধরে
         * অধিকারের খবরে কাগজের নাম পেয়ে যেতেন (তৃতীয় ধাপে ধরা পড়ল)।
         */
        // ⓘ শাখাহীন (গোটা কোম্পানির) কাগজ সবার নাগালে — ABOS-এর নিয়ম ([[DataScope::allows()]])
        if (! $this->scope->allows($user, UserDataScope::BRANCH, $document->branch_id === null ? null : (int) $document->branch_id)) {
            return false;
        }

        if ($this->isOwn($user, $document) || $this->granted($user, $document, 'view')) {
            return true;
        }

        $departments = $this->departmentsFor($user);

        if ($departments !== null && $document->department_id !== null
            && ! in_array((int) $document->department_id, $departments, true)) {
            return false;
        }

        return in_array((string) $document->confidentiality, $this->levelsFor($user), true);
    }

    /**
     * কাগজ-ধরে অধিকার আছে কি না — মানুষের নিজের নামে, বা তাঁর কোনো ভূমিকার নামে।
     *
     * @param  string  $ability  view, download, print, share, edit ([[DocumentGrant::ABILITIES]])
     */
    public function granted(User $user, Document $document, string $ability): bool
    {
        $column = DocumentGrant::ABILITIES[$ability] ?? null;

        if ($column === null) {
            return false;
        }

        return $this->grantsQuery($user)
            ->where('dms_document_permissions.document_id', $document->getKey())
            ->where('dms_document_permissions.'.$column, true)
            ->exists();
    }

    /**
     * তালিকার তৃতীয় দেয়াল — [[Document::scopeVisibleTo()]] এটাই ডাকে।
     */
    public function visibleScope(Builder $query, User $user): Builder
    {
        $levels = $this->levelsFor($user);
        $departments = $this->departmentsFor($user);
        $table = $query->getModel()->getTable();
        $grants = $this->grantsQuery($user)->where('dms_document_permissions.can_view', true)
            ->select('dms_document_permissions.document_id');

        return $query->where(function (Builder $q) use ($levels, $departments, $user, $table, $grants) {
            $q->where(function (Builder $byRule) use ($levels, $departments, $table) {
                $byRule->whereIn($table.'.confidentiality', $levels);

                if ($departments !== null) {
                    $byRule->where(fn (Builder $d) => $d->whereNull($table.'.department_id')
                        ->orWhereIn($table.'.department_id', $departments));
                }
            })
                ->orWhere($table.'.owner_id', $user->getKey())
                ->orWhere($table.'.created_by', $user->getKey())
                ->orWhereIn($table.'.id', $grants);
        });
    }

    /**
     * আমার সাথে শেয়ার করা কাগজ — চালু শেয়ার, আমার নামে বা আমার ভূমিকার নামে (§১৪ Shared Documents)।
     */
    public function sharedWith(Builder $query, User $user): Builder
    {
        $ids = $this->grantsQuery($user)->where('dms_document_permissions.via_share', true)
            ->select('dms_document_permissions.document_id');

        return $query->whereIn($query->getModel()->getTable().'.id', $ids);
    }

    /**
     * ধাপগুলোর মধ্যে কোনগুলো এই মানুষ কাগজে **বসাতে** পারেন।
     *
     * ⓘ যে ধাপ নিজে দেখেন না, সেই ধাপে কাগজ লুকানো যায় না।
     *
     * @return list<string>
     */
    public function levelsToChoose(User $user): array
    {
        return $this->levelsFor($user);
    }

    /**
     * এই মানুষের নামে বা তাঁর ভূমিকার নামে দেওয়া অধিকারগুলো — এই কোম্পানিতে।
     *
     * ⓘ ভূমিকা কাঁচা কোয়েরিতে পড়া, কোম্পানি ধরে — [[DataScope]]-এর ধাঁচে, যাতে spatie-র
     * চলতি টিম যা-ই থাকুক অন্য কোম্পানির ভূমিকা কখনো না আসে।
     */
    private function grantsQuery(User $user): \Illuminate\Database\Query\Builder
    {
        $companyId = (int) CompanyContext::id();
        $roles = $this->rolesOf($user, $companyId);

        return DB::table('dms_document_permissions')
            ->where('dms_document_permissions.company_id', $companyId)
            // ⭐ শেয়ারের মেয়াদ — পেরোলে অধিকারটা নিজে থেকেই বন্ধ (চতুর্থ ধাপ); সময় PHP থেকে
            ->where(fn ($q) => $q->whereNull('dms_document_permissions.expires_at')
                ->orWhere('dms_document_permissions.expires_at', '>', now()->toDateTimeString()))
            ->where(function ($q) use ($user, $roles) {
                $q->where(fn ($u) => $u->where('dms_document_permissions.grantee_type', DocumentGrant::USER)
                    ->where('dms_document_permissions.grantee_id', $user->getKey()));

                if ($roles !== []) {
                    $q->orWhere(fn ($r) => $r->where('dms_document_permissions.grantee_type', DocumentGrant::ROLE)
                        ->whereIn('dms_document_permissions.grantee_id', $roles));
                }
            });
    }

    /** @return list<int> */
    private function rolesOf(User $user, int $companyId): array
    {
        $key = $companyId.':'.$user->getKey();

        return $this->roles[$key] ??= DB::table('model_has_roles')
            ->where('model_has_roles.model_id', $user->getKey())
            ->where('model_has_roles.model_type', $user->getMorphClass())
            ->where('model_has_roles.company_id', $companyId)
            ->pluck('model_has_roles.role_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * ⭐ যাঁর কাছে এই কাগজ সইয়ের অপেক্ষায় — তিনি পড়েই সই দেন (তৃতীয় ধাপ)।
     *
     * ⓘ ভাউচারের মতোই ([[ApprovalInboxController::show()]]-এর `$mayReadDocument`): গোপনীয়তার ধাপ না থাকলেও
     * যিনি এখন সই দিতে পারেন তিনি কাগজটা খোলেন; সই পড়ে গেলে আবার নিজের ধাপ। ⛔ তালিকায় নয় — কেবল
     * কাগজের নিজের পাতা, প্রিভিউ আর নামানো (দেখার নিয়ম যেখানে কাগজ ধরে জিজ্ঞেস করে)।
     */
    public function isSigner(User $user, Document $document): bool
    {
        $engine = app(ApprovalEngine::class);

        // ⓘ অনুমোদন — কেবল জমা বা পর্যালোচনায় থাকা কাগজে; সই (চতুর্থ ধাপ) — যেকোনো চলমান সই-অনুরোধে
        $actions = in_array($document->status, [DocumentCatalog::SUBMITTED, DocumentCatalog::UNDER_REVIEW], true)
            ? [DocumentWorkflow::ACTION, DocumentSignatures::ACTION]
            : [DocumentSignatures::ACTION];

        foreach ($actions as $action) {
            $approval = $engine->latestFor($document, $action);

            if ($approval !== null && $approval->status === Approval::PENDING && $engine->canDecide($approval, $user)) {
                return true;
            }
        }

        return false;
    }

    private function isOwn(User $user, Document $document): bool
    {
        $id = (int) $user->getKey();

        return (int) $document->owner_id === $id || (int) $document->created_by === $id;
    }
}
