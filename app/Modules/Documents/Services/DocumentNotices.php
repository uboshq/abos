<?php

declare(strict_types=1);

namespace App\Modules\Documents\Services;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Services\NotificationService;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\User;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentGrant;
use App\Modules\Documents\Support\DocumentCatalog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ডকুমেন্টের খবর — ABOS-এর একটাই নোটিফিকেশন সেবা দিয়ে (পরিকল্পনা §২৩; তৃতীয় ধাপ, ৯ অক্টোবর ২০২৬)।
 *
 * ⓘ ধরনগুলো কোরের তালিকায় ([[NotificationKinds]], `documents.*`) — প্রত্যেকে নিজের খবরের
 * পর্দায় যেকোনোটা বন্ধ রাখতে পারেন। ⓘ পাঠায় [[NotificationService]]; এখানে কেবল **কাকে**।
 *
 * ── ⛔ যিনি কাগজটা দেখতে পান না, তিনি খবরও পান না ─────────────────────
 * খবরের শিরোনামে কাগজের নাম থাকে। ⚠️ গোপন চুক্তির নাম ঘণ্টির খবরে চলে গেলে গোপনীয়তার
 * দেয়ালটাই ফুটো — তাই প্রতিটা প্রাপক আগে [[DocumentAccess::canSee()]] পেরোয়।
 *
 * ⓘ যিনি কাজটা করলেন তিনি নিজের খবর পান না — সেবা নিজেই বাদ দেয়।
 */
final class DocumentNotices
{
    public const NEW = 'documents.new';

    public const SHARED = 'documents.shared';

    public const APPROVAL_REQUIRED = 'documents.approval_required';

    public const APPROVED = 'documents.approved';

    public const REJECTED = 'documents.rejected';

    public const SIGNATURE_REQUIRED = 'documents.signature_required';

    public const SIGNATURE_COMPLETED = 'documents.signature_completed';

    public const EXPIRY_WARNING = 'documents.expiry_warning';

    public const EXPIRED = 'documents.expired';

    public const RENEWAL_REQUIRED = 'documents.renewal_required';

    public const UPDATED = 'documents.updated';

    public const PERMISSION_CHANGED = 'documents.permission_changed';

    public function __construct(
        private readonly NotificationService $notifications,
        private readonly DocumentAccess $access,
        private readonly ApprovalEngine $engine,
    ) {}

    /** নতুন কাগজ — মালিককে, যদি তিনি নিজে তোলেননি */
    public function created(Document $document): void
    {
        $this->tell($this->people([(int) $document->owner_id]), self::NEW, $document,
            __('documents::notice.new', ['name' => $document->name]),
            __('documents::notice.new_body', ['no' => $document->document_no]));
    }

    /** নতুন ভার্সন — মালিক, যিনি তুলেছিলেন, আর যাঁদের এই কাগজে নিজের নামে অধিকার আছে */
    public function updated(Document $document, string $version): void
    {
        $ids = [(int) $document->owner_id, (int) $document->created_by,
            ...DocumentGrant::query()->where('document_id', $document->getKey())
                ->where('grantee_type', DocumentGrant::USER)->pluck('grantee_id')->map(fn ($id) => (int) $id)->all()];

        $this->tell($this->people($ids), self::UPDATED, $document,
            __('documents::notice.updated', ['name' => $document->name]),
            __('documents::notice.updated_body', ['version' => $version]));
    }

    /** অধিকার বদল — মানুষটা নিজে, বা ভূমিকার সবাই */
    public function permissionChanged(Document $document, DocumentGrant $grant, bool $removed): void
    {
        $ids = $this->granteeIds($document, $grant);

        // ⓘ সরানো হলে তিনি আর কাগজটা দেখেন না — তাই খবরে নাম নেই, কেবল নম্বর, আর লিংকও নয়
        $this->tell($this->people($ids), self::PERMISSION_CHANGED, $removed ? null : $document,
            $removed ? __('documents::notice.access_removed', ['no' => $document->document_no])
                : __('documents::notice.access_given', ['name' => $document->name]),
            null);
    }

    /** সই চাওয়া — এখনকার স্তরে যাঁরা সই দিতে পারেন ([[ApprovalEngine::canDecide()]]) */
    public function approvalRequired(Document $document, Approval $approval): void
    {
        $signers = $this->companyPeople()->filter(fn (User $u) => $this->engine->canDecide($approval, $u));

        $this->tell($signers, self::APPROVAL_REQUIRED, $document,
            __('documents::notice.approval_required', ['name' => $document->name]),
            __('documents::notice.approval_required_body', ['no' => $document->document_no]),
            route('approval.inbox.show', $approval->id),
            guarded: false);
    }

    /**
     * শেষ সিদ্ধান্ত — মালিককে। ⓘ যিনি সই চেয়েছিলেন তিনি ইঞ্জিনের নিজের খবর পান
     * (`approval.approved` / `approval.rejected`), তাই তাঁকে দ্বিতীয়বার নয়।
     */
    public function decided(Document $document, Approval $approval): void
    {
        $ids = array_diff([(int) $document->owner_id, (int) $document->created_by], [(int) $approval->requested_by]);
        $approved = $document->status === DocumentCatalog::APPROVED;

        $this->tell($this->people($ids), $approved ? self::APPROVED : self::REJECTED, $document,
            __($approved ? 'documents::notice.approved' : 'documents::notice.'.$document->status, ['name' => $document->name]),
            null);
    }

