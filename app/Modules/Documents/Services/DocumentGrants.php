<?php

declare(strict_types=1);

namespace App\Modules\Documents\Services;

use App\Core\Support\Actor;
use App\Core\Support\CompanyContext;
use App\Models\User;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentGrant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * কাগজ-ধরে অধিকার দেওয়া আর সরানো — পরিকল্পনা §১৩ (দ্বিতীয় ধাপ, ৯ অক্টোবর ২০২৬)।
 *
 * ⓘ একজন মানুষ বা একটা ভূমিকা, একটা কাগজ, পাঁচটা অধিকার ([[DocumentGrant::ABILITIES]])।
 * একই জনকে আবার দিলে নতুন সারি নয় — আগেরটাই বদলায়।
 *
 * ⭐ প্রতিটা বদল কাগজের অডিটে নিজের নামে (`doc_access_changed`), আর সারিটা নিজেও
 * অডিটে ([[IsAudited]]) — কে, কাকে, কী দিলেন বা সরালেন।
 */
final class DocumentGrants
{
    public function __construct(private readonly DocumentNotices $notices) {}

    /** @var list<string> */
    public const TYPES = [DocumentGrant::USER, DocumentGrant::ROLE];

    /**
     * @param  list<string>  $abilities
     */
    public function grant(Document $document, string $type, int $granteeId, array $abilities): DocumentGrant
    {
        $made = DB::transaction(function () use ($document, $type, $granteeId, $abilities) {
            $flags = [];

            foreach (DocumentGrant::ABILITIES as $ability => $column) {
                // ⓘ দেখা সবসময় চালু — দেখা ছাড়া নামানো বা ছাপা অর্থহীন
                $flags[$column] = $ability === 'view' || in_array($ability, $abilities, true);
            }

            $grant = DocumentGrant::query()->firstOrNew([
                'document_id' => $document->getKey(),
                'grantee_type' => $type,
                'grantee_id' => $granteeId,
            ]);

            $grant->fill([
                ...$flags,
                // ⓘ প্রশাসকের দেওয়া অধিকার শেয়ার নয় — মেয়াদ নেই, আগের শেয়ারটাকেও ঢেকে দেয়
                'via_share' => false,
                'expires_at' => null,
                'company_id' => $document->company_id ?? CompanyContext::id(),
                'updated_by' => Actor::userId(),
            ]);

            if (! $grant->exists) {
                $grant->created_by = Actor::userId();
            }

            $grant->save();

            $document->auditAction('doc_access_changed', $grant->granteeName().': '.implode(', ', $grant->abilities()));

            return $grant;
        });

        $this->notices->permissionChanged($document, $made, removed: false);

        return $made;
    }

    /**
     * ⭐ শেয়ার (চতুর্থ ধাপ) — একই অধিকারের খাতা, কেবল "দেখা" আর ইচ্ছা হলে "নামানো", আর ঐচ্ছিক মেয়াদ।
     *
     * ⓘ শেয়ার অধিকারের মতোই দেয়াল মানে ([[DocumentAccess]]) — অন্য শাখা বা কোম্পানির মানুষের জন্য কিছুই
     * খোলে না। ⛔ ইন্টারনেটের খোলা লিংক নয় — কেবল ABOS-এর ভিতরের মানুষ বা ভূমিকা।
     * ⓘ আগে থেকে পূর্ণ অধিকার থাকলে শেয়ার সেটা কমায় না — বেশি অধিকারটাই থাকে।
     */
    public function share(Document $document, string $type, int $granteeId, bool $download, ?Carbon $expiresAt): DocumentGrant
    {
        $made = DB::transaction(function () use ($document, $type, $granteeId, $download, $expiresAt) {
            $grant = DocumentGrant::query()->firstOrNew([
                'document_id' => $document->getKey(),
                'grantee_type' => $type,
                'grantee_id' => $granteeId,
            ]);

            $wasFull = $grant->exists && ! $grant->via_share;

            $grant->fill([
                'company_id' => $document->company_id ?? CompanyContext::id(),
                'can_view' => true,
                'can_download' => $download || ($wasFull && $grant->can_download),
                'via_share' => ! $wasFull,
                'expires_at' => $wasFull ? null : $expiresAt?->endOfDay(),
                'updated_by' => Actor::userId(),
            ]);

            if (! $grant->exists) {
                $grant->created_by = Actor::userId();
            }

            $grant->save();

            $document->auditAction('document_shared', $grant->granteeName()
                .($expiresAt !== null ? ' → '.$expiresAt->toDateString() : ''));

            return $grant;
        });

        $this->notices->shared($document, $made);

        return $made;
    }

    public function revoke(Document $document, DocumentGrant $grant): void
    {
        DB::transaction(function () use ($document, $grant) {
            $name = $grant->granteeName();
            $grant->delete();

            $document->auditAction('doc_access_changed', $name.': —');
        });

        $this->notices->permissionChanged($document, $grant, removed: true);
    }

    /**
     * যাঁদের অধিকার দেওয়া যায় — এই কোম্পানির মানুষ।
     *
     * @return array<int, string>
     */
    public function people(): array
    {
        return User::query()
            ->whereHas('companies', fn ($q) => $q->where('companies.id', CompanyContext::id()))
            ->orderBy('name')
            ->get(['users.id', 'users.name'])
            ->mapWithKeys(fn (User $u) => [(int) $u->id => (string) $u->name])
            ->all();
    }

    /**
     * যে ভূমিকাগুলোকে অধিকার দেওয়া যায় — এই কোম্পানির।
     *
     * @return array<int, string>
     */
    public function roles(): array
    {
        return Role::query()
            ->where('company_id', CompanyContext::id())
            ->orderBy('name')
            ->pluck('name', 'id')
            ->mapWithKeys(fn ($name, $id) => [(int) $id => (string) $name])
            ->all();
    }
}
