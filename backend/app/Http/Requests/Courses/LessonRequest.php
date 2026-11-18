<?php

namespace App\Http\Requests\Courses;

use App\Courses\RichText;
use App\Enums\LessonType;
use App\Models\Lesson;
use App\Rules\OwnVideo;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Authorization is done in the controller (via the parent course). */
class LessonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:160'],
            'type' => [$creating ? 'required' : 'sometimes', Rule::enum(LessonType::class)],
            'content' => ['sometimes', 'nullable', 'string', 'max:100000'],
            'video_id' => ['sometimes', 'nullable', 'uuid', new OwnVideo(($lesson = $this->route('lesson')) instanceof Lesson ? $lesson->video_id : null)],
            'duration_seconds' => ['sometimes', 'integer', 'min:0', 'max:86400'],
            'is_preview' => ['sometimes', 'boolean'],
            'drip_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('content')) {
            $this->merge(['content' => RichText::clean($this->input('content'))]);
        }
    }
}
