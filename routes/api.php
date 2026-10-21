<?php

use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AvailabilityController;
use App\Http\Controllers\Api\DoctorController;
use App\Http\Controllers\Api\MedicalRecordController;
use App\Http\Controllers\Api\PatientController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Controllers\Api\SettingController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:6,1');
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:6,1');
Route::get('/services', [ServiceController::class, 'index']);
Route::get('/settings', [SettingController::class, 'show']);
Route::get('/settings/hours', [SettingController::class, 'hours']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);
    Route::apiResource('services', ServiceController::class)->only(['store', 'update', 'destroy']);
    Route::put('/settings', [SettingController::class, 'update']);
    Route::put('/settings/hours', [SettingController::class, 'updateHours']);
    Route::get('/doctors', [DoctorController::class, 'index']);
    Route::apiResource('patients', PatientController::class)->except(['destroy']);
    Route::get('/availability', AvailabilityController::class);
    Route::apiResource('appointments', AppointmentController::class)->except(['destroy']);
    Route::apiResource('patients.records', MedicalRecordController::class)->only(['index', 'store', 'update']);
});
