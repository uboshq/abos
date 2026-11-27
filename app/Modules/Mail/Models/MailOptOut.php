<?php

declare(strict_types=1);

namespace App\Modules\Mail\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ যে ঠিকানা অফারের মেইল আর চায় না (NEXUS-এর পর্যালোচনা R2; RFC 8058) — [[MailOptOuts]]।
 */
class MailOptOut extends Model
{
    use BelongsToCompany;
    use HasPublicId;

    protected $table = 'mail_optouts';

    protected $fillable = ['email', 'source', 'via'];
}
