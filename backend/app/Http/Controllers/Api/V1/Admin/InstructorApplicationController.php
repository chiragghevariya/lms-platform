<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\InstructorApplicationController as Payload;
use App\Http\Controllers\Controller;
use App\Instructors\InstructorApplicationService;
use App\Models\InstructorApplication;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InstructorApplicationController extends Controller
{
    public function __construct(private readonly InstructorApplicationService $service) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['status' => ['nullable', Rule::in(['pending', 'approved', 'rejected'])]]);
        $apps = InstructorApplication::with('user')->where('status', $data['status'] ?? 'pending')->oldest()->paginate(30);

        return response()->json([
            'data' => $apps->getCollection()->map(fn (InstructorApplication $a) => Payload::payload($a, true))->values(),
            'meta' => ['total' => $apps->total(), 'current_page' => $apps->currentPage(), 'last_page' => $apps->lastPage()],
        ]);
    }

    public function approve(Request $request, InstructorApplication $application): JsonResponse
    {
        $data = $request->validate(['notes' => ['nullable', 'string', 'max:2000']]);

        return response()->json(['data' => Payload::payload($this->service->approve($application->load('user'), $this->admin($request), $data['notes'] ?? null), true)]);
    }

    public function reject(Request $request, InstructorApplication $application): JsonResponse
    {
        $data = $request->validate(['notes' => ['required', 'string', 'min:10', 'max:2000']]);

        return response()->json(['data' => Payload::payload($this->service->reject($application->load('user'), $this->admin($request), $data['notes']), true)]);
    }

    private function admin(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
