<?php

declare(strict_types=1);

namespace App\Core\Engines\Posting;

use RuntimeException;

/**
 * হিসাবে বসানো যায়নি।
 *
 * আলাদা শ্রেণি, কারণ এটা ধরা পড়লে করণীয় আলাদা: সাধারণ ত্রুটির মতো লগ করে
 * এগিয়ে যাওয়া নয় — পুরো লেনদেনটাই থামাতে হয়। অর্ধেক বসা হিসাব না বসা
 * হিসাবের চেয়ে খারাপ।
 */
class PostingException extends RuntimeException {}
