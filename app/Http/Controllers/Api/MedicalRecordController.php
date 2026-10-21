<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMedicalRecordRequest;
use App\Http\Requests\UpdateMedicalRecordRequest;
use App\Http\Resources\MedicalRecordResource;
use App\Models\MedicalRecord;
use App\Models\Patient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class MedicalRecordController extends Controller
{
    public function index(Patient $patient) {
        Gate::authorize('viewAny', [MedicalRecord::class, $patient]);

        return MedicalRecordResource::collection(
            $patient->medical_records()->orderByDesc('created_at')->orderByDesc('id')->get()
        );
    }

    public function store(StoreMedicalRecordRequest $request, Patient $patient): JsonResponse {
        $record = $patient->medical_records()->create([
            ...$request->validated(),
            'doctor_id' => $request->user()->id,
        ]);

        return (new MedicalRecordResource($record))->response()->setStatusCode(201);
    }

    public function update(UpdateMedicalRecordRequest $request, Patient $patient, MedicalRecord $record): MedicalRecordResource {
        $record->update($request->validated());

        return new MedicalRecordResource($record);
    }
}
