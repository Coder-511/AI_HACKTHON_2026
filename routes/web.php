<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\IdentityRegistrationController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:reads')->group(function (): void {
    Route::get('/', [PageController::class, 'home'])->name('home');
    Route::get('/news', [PageController::class, 'home'])->name('news.index');
    Route::get('/privacy', [PageController::class, 'privacy'])->name('privacy');
    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
});
Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'loginForm'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:authentication')->name('login.store');
    Route::get('/register', [IdentityRegistrationController::class, 'create'])->name('register');
    Route::post('/register/identity', [IdentityRegistrationController::class, 'start'])->middleware('throttle:identity-registration')->name('register.identity');
    Route::post('/register', [IdentityRegistrationController::class, 'store'])->middleware('throttle:authentication')->name('register.store');
    Route::get('/forgot-password', [AuthController::class, 'forgotForm'])->name('password.request');
    Route::post('/forgot-password', [AuthController::class, 'forgot'])->middleware('throttle:authentication')->name('password.email');
    Route::get('/reset-password/{token}', [AuthController::class, 'resetForm'])->name('password.reset');
    Route::post('/reset-password', [AuthController::class, 'reset'])->middleware('throttle:authentication')->name('password.update');
});
Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/verify-email', [AuthController::class, 'verificationNotice'])->name('verification.notice');
    Route::get('/verify-email/{id}/{hash}', [AuthController::class, 'verify'])->middleware(['signed', 'throttle:6,1'])->name('verification.verify');
    Route::post('/email/verification-notification', [AuthController::class, 'resend'])->middleware('throttle:3,1')->name('verification.send');
    Route::get('/confirm-password', [AuthController::class, 'confirmForm'])->name('password.confirm');
    Route::post('/confirm-password', [AuthController::class, 'confirm'])->middleware('throttle:authentication')->name('password.confirm.store');
    Route::get('/admin/mfa/setup', [AuthController::class, 'mfaSetup'])->name('mfa.setup');
    Route::post('/admin/mfa/setup', [AuthController::class, 'mfaEnable'])->middleware('throttle:mfa')->name('mfa.enable');
    Route::get('/admin/mfa/challenge', [AuthController::class, 'mfaChallenge'])->name('mfa.challenge');
    Route::post('/admin/mfa/challenge', [AuthController::class, 'mfaVerify'])->middleware('throttle:mfa')->name('mfa.verify');
    Route::get('/my-reports', [ReportController::class, 'mine'])->middleware('throttle:reads')->name('reports.mine');
    Route::get('/account/access', [AccountController::class, 'access'])->middleware('throttle:reads')->name('account.access');
    Route::post('/account/appeal', [AccountController::class, 'appeal'])->middleware('throttle:appeals')->name('account.appeal');
    Route::post('/account/privacy', [AccountController::class, 'privacy'])->middleware('throttle:appeals')->name('account.privacy');
    Route::get('/attachments/{attachment}', [ReportController::class, 'download'])->middleware(['signed', 'throttle:reads'])->name('attachments.download');
    Route::middleware(['submission', 'throttle:submission'])->group(function (): void {
        Route::get('/reports/create', [ReportController::class, 'create'])->name('reports.create');
        Route::post('/reports', [ReportController::class, 'store'])->name('reports.store');
        Route::patch('/reports/{report}', [ReportController::class, 'information'])->name('reports.update');
        Route::post('/reports/{report}/additional-information', [ReportController::class, 'information'])->name('reports.information');
        Route::post('/reports/{report}/attachments', [ReportController::class, 'attachment'])->name('reports.attachments');
    });
    Route::post('/reports/{report}/appeal', [ReportController::class, 'appeal'])->middleware('throttle:appeals')->name('reports.appeal');
    Route::prefix('admin')->name('admin.')->middleware(['admin.secure', 'throttle:reads'])->group(function (): void {
        Route::get('/', [AdminController::class, 'index'])->name('index');
        Route::get('/reports/{report}', [AdminController::class, 'show'])->name('reports.show');
        Route::get('/users', [AdminController::class, 'users'])->name('users');
        Route::middleware('recent_password')->group(function (): void {
            Route::post('/reports/{report}/decision', [AdminController::class, 'decide'])->name('reports.decide');
            Route::post('/users/{user}/restriction', [AdminController::class, 'restrict'])->name('users.restrict');
            Route::post('/appeals/{appeal}/decision', [AdminController::class, 'appealDecision'])->name('appeals.decide');
            Route::post('/privacy/{privacyRequest}/decision', [AdminController::class, 'privacyDecision'])->name('privacy.decide');
        });
    });
});
Route::get('/reports/{report}', [ReportController::class, 'show'])->middleware('throttle:reads')->name('reports.show');
