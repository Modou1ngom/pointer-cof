<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\RegisterDeviceRequest;
use App\Models\User;
use App\Support\MobileApiAccountResource;
use App\Support\MobileDeviceRegistration;
use Illuminate\Http\JsonResponse;
use Laravel\Sanctum\PersonalAccessToken;

class DeviceController extends Controller
{
    public function store(RegisterDeviceRequest $request): JsonResponse
    {
        if (! (bool) config('pointrust.device_register_enabled', true)) {
            return response()->json(['message' => 'Non disponible'], 404);
        }

        $pat = PersonalAccessToken::findToken((string) $request->bearerToken());
        $user = $pat?->tokenable;

        if (! $user instanceof User || ! $user->is_active) {
            return response()->json(['message' => 'Non authentifié'], 401);
        }

        $abilities = $pat->abilities ?? [];
        if (in_array('otp-pending', $abilities, true) && ! in_array('*', $abilities, true)) {
            return response()->json(['message' => 'OTP requis'], 403);
        }

        MobileDeviceRegistration::register($user, $request->validated());
        $user->profilCollaborateurAssocie();

        return response()->json([
            'message' => 'Appareil enregistré',
            'user' => MobileApiAccountResource::toArray($user, $request),
        ], 200);
    }
}
