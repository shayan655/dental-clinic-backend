<?php

namespace App\Http\Resources;

use App\Models\WorkingHour;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkingHourResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'day' => WorkingHour::DAYS[$this->day_of_week],
            'is_open' => $this->is_open,
            'open_time' => substr($this->open_time, 0, 5),
            'close_time' => substr($this->close_time, 0, 5),
        ];
    }
}
