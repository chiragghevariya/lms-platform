<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Stores avatars on AVATAR_DISK (public locally, s3 in production). */
class AvatarService
{
    public function store(User $user, UploadedFile $file): User
    {
        $disk = Storage::disk($this->disk());
        $path = $disk->putFileAs(
            "avatars/{$user->uuid}",
            $file,
            Str::random(24).'.'.($file->guessExtension() ?? 'jpg'),
            ['visibility' => 'public'],
        );

        $this->deleteFile($user);
        $user->forceFill(['avatar_path' => $path ?: null])->save();

        return $user;
    }

    public function remove(User $user): User
    {
        $this->deleteFile($user);
        $user->forceFill(['avatar_path' => null])->save();

        return $user;
    }

    public function url(?string $path): ?string
    {
        return $path ? Storage::disk($this->disk())->url($path) : null;
    }

    private function deleteFile(User $user): void
    {
        if ($user->avatar_path) {
            Storage::disk($this->disk())->delete($user->avatar_path);
        }
    }

    private function disk(): string
    {
        return (string) config('lms.avatar_disk');
    }
}
