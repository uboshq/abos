<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * একটা নোটিশ প্রকাশের সময় ঠিক যেমনটা ছিল।
 *
 * ⓘ প্রশ্নটা *"কে কী বদলেছে"* নয় — ওটা অডিট-খাতার কাজ. প্রশ্নটা
 * *"যিনি মঙ্গলবার পড়েছিলেন তিনি কোন লেখাটা পড়েছিলেন"*.
 */
final class NoticeVersion extends Model
{
    use BelongsToCompany;
    use HasPublicId;

    protected $fillable = [
        'company_id', 'notice_id', 'revision',
        'title', 'summary', 'body', 'priority', 'change_note', 'created_by',
    ];

    protected function casts(): array
    {
        return ['revision' => 'integer'];
    }

    public function notice(): BelongsTo
    {
        return $this->belongsTo(Notice::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
