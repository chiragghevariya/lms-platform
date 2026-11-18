<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CategoryController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return CategoryResource::collection(Category::withCount('courses')->orderBy('sort')->orderBy('name')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $category = Category::create([...$data, 'slug' => $this->uniqueSlug($data['slug'] ?? $data['name'])]);

        return (new CategoryResource($category->loadCount('courses')))->response()->setStatusCode(201);
    }

    public function update(Request $request, Category $category): CategoryResource
    {
        $data = $this->validated($request, $category);
        if (isset($data['slug'])) {
            $data['slug'] = $this->uniqueSlug($data['slug'], $category->id);
        }
        $category->update($data);

        return new CategoryResource($category->loadCount('courses'));
    }

    public function destroy(Category $category): JsonResponse
    {
        if ($category->courses()->exists()) {
            throw ValidationException::withMessages(['category' => 'Move this category\'s courses to another category first.']);
        }
        $category->delete();

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Category $category = null): array
    {
        return $request->validate([
            'name' => [$category ? 'sometimes' : 'required', 'string', 'max:120', Rule::unique('categories', 'name')->ignore($category?->id)],
            'slug' => ['sometimes', 'nullable', 'string', 'max:140', 'regex:/^[a-z0-9-]+$/'],
            'description' => ['nullable', 'string', 'max:500'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:65000'],
        ]);
    }

    private function uniqueSlug(string $value, ?int $ignore = null): string
    {
        $base = Str::slug($value) ?: 'category';
        $slug = $base;
        for ($i = 2; Category::where('slug', $slug)->when($ignore, fn ($q) => $q->whereKeyNot($ignore))->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }
}
