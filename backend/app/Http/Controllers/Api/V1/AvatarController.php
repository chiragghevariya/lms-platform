<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Rules\CleanUpload;
use App\Services\AvatarService;
use Illuminate\Http\Request;

class AvatarController extends Controller
{
    public function store(Request $request, AvatarService $avatars): UserResource
    {
        $request->validate([
            'avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:min_width=64,min_height=64,max_width=4000,max_height=4000', new CleanUpload],
        ]);

        /** @var User $user */
        $user = $request->user();

        return new UserResource($avatars->store($user, $request->file('avatar')));
    }

    public function destroy(Request $request, AvatarService $avatars): UserResource
    {
        /** @var User $user */
        $user = $request->user();

        return new UserResource($avatars->remove($user));
    }
}
