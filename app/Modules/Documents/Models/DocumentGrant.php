<?php

declare(strict_types=1);

namespace App\Modules\Documents\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Permission\Models\Role;

/**
 * একটা কাগজে একজন মানুষ বা একটা ভূমিকার নিজের অধিকার — পরিকল্পনা §১৩ "document-level permission"।
 *
 * ⭐ দেখা সবসময় চালু; নামানো, ছাপা, শেয়ার, বদল আলাদা করে বাছা।
 *
 * ── ⛔ কোন দেয়াল এটা পেরোয়, কোনটা নয় ────────────────────────────────
 * পেরোয়: গোপনীয়তার ধাপ আর বিভাগের সীমা — কাগজের মালিক বা প্রশাসক জেনেশুনে ঐ মানুষটাকে
 * দিয়েছেন। ⛔ পেরোয় না: কোম্পানি আর শাখার দেয়াল — ওগুলো গোটা ABOS-এর নিয়ম, একটা কাগজের নয়।
 * ⓘ DOC-এ ঢোকার চাবিও (`documents.view`) লাগে — অধিকার কেবল কাগজটার, মডিউলের নয়।
 */
class DocumentGrant extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    public const USER = 'user';

    public const ROLE = 'role';

    /** ⓘ অধিকারের নাম => ঘর; দেখা সবার আগে আর সবসময় চালু */
    public const ABILITIES = [
        'view' => 'can_view',
        'download' => 'can_download',
        'print' => 'can_print',
        'share' => 'can_share',
        'edit' => 'can_edit',
    ];

    protected $table = 'dms_document_permissions';

    protected $fillable = [
        'company_id', 'document_id', 'grantee_type', 'grantee_id',
        'can_view', 'can_download', 'can_print', 'can_share', 'can_edit',
        'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'can_view' => 'boolean',
            'can_download' => 'boolean',
            'can_print' => 'boolean',
            'can_share' => 'boolean',
            'can_edit' => 'boolean',
        ];
    }

    public function auditIgnores(): array
    {
        return ['updated_by'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    /** অডিটের লেবেল — কাকে */
    public function name(): string
    {
        return $this->granteeName();
    }

    public function granteeName(): string
    {
        if ($this->grantee_type === self::ROLE) {
            return (string) (Role::query()->whereKey($this->grantee_id)->value('name') ?? '—');
        }

        return (string) (User::query()->whereKey($this->grantee_id)->value('name') ?? '—');
    }

    /** @return list<string> যে অধিকারগুলো চালু */
    public function abilities(): array
    {
        return array_keys(array_filter(self::ABILITIES, fn (string $column) => (bool) $this->getAttribute($column)));
    }
}
