<?php

declare(strict_types=1);

use App\Modules\SystemAdmin\Http\Controllers\ControlPanelController;
use Illuminate\Support\Facades\Route;

/*
 * System Administration-এর রুট।
 *
 * এখন শুধু Control Panel — কোম্পানি, ব্যবহারকারী, রোল ও ব্যাকআপের
 * পর্দাগুলো এখনো লেখা হয়নি, আর module.php-তে সেগুলো planned হিসেবেই
 * আছে। মেনুতে মৃত সারি রাখা হয় না।
 */

Route::middleware('auth')->prefix('system')->group(function () {
    Route::get('/control-panel', [ControlPanelController::class, 'edit'])->name('control-panel');
    Route::put('/control-panel', [ControlPanelController::class, 'update'])->name('control-panel.update');
});
