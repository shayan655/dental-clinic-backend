<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ContactMessageResource;
use App\Models\ContactMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class ContactMessageController extends Controller
{
    public function index(Request $request) {
        Gate::authorize('viewAny', ContactMessage::class);

        $query = ContactMessage::query()->orderByDesc('created_at')->orderByDesc('id')->limit(200);

        if ( $request->query('status') === 'unread') {
            $query->whereNull('read_at');
        }

        return ContactMessageResource::collection($query->get())
            ->additional(['unread_count' => ContactMessage::whereNull('read_at')->count()]);
    }

    public function update(Request $request, ContactMessage $contactMessage): ContactMessageResource {
        Gate::authorize('update', $contactMessage);

        $data = $request->validate(['read' => ['required', 'boolean']]);

        $contactMessage->forceFill([
            'read_at' => $data['read'] ? ($contactMessage->read_at ?? now()) : null,
        ])->save();

        return new ContactMessageResource($contactMessage);
    }

    public function destroy(ContactMessage $contactMessage): Response {
        Gate::authorize('delete', $contactMessage);

        $contactMessage->delete();

        return response()->noContent();
    }

    public function unreadCount(): JsonResponse
    {
        Gate::authorize('viewAny', ContactMessage::class);

        return response()->json(['count' => ContactMessage::whereNull('read_at')->count()]);
    }
}
