<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use App\Notifications\NewContactMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;

class ContactController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:32'],
            'subject' => ['required', 'string', 'max:160'],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
            'website' => ['prohibited'], // honeypot
        ]);

        $message = ContactMessage::create([
            ...$data,
            'user_id' => $request->user('sanctum')?->getAuthIdentifier(),
            'ip_address' => $request->ip(),
        ]);

        Notification::route('mail', (string) config('lms.contact_email'))->notify(new NewContactMessage($message));

        return response()->json(['message' => 'Thanks! We will get back to you shortly.', 'data' => ['id' => $message->uuid]], 201);
    }
}
