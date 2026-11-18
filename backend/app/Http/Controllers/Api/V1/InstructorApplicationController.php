<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Instructors\InstructorApplicationService;
use App\Models\InstructorApplication;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** "Become an instructor": submit an application and see its status. */
class InstructorApplicationController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $app = InstructorApplication::where('user_id', $user->id)->latest('id')->first();

        return response()->json(['data' => [
            'is_instructor' => $user->hasRole('instructor'),
            'application' => $app ? self::payload($app) : null,
        ]]);
    }

    public function store(Request $request, InstructorApplicationService $service): JsonResponse
    {
        $data = $request->validate([
            'headline' => ['required', 'string', 'min:10', 'max:160'],
            'expertise' => ['required', 'array', 'min:1', 'max:10'],
            'expertise.*' => ['string', 'max:40'],
            'bio' => ['required', 'string', 'min:50', 'max:3000'],
            'motivation' => ['required', 'string', 'min:50', 'max:3000'],
            'links' => ['nullable', 'array', 'max:5'],
            'links.*' => ['url:https', 'max:300'],
        ]);
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => self::payload($service->apply($user, $data))], 201);
    }

    /** @return array<string, mixed> */
    public static function payload(InstructorApplication $a, bool $admin = false): array
    {
        return [
            'id' => $a->uuid,
            'headline' => $a->headline,
            'expertise' => $a->expertise,
            'bio' => $a->bio,
            'motivation' => $a->motivation,
            'links' => $a->links ?? [],
            'status' => $a->status,
            'review_notes' => $a->review_notes,
            'reviewed_at' => $a->reviewed_at?->toIso8601String(),
            'created_at' => $a->created_at?->toIso8601String(),
            ...($admin ? ['applicant' => ['id' => $a->user->uuid, 'name' => $a->user->name, 'email' => $a->user->email]] : []),
        ];
    }
}
