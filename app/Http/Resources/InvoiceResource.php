<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceResource extends JsonResource
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
            'appointment_id' => $this->appointment_id,
            'amount' => (float) $this->amount,
            'status' => $this->status->value,
            'issued_at' => $this->issued_at->format('Y-m-d\TH:i:s'),
            'paid_at' => $this->paid_at?->format('Y-m-d\TH:i:s'),
            'patient' => new PatientResource($this->whenLoaded('patient')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
