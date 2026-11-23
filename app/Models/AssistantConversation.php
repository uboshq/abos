<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ⭐ One Copilot talk on the full-screen page — per person and per company (owner's web sample, tab খ, 21 Nov 2026).
 *
 * ⓘ The company wall is the global scope of [[BelongsToCompany]]; the person wall is [[scopeMine()]]. ⛔ The page
 *   never reads a talk without both — another person's talk, or my own talk in another company, is a 404.
 * ⓘ `messages`: a list of `{role: me|ai, at, text, …}`; an answer also carries `state`, `tool`, `headings`,
 *   `columns`, `rows`, `chart`, `sources`, `by`, `answer_id` ([[CopilotAnswer::present()]]).
 *
 * @property array<int, array<string, mixed>> $messages
 */
class AssistantConversation extends Model
{
    use BelongsToCompany;
    use HasPublicId;

    /** ⓘ a talk keeps at most this many lines (questions + answers) — the oldest drop off */
    public const MAX_MESSAGES = 200;

    /** ⓘ the history panel shows this many talks */
    public const HISTORY = 40;

    protected $fillable = ['company_id', 'user_id', 'title', 'messages', 'last_at'];

    protected function casts(): array
    {
        return [
            'messages' => 'array',
            'last_at' => 'datetime',
        ];
    }

    /** ⛔ only my own talks (the company wall is already on every query) */
    public function scopeMine(Builder $query, User $user): Builder
    {
        return $query->where($this->getTable().'.user_id', $user->id);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
