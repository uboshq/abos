<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ⭐ কোপাইলট আর খোঁজার খাতা — খোঁজা, প্রশ্ন ও উত্তর, নজর, প্রস্তাব, খসড়া, আটকানো প্রশ্ন, "এটা ঠিক আছে", 👍👎।
 *
 * ⛔ পর্দা থেকে বদলানো বা মোছা যায় না — কেবল পড়া (মালিকের চাবি `assistant.audit`)। ⓘ মেয়াদ শেষে প্রশ্ন আর উত্তরের লেখা মোছে,
 *   সারিটা থাকে ([[AssistantEngine::forgetOldText()]])।
 * ⓘ নিজেই খাতা, তাই অডিটের পথে আবার লেখা হয় না (একই কথা দুইবার নয়)।
 */
class AssistantEvent extends Model
{
    use BelongsToCompany;
    use HasPublicId;

    public const SEARCH = 'search';

    public const QUESTION = 'question';

    public const WATCH = 'watch';

    public const SUGGESTION = 'suggestion';

    public const DRAFT = 'draft';

    public const BLOCKED = 'blocked';

    public const DISMISS = 'dismiss';

    public const FEEDBACK = 'feedback';

    public const BRIEF = 'brief';

    /** ⭐ কে কোন পাতা খুলল বা কী নামাল (মালিক, ১১ নভেম্বর ২০২৬) */
    public const VISIT = 'visit';

    public const KINDS = [self::VISIT, self::SEARCH, self::QUESTION, self::WATCH, self::SUGGESTION, self::DRAFT, self::BLOCKED, self::DISMISS, self::FEEDBACK, self::BRIEF];

    protected $fillable = ['company_id', 'user_id', 'kind', 'module', 'page', 'text', 'answer', 'tools', 'model', 'ms', 'outcome', 'feedback', 'reason',
        'branch_id', 'channel', 'ip_address', 'user_agent'];

    protected function casts(): array
    {
        return [
            'tools' => 'array',
            'ms' => 'integer',
            'feedback' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
