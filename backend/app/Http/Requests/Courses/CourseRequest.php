<?php

namespace App\Http\Requests\Courses;

use App\Courses\RichText;
use App\Enums\CourseLevel;
use App\Models\Course;
use App\Rules\OwnVideo;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CourseRequest extends FormRequest
{
    public function authorize(): bool
    {
        $course = $this->route('course');

        return $course instanceof Course
            ? ($this->user()?->can('update', $course) ?? false)
            : ($this->user()?->can('create', Course::class) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creating = ! $this->route('course') instanceof Course;

        return [
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'min:5', 'max:160'],
            'subtitle' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:50000'],
            'category_id' => ['sometimes', 'nullable', 'uuid', 'exists:categories,uuid'],
            'level' => ['sometimes', Rule::enum(CourseLevel::class)],
            'language' => ['sometimes', 'string', 'max:10', 'regex:/^[a-z]{2}(-[A-Z]{2})?$/'],
            'required_tier' => ['sometimes', 'nullable', 'integer', 'between:1,3'],
            'price_amount' => ['sometimes', 'nullable', 'integer', 'min:100', 'required_with:currency'],
            'currency' => ['sometimes', 'nullable', 'required_with:price_amount', Rule::in(config('billing.supported_currencies'))],
            'promo_video_id' => ['sometimes', 'nullable', 'uuid', new OwnVideo(($c = $this->route('course')) instanceof Course ? $c->promo_video_id : null)],
            'tags' => ['sometimes', 'array', 'max:10'],
            'tags.*' => ['string', 'max:40'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('description')) {
            $this->merge(['description' => RichText::clean($this->input('description'))]);
        }
    }
}