    /** সই চাওয়া — এখনকার স্তরে যাঁরা সই দিতে পারেন (চতুর্থ ধাপ) */
    public function signatureRequired(Document $document, Approval $approval): void
    {
        $signers = $this->companyPeople()->filter(fn (User $u) => $this->engine->canDecide($approval, $u));

        $this->tell($signers, self::SIGNATURE_REQUIRED, $document,
            __('documents::notice.signature_required', ['name' => $document->name]),
            __('documents::notice.signature_required_body', ['version' => (string) ($approval->payload['version'] ?? '')]),
            route('approval.inbox.show', $approval->id),
            guarded: false);
    }

    /** সব সই পড়ল — যিনি চেয়েছিলেন আর কাগজের মালিক */
    public function signatureCompleted(Document $document, Approval $approval): void
    {
        $this->tell($this->people([(int) $approval->requested_by, (int) $document->owner_id]), self::SIGNATURE_COMPLETED, $document,
            __('documents::notice.signature_completed', ['name' => $document->name]),
            __('documents::notice.signature_required_body', ['version' => (string) ($approval->payload['version'] ?? '')]));
    }

    /** সই হলো না — যিনি চেয়েছিলেন আর মালিক; ⓘ ইঞ্জিনের নিজের "না"-এর খবর চাওয়াকারী আলাদা পান */
    public function signatureRefused(Document $document, Approval $approval): void
    {
        $ids = array_diff([(int) $document->owner_id], [(int) $approval->requested_by]);

        $this->tell($this->people($ids), self::REJECTED, $document,
            __('documents::notice.signature_refused', ['name' => $document->name]), null);
    }

    /** শেয়ার — যাঁকে বা যে ভূমিকাকে শেয়ার করা হলো (চতুর্থ ধাপ) */
    public function shared(Document $document, DocumentGrant $grant): void
    {
        $this->tell($this->people($this->granteeIds($document, $grant)), self::SHARED, $document,
            __('documents::notice.shared', ['name' => $document->name]),
            $grant->expires_at !== null ? __('documents::notice.shared_until', ['date' => $grant->expires_at->toDateString()]) : null);
    }

    /**
     * মেয়াদ — ৯০/৬০ দিনে সতর্কতা, ৩০/১৫/৭/১ দিনে নবায়নের ডাক, পেরোলে "মেয়াদ শেষ" ([[DocumentExpiry]])।
     *
     * ⓘ মালিক আর যিনি তুলেছেন; তাঁরা কেউ না থাকলে বা নিষ্ক্রিয় হলে প্রশাসনের চাবিওয়ালারা।
     *
     * @return int কতজনকে পাঠানো হলো
     */
    public function expiry(Document $document, int $days): int
    {
        $people = $this->people([(int) $document->owner_id, (int) $document->created_by]);

        if ($people->isEmpty()) {
            $people = $this->companyPeople()
                ->filter(fn (User $u) => $u->can('documents.admin') && $this->access->canSee($u, $document));
        }

        [$type, $title] = match (true) {
            $days <= 0 => [self::EXPIRED, 'documents::notice.expired'],
            $days <= 30 => [self::RENEWAL_REQUIRED, 'documents::notice.renewal_required'],
            default => [self::EXPIRY_WARNING, 'documents::notice.expiry_warning'],
        };

        return $this->tell($people, $type, $document,
            __($title, ['name' => $document->name, 'days' => $days]),
            __('documents::notice.expiry_body', ['date' => $document->expiry_date?->toDateString()]));
    }

    /** @return list<int> অধিকারের মানুষটা, বা ভূমিকার সবাই — এই কোম্পানিতে */
    private function granteeIds(Document $document, DocumentGrant $grant): array
    {
        return $grant->grantee_type === DocumentGrant::USER
            ? [(int) $grant->grantee_id]
            : DB::table('model_has_roles')
                ->where('model_has_roles.company_id', $document->company_id)
                ->where('model_has_roles.role_id', $grant->grantee_id)
                ->where('model_has_roles.model_type', (new User)->getMorphClass())
                ->pluck('model_has_roles.model_id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, User>
     */
    private function people(array $ids): Collection
    {
        return $this->companyPeople()->filter(fn (User $u) => in_array((int) $u->getKey(), $ids, true))->values();
    }

    /** @return Collection<int, User> এই কোম্পানির চালু মানুষ */
    private function companyPeople(): Collection
    {
        $companyId = CompanyContext::id();

        return User::query()
            ->where('is_active', true)
            ->whereHas('companies', fn ($q) => $q->where('companies.id', $companyId))
            ->get();
    }

    /**
     * পাঠানো — প্রত্যেককে আলাদা করে, দেখার পাহারা পেরিয়ে।
     *
     * @param  Collection<int, User>  $people
     */
    private function tell(
        Collection $people,
        string $type,
        ?Document $document,
        string $title,
        ?string $body,
        ?string $url = null,
        bool $guarded = true,
    ): int {
        $sent = 0;

        foreach ($people as $user) {
            if ($guarded && $document !== null
                && ! ($user->can('documents.view') && $this->access->canSee($user, $document))) {
                continue;
            }

            $url ??= $document !== null ? route('documents.show', $document) : null;

            if ($this->notifications->send($user, $type, $title, $body, $url) !== null) {
                $sent++;
            }
        }

        return $sent;
    }
}
