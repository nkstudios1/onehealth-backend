<?php

use App\Http\Controllers\Api\V1\Access\CardController;
use App\Http\Controllers\Api\V1\Access\ContactApprovalController;
use App\Http\Controllers\Api\V1\Access\PatientAccessController;
use App\Http\Controllers\Api\V1\Provider\ProviderAccessController;
use App\Http\Controllers\Api\V1\Records\RecordController;
use Illuminate\Support\Facades\Route;

/*
| Cards, access requests, sessions and records.   URLs: /api/v1/...
*/

// ---- Patient ---------------------------------------------------------------------------------
Route::middleware(['auth:web', 'active', 'type:patient'])->group(function () {
    Route::get('cards/me', [CardController::class, 'show'])->name('cards.show');
    Route::post('cards/activate', [CardController::class, 'activate'])->middleware('throttle:auth-step')->name('cards.activate');

    Route::get('access/requests/pending', [PatientAccessController::class, 'pending'])->name('access.pending');
    Route::post('access/requests/{id}/approve', [PatientAccessController::class, 'approve'])->middleware('throttle:auth-step')->name('access.approve');
    Route::post('access/requests/{id}/deny', [PatientAccessController::class, 'deny'])->middleware('throttle:auth-step')->name('access.deny');
    Route::get('access/sessions', [PatientAccessController::class, 'sessions'])->name('access.sessions');
    Route::post('access/sessions/{id}/revoke', [PatientAccessController::class, 'revoke'])->middleware('throttle:auth-step')->name('access.revoke');

    Route::get('records', [RecordController::class, 'index'])->name('records.index');
    Route::post('records', [RecordController::class, 'store'])->middleware('throttle:auth-step')->name('records.store');
    Route::patch('records/{id}/visibility', [RecordController::class, 'visibility'])->name('records.visibility');
});

// ---- Emergency contact with an account (in the app) -------------------------------------------
Route::middleware(['auth:web', 'active'])->group(function () {
    Route::get('access/contact-requests', [ContactApprovalController::class, 'index'])->name('access.contact.index');
    Route::post('access/contact-requests/{id}/approve', [ContactApprovalController::class, 'approve'])->middleware('throttle:otp-verify')->name('access.contact.approve');
});

// ---- Emergency contact from the SMS link (no account) -----------------------------------------
// GET only describes the request. Approving is a separate POST (link scanners open every link).
Route::get('access/approvals/{token}', [ContactApprovalController::class, 'show'])->middleware('throttle:otp-verify')->name('access.approval.show');
Route::post('access/approvals/{token}/approve', [ContactApprovalController::class, 'approveByLink'])->middleware('throttle:otp-verify')->name('access.approval.approve');

// ---- Hospital ---------------------------------------------------------------------------------
Route::prefix('provider')->middleware(['auth:web', 'active', 'type:org_admin,org_staff'])->name('provider.')->group(function () {
    Route::post('access-requests', [ProviderAccessController::class, 'scan'])->middleware('throttle:auth-step')->name('scan');
    Route::get('access-requests/{id}', [ProviderAccessController::class, 'show'])->name('request.show');
    Route::post('access-requests/{id}/code', [ProviderAccessController::class, 'submitCode'])->middleware('throttle:otp-verify')->name('request.code');

    Route::get('sessions', [ProviderAccessController::class, 'sessions'])->name('sessions.index');
    Route::get('sessions/{id}/essentials', [ProviderAccessController::class, 'essentials'])->name('sessions.essentials');
    Route::post('sessions/{id}/records', [ProviderAccessController::class, 'writeRecord'])->middleware('throttle:auth-step')->name('sessions.records');
    Route::post('sessions/{id}/checkout', [ProviderAccessController::class, 'checkOut'])->name('sessions.checkout');
});
