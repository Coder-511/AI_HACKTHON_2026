<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $owner = $request->user()?->id === $this->user_id;
        $private = $owner && $request->user()->submissionAccess()['account_state'] !== 'banned';
        $data = ['id' => $this->id, 'tracking_code' => $this->tracking_code, 'status' => $this->status,
            'title' => $this->public_title, 'summary' => $this->public_summary, 'category' => $this->category,
            'created_at' => $this->created_at?->toIso8601String(), 'published_at' => $this->published_at?->toIso8601String()];
        if ($private) {
            $data += ['description' => $this->description, 'governorate' => $this->governorate,
                'latitude' => $this->latitude, 'longitude' => $this->longitude, 'citizen_reason' => $this->citizen_reason,
                'guidance' => $this->guidance, 'version' => $this->version];
            $data['title'] = $this->title;
        } elseif ($owner) {
            $data['guidance'] = $this->guidance;
        }

        return $data;
    }
}
