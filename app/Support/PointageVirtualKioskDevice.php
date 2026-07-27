<?php

namespace App\Support;

use App\Models\Agence;

/**
 * Un seul téléphone (n° de série) est autorisé pour pointer sur une agence virtuelle.
 * Au premier usage réussi, le serial est verrouillé sur l’agence (compte borne partagé).
 */
final class PointageVirtualKioskDevice
{
    public const REQUIRED_MESSAGE = 'Numéro de série de l’appareil requis pour cette agence virtuelle.';

    public const MISMATCH_MESSAGE = 'Cet appareil n’est pas autorisé pour cette agence virtuelle. Un seul téléphone enregistré peut pointer ici.';

    /**
     * @return array{ok: bool, message?: string, bound?: bool, serial?: string}
     */
    public static function assertAuthorized(Agence $agence, ?string $serial, bool $allowBind = true): array
    {
        if (! $agence->isVirtual()) {
            return ['ok' => true];
        }

        $serial = MobileDeviceId::normalize((string) $serial);
        if ($serial === '' || ! PointageDeviceDayGuard::isUsableFingerprint($serial)) {
            return ['ok' => false, 'message' => self::REQUIRED_MESSAGE];
        }

        $locked = MobileDeviceId::normalize((string) ($agence->kiosk_serial_number ?? ''));

        if ($locked === '') {
            if (! $allowBind) {
                return [
                    'ok' => false,
                    'message' => 'Aucun téléphone n’est encore enregistré pour cette agence virtuelle. Contactez les RH.',
                ];
            }

            $agence->forceFill(['kiosk_serial_number' => $serial])->save();

            return ['ok' => true, 'bound' => true, 'serial' => $serial];
        }

        if (! hash_equals($locked, $serial)) {
            return ['ok' => false, 'message' => self::MISMATCH_MESSAGE];
        }

        return ['ok' => true, 'bound' => false, 'serial' => $serial];
    }
}
