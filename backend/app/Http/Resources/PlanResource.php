<?php

namespace App\Http\Resources;

use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Plan */
class PlanResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $tier = $this->tier();

        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'slug' => $this->slug,
            'tier_level' => $this->tier_level,
            'tier' => $tier->slug(),
            'description' => $this->description,
            'features' => $this->features ?? [],
            'is_active' => $this->is_active,
            'sort' => $this->sort,
            'prices' => PlanPriceResource::collection($this->whenLoaded('prices')),
        ];
    }
}
