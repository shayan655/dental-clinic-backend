<?php

use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AvailabilityController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\ContactMessageController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DoctorController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\MedicalRecordController;
use App\Http\Controllers\Api\PatientController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\Api\StaffController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:6,1');
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:6,1');
Route::get('/services', [ServiceController::class, 'index']);
Route::get('/settings', [SettingController::class, 'show']);
Route::get('/settings/hours', [SettingController::class, 'hours']);
Route::post('contact', [ContactController::class, 'store'])->middleware('throttle:5,10');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);
    Route::apiResource('services', ServiceController::class)->only(['store', 'update', 'destroy']);
    Route::put('/settings', [SettingController::class, 'update']);
    Route::put('/settings/hours', [SettingController::class, 'updateHours']);
    Route::get('/doctors', [DoctorController::class, 'index']);
    Route::apiResource('patients', PatientController::class)->except(['destroy']);
    Route::post('/patients/{patient}/account', [PatientController::class, 'createAccount']);
    Route::get('/availability', AvailabilityController::class);
    Route::apiResource('appointments', AppointmentController::class)->except(['destroy']);
    Route::apiResource('patients.records', MedicalRecordController::class)->only(['index', 'store', 'update']);
    Route::apiResource('invoices', InvoiceController::class)->only(['index', 'store', 'update']);
    Route::apiResource('staff', StaffController::class)->except(['show']);
    Route::put('/profile', [ProfileController::class, 'update']);
    Route::put('/profile/password', [ProfileController::class, 'updatePassword'])->middleware('throttle:6,1');
    Route::prefix('dashboard')->group(function () {
        Route::get('/manager', [DashboardController::class, 'manager']);
        Route::get('/doctor', [DashboardController::class, 'doctor']);
        Route::get('/receptionist', [DashboardController::class, 'receptionist']);
        Route::get('/patient', [DashboardController::class, 'patient']);
    });
    Route::get('/contact-messages', [ContactMessageController::class, 'index']);
    Route::put('contact-messages/{contactMessage}', [ContactMessageController::class, 'update']);
    Route::delete('contact-messages/{contactMessage}', [ContactMessageController::class, 'destroy']);
    Route::get('/contact-messages/unread-count', [ContactMessageController::class, 'unreadCount']);
});
