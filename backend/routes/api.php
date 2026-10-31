<?php

use App\Http\Controllers\Admin\PermissionController;
use App\Http\Controllers\Admin\ProfileController;
use App\Http\Controllers\Admin\UnitController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\AuditEventController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\DocumentLifecycleController;
use App\Http\Controllers\DocumentShareController;
use App\Jobs\VerifyDocumentIntegrity;
use App\Models\DocumentType;
use App\Models\DocumentVersion;
use App\Models\OrganizationalUnit;
use App\Services\UnitAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Login uses the web session and CSRF middleware even without an Origin header.
    Route::post('auth/login', [AuthController::class, 'login'])->middleware(['web', 'throttle:5,1']);

    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        Route::prefix('admin')->name('admin.')->group(function () {
            Route::middleware('permission:users.manage')->group(function () {
                Route::apiResource('users', UserController::class)->except('destroy');
            });
            Route::middleware('permission:profiles.manage')->group(function () {
                Route::apiResource('profiles', ProfileController::class);
                Route::apiResource('permissions', PermissionController::class)->only('index', 'show');
            });
            Route::middleware('permission:units.manage')->group(function () {
                Route::apiResource('units', UnitController::class)->except('destroy');
            });
        });
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::get('audit-events', [AuditEventController::class, 'index'])->name('audit-events.index');
        Route::get('audit-events/{event}', [AuditEventController::class, 'show'])->whereNumber('event')->name('audit-events.show');
        Route::get('documents/{document}/audit-events', [AuditEventController::class, 'document'])->name('documents.audit-events');
        Route::get('share-recipients', [DocumentShareController::class, 'recipients'])->name('shares.recipients');
        Route::get('shares/received', [DocumentShareController::class, 'received'])->name('shares.received');
        Route::get('versions/{version}/shares', [DocumentShareController::class, 'index'])->name('shares.index');
        Route::post('versions/{version}/shares', [DocumentShareController::class, 'store'])->name('shares.store')->middleware('throttle:30,1');
        Route::post('shares/{share}/revoke', [DocumentShareController::class, 'revoke'])->name('shares.revoke');
        Route::get('shares/{share}', [DocumentShareController::class, 'show'])->name('shares.access');
        Route::get('shares/{share}/download', [DocumentShareController::class, 'download'])->name('shares.download');
        Route::get('shared/{token}', [DocumentShareController::class, 'showToken'])->where('token', '[a-f0-9]{64}')->name('shared.show');
        Route::get('shared/{token}/download', [DocumentShareController::class, 'downloadToken'])->where('token', '[a-f0-9]{64}')->name('shared.download');
        Route::post('auth/logout', [AuthController::class, 'logout'])->middleware('web');
        Route::get('dashboard', DashboardController::class);
        Route::get('document-types', fn () => DocumentType::where('is_active', true)->orderBy('name')->get());
        Route::get('units', function (Request $request, UnitAccess $access) {
            $query = OrganizationalUnit::where('is_active', true);
            if (! $request->user()->hasPermission('access.global')) {
                $query->whereIn('id', $access->ids($request->user()));
            }

            return $query->orderBy('name')->get();
        });
        Route::get('documents', [DocumentController::class, 'index']);
        Route::post('documents', [DocumentController::class, 'store']);
        Route::get('documents/{document}', [DocumentController::class, 'show']);
        Route::patch('documents/{document}', [DocumentLifecycleController::class, 'update'])->name('documents.update');
        Route::patch('documents/{document}/producing-unit', [DocumentLifecycleController::class, 'move'])->name('documents.move');
        Route::post('documents/{document}/archive', [DocumentLifecycleController::class, 'archive'])->name('documents.archive');
        Route::post('documents/{document}/restore', [DocumentLifecycleController::class, 'restore'])->name('documents.restore');
        Route::post('documents/{document}/versions', [DocumentController::class, 'addVersion']);
        Route::get('versions/{version}/download', [DocumentController::class, 'download']);
        Route::post('versions/{version}/integrity-checks', function (Request $request, DocumentVersion $version) {
            Gate::authorize('verifyIntegrity', $version->document);
            VerifyDocumentIntegrity::dispatch($version->id, $request->user()->id);

            return response()->json(['message' => 'Verificação agendada.'], 202);
        })->middleware('throttle:10,1');
        Route::get('versions/{version}/integrity-checks', function (DocumentVersion $version) {
            Gate::authorize('view', $version->document);

            return $version->integrityChecks()->orderByDesc('id')->paginate(20);
        });
    });
});
