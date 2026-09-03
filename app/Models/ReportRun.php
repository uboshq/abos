<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * একটা নির্ধারিত রিপোর্ট একবার চলার ফল — কোন ফাইল, কয় সারি, কখন।
 *
 * ফাইলটা private ডিস্কে; কেবল অনুমতি-যাচাই করা download-পথ দিয়ে নামে,
 * তাই URL অনুমান করে অন্য কোম্পানির কেউ পায় না।
 */
class ReportRun extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    protected $fillable = [
        'company_id', 'report_schedule_id', 'format',
        'file_path', 'recipients', 'row_count', 'status', 'ran_at',
    ];

    protected function casts(): array
    {
        return [
            'recipients' => 'array',
            'row_count' => 'integer',
            'ran_at' => 'datetime',
        ];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(ReportSchedule::class, 'report_schedule_id');
    }

    public function hasFile(): bool
    {
        return $this->file_path !== null && $this->status === 'ok';
    }

    /**
     * এই ফাইলটা কে নামাতে পারবেন — রেন্ডারের মুহূর্তের ছবি ধরে, সূচির
     * চলতি তালিকা ধরে নয়।
     *
     * পরে যোগ হওয়া প্রাপক পুরনো ফাইল পান না: ফাইলটা তখন তাঁর কথা ভেবে
     * কলাম ছাঁকা হয়নি, তাই তাতে তাঁর পর্দায় না-দেখা কলাম থাকতে পারে।
     */
    public function canBeDownloadedBy(int $userId): bool
    {
        return in_array($userId, array_map('intval', (array) $this->recipients), true);
    }
}
