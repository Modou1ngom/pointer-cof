<?php

use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AttendanceScanController;
use App\Http\Controllers\Api\AttendanceVirtualOtpController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PointageDashboardSummaryController;
use App\Http\Controllers\Api\PointageTodayController;
use App\Http\Controllers\Api\Pointrust\QrController;
use App\Http\Controllers\Api\ProfileController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API — préfixe /api
|--------------------------------------------------------------------------
| POINTRUST (mobile) : authentification Sanctum Bearer sur les routes protégées.
| Génération QR admin : toujours JWT via middleware pointrust + pointrust.admin.
|--------------------------------------------------------------------------
*/

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:20,1');
Route::post('/verify-otp', [AuthController::class, 'verifyOtp'])->middleware('throttle:20,1');
Route::post('/register-device', [DeviceController::class, 'store'])->middleware('throttle:60,1');

Route::middleware(['auth:sanctum', 'reject_otp_pending'])->group(function () {
    Route::post('/attendance/scan', [AttendanceScanController::class, 'validateScan'])->middleware('throttle:120,1');
    Route::post('/attendance/virtual/request-otp', [AttendanceVirtualOtpController::class, 'requestOtp'])->middleware('throttle:20,1');
    Route::post('/attendance/checkin', [AttendanceController::class, 'checkin'])->middleware('throttle:60,1');
    Route::post('/attendance/checkout', [AttendanceController::class, 'checkout'])->middleware('throttle:60,1');
    Route::get('/attendance/history', [AttendanceController::class, 'history'])->middleware('throttle:120,1');
    Route::get('/declarations', [\App\Http\Controllers\Api\DeclarationController::class, 'index'])->middleware('throttle:120,1');
    Route::post('/declarations', [\App\Http\Controllers\Api\DeclarationController::class, 'store'])->middleware('throttle:30,1');
    Route::get('/declarations/{declaration}', [\App\Http\Controllers\Api\DeclarationController::class, 'show'])->middleware('throttle:120,1');
    Route::get('/pointage/today', [PointageTodayController::class, 'show'])->middleware('throttle:120,1');
    Route::get('/pointage/dashboard-summary', [PointageDashboardSummaryController::class, 'show'])->middleware('throttle:120,1');
    Route::get('/profile', [ProfileController::class, 'show'])->middleware('throttle:120,1');
    Route::get('/notifications', [NotificationController::class, 'index'])->middleware('throttle:120,1');
});

Route::middleware(['pointrust', 'pointrust.admin'])->group(function () {
    Route::get('/qr/generate', [QrController::class, 'generate']);
});

