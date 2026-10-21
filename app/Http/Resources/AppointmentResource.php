<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AppointmentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'patient_id' => $this->patient_id,
            'doctor_id' => $this->doctor_id,
            'service_id' => $this->service_id,
            'duration_minutes' => $this->duration_minutes,
            'status' => $this->status->value,
            'scheduled_at' => $this->scheduled_at->format('Y-m-d\TH:i:s'),
            'notes' => $this->notes,
            'patient' => new PatientResource($this->whenLoaded('patient')),
            'doctor' => new UserResource($this->whenLoaded('doctor')),
            'service' => new ServiceResource($this->whenLoaded('service')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
