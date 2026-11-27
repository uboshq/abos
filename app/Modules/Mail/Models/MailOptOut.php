<?php

declare(strict_types=1);

namespace App\Modules\Mail\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ যে ঠিকানা অফারের মেইল আর চায় না (NEXUS-এর পর্যালোচনা R2; RFC 8058) — [[MailOptOuts]]।
 * ⓘ অডিটে থাকে — কে কবে বন্ধ করল (গ্রাহক নিজে, লিংক বা মেইল প্রোগ্রামের বোতাম), পরে প্রশ্ন উঠলে দেখা যায়।
 */
class MailOptOut extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    protected $table = 'mail_optouts';

    protected $fillable = ['email', 'source', 'via'];
}
