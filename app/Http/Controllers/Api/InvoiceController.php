<?php

namespace App\Http\Controllers\Api;

use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInvoiceRequest;
use App\Http\Requests\UpdateInvoiceRequest;
use App\Http\Resources\InvoiceResource;
use App\Models\Invoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class InvoiceController extends Controller
{
    public function index(Request $request) {
        Gate::authorize('viewAny', Invoice::class);

        $query = Invoice::visibleTo($request->user())
            ->with('patient')
            ->orderBy('issued_at')
            ->orderByDesc('id');

        if ($request->filled('patient_id')) {
            $query->where('patient_id', $request->integer('patient_id'));
        }

        return InvoiceResource::collection($query->get());
    }

    public function store(StoreInvoiceRequest $request): JsonResponse {
        $invoice = Invoice::create([
            ...$request->validated(),
            'status' => InvoiceStatus::Pending,
            'issued_at' => now(),
        ]);

        return (new InvoiceResource($invoice->load('patient')))->response()->setStatusCode(201);
    }

    public function update(UpdateInvoiceRequest $request, Invoice $invoice): InvoiceResource
    {
        abort_unless(
            $invoice->status === InvoiceStatus::Pending,
            422,
            'Paid or cancelled invoices cannot be changed.'
        );

        $changes = $request->validated();

        // Paying is recorded in person, so the timestamp is "now"
        if (($changes['status'] ?? null) === InvoiceStatus::Paid->value) {
            $changes['paid_at'] = now();
        }

        $invoice->update($changes);

        return new InvoiceResource($invoice->load('patient'));
    }
}