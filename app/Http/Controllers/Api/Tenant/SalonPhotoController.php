<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Models\SalonPhoto;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Multitenancy\Models\Tenant as CurrentTenant;

class SalonPhotoController extends Controller
{
    public function store(Request $request, Tenant $salon): JsonResponse
    {
        $user = auth('api')->user();
        if (!$user || !$user->can('salon.photos.manage')) {
            return $this->forbidden('You do not have permission to upload salon photos.');
        }

        try {
            $data = $request->validate([
                'photo' => ['required', 'image', 'max:5120'],
                'alt_text' => ['nullable', 'string', 'max:255'],
                'sort_order' => ['nullable', 'integer', 'min:0'],
            ]);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }

        // Enforce tenant scope from X-Tenant header.
        if (!CurrentTenant::checkCurrent() || (int) CurrentTenant::current()->id !== (int) $salon->id) {
            return $this->forbidden('You are not allowed to upload photos for this salon.');
        }

        $path = $request->file('photo')->store('salons/photos', 'public');
        $url = Storage::url($path);

        $photo = SalonPhoto::create([
            'salon_id' => $salon->id,
            'url' => $url,
            'alt_text' => $data['alt_text'] ?? null,
            'sort_order' => $data['sort_order'] ?? 0,
        ]);

        return $this->created([
            'photo' => [
                'id' => $photo->id,
                'url' => $photo->url,
                'alt_text' => $photo->alt_text,
                'sort_order' => $photo->sort_order,
            ],
        ], 'Photo uploaded successfully');
    }
}

