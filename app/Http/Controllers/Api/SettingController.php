<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateClinicSettingRequest;
use App\Http\Requests\UpdateWorkingHoursRequest;
use App\Http\Resources\ClinicSettingResource;
use App\Http\Resources\WorkingHourResource;
use App\Models\ClinicSetting;
use App\Models\WorkingHour;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SettingController extends Controller
{
    public function show(): ClinicSettingResource {
        return new ClinicSettingResource(ClinicSetting::current());
    }

    public function update(UpdateClinicSettingRequest $request): ClinicSettingResource {
        $settings = ClinicSetting::current();
        $settings->update($request->validated());

        return new ClinicSettingResource($settings);
    }

    public function hours() {
        return WorkingHourResource::collection(
            WorkingHour::orderBy('day_of_week')->get()
        );
    }

    public function updateHours(UpdateWorkingHoursRequest $request) {
        $dayNumbers = array_flip(WorkingHour::DAYS);

        DB::transaction(function () use ($request, $dayNumbers) {
            foreach ($request->validated('hours') as $day) {
                WorkingHour::where('day_of_week', $dayNumbers[$day['day']])->update([
                    'is_open' => $day['is_open'],
                    'open_time' => $day['open_time'],
                    'close_time' => $day['close_time'],
                ]);
            }
        });

        return $this->hours();
    }
}
