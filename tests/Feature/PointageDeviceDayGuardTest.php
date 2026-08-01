<?php

use App\Models\Pointage;
use App\Models\User;
use App\Support\PointageDeviceDayGuard;
use Carbon\Carbon;

test('same account can punch arrivee and depart on same device same day', function () {
    $user = User::factory()->withoutTwoFactor()->create(['is_active' => true]);
    $fingerprint = 'SN-DEVICE-ABC-123456';

    Pointage::query()->create([
        'user_id' => $user->id,
        'agence_id' => null,
        'type' => 'arrivee',
        'clocked_at' => Carbon::today()->setTime(8, 5),
        'latitude' => 14.7,
        'longitude' => -17.4,
        'qr_verified' => true,
        'biometric_ok' => true,
        'statut' => 'normal',
        'meta' => ['device_fingerprint' => $fingerprint],
    ]);

    $result = PointageDeviceDayGuard::assertAvailableForUser($user, $fingerprint, Carbon::today());

    expect($result['ok'])->toBeTrue();
});

test('another account cannot punch with same device same day', function () {
    $userA = User::factory()->withoutTwoFactor()->create(['is_active' => true]);
    $userB = User::factory()->withoutTwoFactor()->create(['is_active' => true]);
    $fingerprint = 'SN-DEVICE-XYZ-987654';

    Pointage::query()->create([
        'user_id' => $userA->id,
        'agence_id' => null,
        'type' => 'arrivee',
        'clocked_at' => Carbon::today()->setTime(8, 10),
        'latitude' => 14.7,
        'longitude' => -17.4,
        'qr_verified' => true,
        'biometric_ok' => true,
        'statut' => 'normal',
        'meta' => [
            'device_fingerprint' => $fingerprint,
            'device_id' => $fingerprint,
        ],
    ]);

    $result = PointageDeviceDayGuard::assertAvailableForUser($userB, $fingerprint, Carbon::today());

    expect($result['ok'])->toBeFalse()
        ->and($result['message'])->toBe(PointageDeviceDayGuard::BLOCK_MESSAGE);
});

test('another account can reuse device the next day', function () {
    $userA = User::factory()->withoutTwoFactor()->create(['is_active' => true]);
    $userB = User::factory()->withoutTwoFactor()->create(['is_active' => true]);
    $fingerprint = 'SN-DEVICE-NEXT-DAY-001';

    Pointage::query()->create([
        'user_id' => $userA->id,
        'agence_id' => null,
        'type' => 'arrivee',
        'clocked_at' => Carbon::yesterday()->setTime(8, 0),
        'latitude' => 14.7,
        'longitude' => -17.4,
        'qr_verified' => true,
        'biometric_ok' => true,
        'statut' => 'normal',
        'meta' => ['device_fingerprint' => $fingerprint],
    ]);

    $result = PointageDeviceDayGuard::assertAvailableForUser($userB, $fingerprint, Carbon::today());

    expect($result['ok'])->toBeTrue();
});

test('web user-agent fingerprints are ignored', function () {
    expect(PointageDeviceDayGuard::isUsableFingerprint('web_'.hash('sha256', 'Mozilla/5.0')))->toBeFalse();
});

test('android Build.ID style fingerprints are ignored to avoid false collisions', function () {
    expect(PointageDeviceDayGuard::isUsableFingerprint('TP1A.220624.014'))->toBeFalse();
    expect(PointageDeviceDayGuard::isUsableFingerprint('N2G48H'))->toBeFalse();
    expect(PointageDeviceDayGuard::isUsableFingerprint('unknown'))->toBeFalse();
    expect(PointageDeviceDayGuard::isUsableFingerprint('0123456789ABCDEF'))->toBeFalse();
});

test('android ANDROID_ID style fingerprints remain usable', function () {
    expect(PointageDeviceDayGuard::isUsableFingerprint('9774d56d682e549c'))->toBeTrue();
    expect(PointageDeviceDayGuard::isUsableFingerprint('SN-DEVICE-REAL-UNIQUE-001'))->toBeTrue();
});

test('weak Build.ID does not block another account', function () {
    $userA = User::factory()->withoutTwoFactor()->create(['is_active' => true]);
    $userB = User::factory()->withoutTwoFactor()->create(['is_active' => true]);
    $weak = 'TP1A.220624.014';

    Pointage::query()->create([
        'user_id' => $userA->id,
        'agence_id' => null,
        'type' => 'arrivee',
        'clocked_at' => Carbon::today()->setTime(8, 10),
        'latitude' => 14.7,
        'longitude' => -17.4,
        'qr_verified' => true,
        'biometric_ok' => true,
        'statut' => 'normal',
        'meta' => [
            'device_fingerprint' => $weak,
            'device_id' => $weak,
        ],
    ]);

    // Empreinte faible → garde désactivé (pas de blocage multi-comptes fiable).
    $result = PointageDeviceDayGuard::assertAvailableForUser($userB, $weak, Carbon::today());

    expect($result['ok'])->toBeTrue();
});
