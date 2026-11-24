<?php

declare(strict_types=1);

namespace App\Core\Engines\Approval;

use Illuminate\Validation\ValidationException;

/**
 * অনুমোদন দেখার মাঝখানেই কাগজ বদলাল — পুরনো অঙ্কে নতুন কাগজের অনুরোধ বসানো যায় না, শুরু থেকে আবার ([[DocumentApproval::assertClear()]])।
 */
final class PaperChangedWhileChecking extends ValidationException {}
