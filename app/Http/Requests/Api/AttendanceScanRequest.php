<?php

namespace App\Http\Requests\Api;

use App\Http\Requests\Api\Concerns\MergesMobileRequestKeys;
use App\Support\PointageQrScanUrl;
use Illuminate\Foundation\Http\FormRequest;

class AttendanceScanRequest extends FormRequest
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
            ['qr_token', 'qrToken'],
            ['device_id', 'deviceId'],
            ['serial_number', 'serialNumber'],
        ]);

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

        $raw = (string) ($this->input('qr_payload') ?? $this->input('qr_token') ?? '');
        if ($raw !== '') {
            $normalized = PointageQrScanUrl::normalizeScannedContent($raw);
            $this->merge([
                'qr_payload' => $normalized,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'qr_payload' => ['required', 'string', 'min:8', 'max:2048'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'device_id' => ['nullable', 'string', 'max:128'],
            'serial_number' => ['nullable', 'string', 'max:128'],
        ];
    }

    public function qrContent(): string
    {
        return (string) $this->validated('qr_payload');
    }
}
