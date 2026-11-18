<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\InstructorResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class InstructorController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'featured' => ['sometimes', 'boolean'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $instructors = User::role(Role::Instructor->value)
            ->where('status', UserStatus::Active)
            ->with('profile')
            ->when($request->boolean('featured'), fn ($q) => $q->whereHas('profile', fn ($p) => $p->where('is_featured', true)))
            ->orderBy('name')
            ->limit($data['limit'] ?? 12)
            ->get();

        return InstructorResource::collection($instructors);
    }
}
