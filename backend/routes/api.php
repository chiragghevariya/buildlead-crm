<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\IngestController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\LeadController;
use App\Http\Controllers\Api\MetaController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PipelineController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\QuoteController;
use App\Http\Controllers\Api\ScoringSettingsController;
use App\Http\Controllers\Api\TeamController;
use Illuminate\Support\Facades\Route;

// ---- Public lead webhook (website forms, email parser, call centre) -------
// Throttle first so invalid tokens can't be brute-forced.
Route::post('ingest/{channel}', IngestController::class)
    ->whereIn('channel', IngestController::channels())
    ->middleware(['throttle:ingest', 'ingest.token'])
    ->name('ingest');

// ---- Auth ------------------------------------------------------------------
Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:10,1')->name('auth.login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('me', [AuthController::class, 'me'])->name('auth.me');
        Route::post('logout', [AuthController::class, 'logout'])->name('auth.logout');
    });
});

// ---- App API -----------------------------------------------------------------
Route::middleware(['auth:sanctum', 'active', 'throttle:api'])->group(function () {
    Route::get('meta', MetaController::class)->name('meta');
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('pipeline', PipelineController::class)->name('pipeline');
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('notifications/read', [NotificationController::class, 'markAllRead'])->name('notifications.read');

    Route::post('leads/bulk', [LeadController::class, 'bulk'])->name('leads.bulk');
    Route::apiResource('leads', LeadController::class);
    Route::patch('leads/{lead}/status', [LeadController::class, 'updateStatus'])->name('leads.status');
    Route::post('leads/{lead}/activities', [LeadController::class, 'storeActivity'])->name('leads.activities.store');
    Route::post('leads/{lead}/rescore', [LeadController::class, 'rescore'])->name('leads.rescore');

    // Quotes: everyone, scoped to leads they can see.
    Route::apiResource('quotes', QuoteController::class)->except('destroy');
    Route::post('quotes/{quote}/send', [QuoteController::class, 'send'])->name('quotes.send');
    Route::post('quotes/{quote}/accept', [QuoteController::class, 'accept'])->name('quotes.accept');
    Route::post('quotes/{quote}/decline', [QuoteController::class, 'decline'])->name('quotes.decline');
    Route::get('quotes/{quote}/pdf', [QuoteController::class, 'pdf'])->name('quotes.pdf');

    // ERP: admins + managers.
    Route::middleware('role:admin,manager')->group(function () {
        Route::apiResource('projects', ProjectController::class)->only(['index', 'show', 'update']);
        Route::post('projects/{project}/invoices', [InvoiceController::class, 'store'])->name('projects.invoices.store');

        Route::get('invoices', [InvoiceController::class, 'index'])->name('invoices.index');
        Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
        Route::post('invoices/{invoice}/send', [InvoiceController::class, 'send'])->name('invoices.send');
        Route::post('invoices/{invoice}/mark-paid', [InvoiceController::class, 'markPaid'])->name('invoices.mark-paid');
        Route::get('invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])->name('invoices.pdf');

        Route::get('team', [TeamController::class, 'index'])->name('team.index');
        Route::patch('team/{user}', [TeamController::class, 'update'])->name('team.update');
    });

    // Scoring engine settings: admins only.
    Route::middleware('role:admin')->prefix('settings/scoring')->name('settings.scoring.')->group(function () {
        Route::get('/', [ScoringSettingsController::class, 'show'])->name('show');
        Route::put('/', [ScoringSettingsController::class, 'update'])->name('update');
        Route::post('reset', [ScoringSettingsController::class, 'reset'])->name('reset');
        Route::post('rescore', [ScoringSettingsController::class, 'rescore'])->name('rescore');
    });
});
