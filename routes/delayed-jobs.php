<?php

use BoringO11y\HorizonDelayedJobs\Http\Controllers\DelayedJobsController;
use BoringO11y\HorizonDelayedJobs\Http\Controllers\PerformNowController;
use Illuminate\Support\Facades\Route;

Route::get('/delayed-jobs', [DelayedJobsController::class, 'index'])
    ->name('horizon-delayed-jobs.index');

if (config('horizon-delayed-jobs.perform_now', true)) {
    Route::post('/delayed-jobs/perform', [PerformNowController::class, 'storeMany'])
        ->name('horizon-delayed-jobs.perform-many');

    Route::post('/delayed-jobs/perform/{id}', [PerformNowController::class, 'store'])
        ->name('horizon-delayed-jobs.perform');
}
