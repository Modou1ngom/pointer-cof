<?php

namespace App\Http\Requests\Api;

use App\Http\Requests\Api\Concerns\MergesMobileRequestKeys;
use App\Support\PointageQrScanUrl;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AttendanceStoreRequest extends FormRequest
{
    use MergesMobileRequestKeys;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->mergeSnakeFromCamelPairs([
            ['qr_payload', 'qrPayload'],
            ['biometric_nonce', 'biometricNonce'],
            ['device_id', 'deviceId'],
            ['serial_number', 'serialNumber'],
            ['otp_code', 'otpCode'],
        ]);

        if ($this->filled('matricule')) {
            $this->merge([
                'matricule' => \App\Support\PointageVirtualMatriculeAuth::normalize((string) $this->input('matricule')),
            ]);
        }

        if ($this->filled('email')) {
            $this->merge([
                'email' => \App\Support\PointageVirtualEmailAuth::normalize((string) $this->input('email')),
            ]);
        }
        if ($this->filled('device_id')) {
            $this->merge([
                'device_id' => \App\Support\MobileDeviceId::normalize((string) $this->input('device_id')),
            ]);
        }

        if ($this->filled('serial_number')) {
            $this->merge([
                'serial_number' => \App\Support\MobileDeviceId::normalize((string) $this->input('serial_number')),
            ]);
        }

        $raw = (string) ($this->input('qr_payload') ?? '');
        if ($raw !== '') {
            $this->merge([
                'qr_payload' => PointageQrScanUrl::normalizeScannedContent($raw),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'qr_payload' => ['required', 'string', 'min:8', 'max:512'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'biometric_nonce' => ['nullable', 'string', 'max:2048'],
            'device_id' => ['nullable', 'string', 'max:128'],
            'serial_number' => ['nullable', 'string', 'max:128'],
            'matricule' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'otp_code' => ['nullable', 'string', 'max:12'],
            'type' => ['nullable', 'string', Rule::in(['checkin', 'checkout', 'arrivee', 'depart'])],
        ];
    }
}
