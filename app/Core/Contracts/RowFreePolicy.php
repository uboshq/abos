<?php

declare(strict_types=1);

namespace App\Core\Contracts;

/**
 * এই নীতির উত্তর কোন সারি, তার উপর দাঁড়ায় না — কেবল মানুষটার চাবির উপর ([[\App\Core\Services\GateMemo::ofTheList()]])।
 *
 * ⛔ সারির একটাও ঘর (অবস্থা, শাখা, কে লিখেছেন) দেখে এমন নীতিতে কখনো নয় — [[ARowFreePolicyNeverLooksAtItsRowTest]] কোড পড়ে আটকায়।
 */
interface RowFreePolicy {}
