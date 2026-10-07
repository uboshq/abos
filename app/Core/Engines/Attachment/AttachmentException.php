<?php

declare(strict_types=1);

namespace App\Core\Engines\Attachment;

use RuntimeException;

/** সংযুক্তি গ্রহণ করা যায়নি — বার্তাটা সরাসরি ব্যবহারকারীকে দেখানোর মতো। */
class AttachmentException extends RuntimeException {}
