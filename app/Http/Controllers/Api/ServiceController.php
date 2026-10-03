<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreServiceRequest;
use App\Http\Requests\UpdateServiceRequest;
use App\Http\Resources\ServiceResource;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class ServiceController extends Controller
{
    public function index() {
        return ServiceResource::collection(Service::orderBy('name')->get());
    }

    public function store(StoreServiceRequest $request): JsonResponse {
        $service = Service::create($request->validated());

        return (new ServiceResource($service))->response()->setStatusCode(201);
    }

    public function update(UpdateServiceRequest $request, Service $service): ServiceResource {
        $service->update($request->validated());

        return new ServiceResource($service);
    }

    public function destroy(Service $service): Response {
        Gate::authorize('delete', $service);

        $service->delete();

        return response()->noContent();
    }
}
