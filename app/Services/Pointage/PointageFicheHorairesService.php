<?php

namespace App\Services\Pointage;

use App\Models\Attendance;
use App\Models\Pointage;
use App\Models\Profil;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class PointageFicheHorairesService
{
    /**
     * @return Collection<int, array<string, string>>
     */
    public function buildRowsForMonth(string $mois, ?int $userId = null): Collection
    {
        try {
            $monthStart = Carbon::createFromFormat('Y-m', $mois)->startOfMonth();
        } catch (\Throwable) {
            $monthStart = Carbon::now()->startOfMonth();
        }
        $monthEnd = $monthStart->copy()->endOfMonth();

        $pointages = Pointage::query()
            ->with(['user.profil'])
            ->whereBetween('clocked_at', [$monthStart, $monthEnd])
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId))
            ->orderBy('clocked_at')
            ->get();

        $attendances = Attendance::query()
            ->with(['user.profil'])
            ->whereBetween('recorded_at', [$monthStart, $monthEnd])
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId))
            ->orderBy('recorded_at')
            ->get();

        /** @var array<string, array{user: User, date: string, arrivee: ?Carbon, depart: ?Carbon}> $days */
        $days = [];

        foreach ($pointages as $p) {
            $key = $p->user_id.'|'.$p->clocked_at->toDateString();
            if (! isset($days[$key])) {
                $days[$key] = [
                    'user' => $p->user,
                    'date' => $p->clocked_at->toDateString(),
                    'arrivee' => null,
                    'depart' => null,
                ];
            }
            if ($p->type === 'arrivee') {
                $days[$key]['arrivee'] = $p->clocked_at;
            } elseif ($p->type === 'depart') {
                $days[$key]['depart'] = $p->clocked_at;
            }
        }

        foreach ($attendances as $a) {
            $type = in_array($a->type, ['checkin', 'arrivee'], true) ? 'arrivee' : (in_array($a->type, ['checkout', 'depart'], true) ? 'depart' : null);
            if ($type === null) {
                continue;
            }
            $key = $a->user_id.'|'.$a->recorded_at->toDateString();
            if (! isset($days[$key])) {
                $days[$key] = [
                    'user' => $a->user,
                    'date' => $a->recorded_at->toDateString(),
                    'arrivee' => null,
                    'depart' => null,
                ];
            }
            if ($type === 'arrivee' && $days[$key]['arrivee'] === null) {
                $days[$key]['arrivee'] = $a->recorded_at;
            }
            if ($type === 'depart') {
                $days[$key]['depart'] = $a->recorded_at;
            }
        }

        $rows = collect($days)
            ->map(fn (array $day) => $this->buildRow($day['user'], $day['date'], $day['arrivee'], $day['depart']))
            ->filter(fn (array $row) => $row['name'] !== '')
            ->sortBy([
                ['name', 'asc'],
                ['date_sort', 'asc'],
            ])
            ->values()
            ->map(function (array $row): array {
                unset($row['date_sort']);

                return $row;
            });

        return $rows;
    }

    /**
     * @return array<string, string>
     */
    public function buildRow(User $user, string $dateStr, ?Carbon $arriveeAt, ?Carbon $departAt): array
    {
        $name = $this->displayName($user);
        $date = Carbon::parse($dateStr);

        $heureArrivee = (string) config('pointage.heure_arrivee', '08:00');
        $heureDepart = (string) config('pointage.heure_depart', '17:00');
        $heureArriveeAjustee = (string) config('pointage.heure_arrivee_ajustee', $heureArrivee);
        $heureDepartAjustee = (string) config('pointage.heure_depart_ajustee', $heureDepart);
        $toleranceMinutes = (int) config('pointage.tolerance_minutes', 10);
        $baseHeuresJour = (float) config('pointage.base_heures_jour_reference', 8);

        $limiteRetard = $date->copy()->setTimeFromTimeString($heureArrivee)->addMinutes($toleranceMinutes);
        $limiteDepart = $date->copy()->setTimeFromTimeString($heureDepart);

        $withinArrivalTolerance = $arriveeAt !== null && $arriveeAt->lte($limiteRetard);
        $withinDepartRule = $departAt !== null && $departAt->lte($limiteDepart);

        $hArrivee = $arriveeAt ? $this->formatClockTime($arriveeAt) : '';
        $hDepart = $departAt ? $this->formatClockTime($departAt) : '';

        $hAjustArrivee = '';
        if ($arriveeAt !== null) {
            $hAjustArrivee = $withinArrivalTolerance
                ? $this->formatTimeShort($heureArriveeAjustee)
                : $this->formatClockTimeShort($arriveeAt);
        }

        $hAjustDepart = '';
        if ($departAt !== null) {
            $hAjustDepart = $withinDepartRule
                ? $this->formatTimeShort($heureDepartAjustee)
                : $this->formatClockTimeShort($departAt);
        }

        $totalBrut = '';
        if ($arriveeAt && $departAt && $departAt->gt($arriveeAt)) {
            $totalBrut = $this->minutesToHourColon((int) $arriveeAt->diffInMinutes($departAt));
        }

        $totalAjustCalc = '';
        if ($arriveeAt && $departAt) {
            $arrAdj = $withinArrivalTolerance
                ? $date->copy()->setTimeFromTimeString($heureArriveeAjustee)
                : $arriveeAt->copy();
            $depAdj = $withinDepartRule
                ? $date->copy()->setTimeFromTimeString($heureDepartAjustee)
                : $departAt->copy();
            if ($depAdj->gt($arrAdj)) {
                $totalAjustCalc = $this->minutesToHourColon((int) $arrAdj->diffInMinutes($depAdj));
            }
        }

        $journeeStandard = $withinArrivalTolerance && $withinDepartRule;
        $totalAjustJournee = '';
        if ($arriveeAt && $departAt) {
            if ($journeeStandard) {
                $totalAjustJournee = $this->minutesToHourColon((int) round($baseHeuresJour * 60));
            } elseif ($totalAjustCalc !== '') {
                $totalAjustJournee = $totalAjustCalc;
            }
        }

        return [
            'name' => $name,
            'date_sort' => $dateStr,
            'date' => $date->format('d/m/Y'),
            'h_arrivee' => $hArrivee,
            'h_depart' => $hDepart,
            'h_ajust_arrivee' => $hAjustArrivee,
            'h_ajust_depart' => $hAjustDepart,
            'total' => $totalBrut,
            'total_ajust_calc' => $totalAjustCalc,
            'total_ajust_journee' => $totalAjustJournee,
        ];
    }

    /**
     * @return Collection<int, array{id: int, label: string}>
     */
    public function employesActifsPourExport(): Collection
    {
        return Profil::query()
            ->where('statut', 'actif')
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->whereExists(function ($q): void {
                $q->selectRaw('1')
                    ->from('users')
                    ->whereColumn('users.email', 'profiles.email')
                    ->where('users.is_active', true);
            })
            ->orderBy('nom')
            ->orderBy('prenom')
            ->get()
            ->map(function (Profil $profil): ?array {
                $user = User::query()
                    ->where('email', $profil->email)
                    ->where('is_active', true)
                    ->first();
                if ($user === null) {
                    return null;
                }

                return [
                    'id' => $user->id,
                    'label' => trim(($profil->prenom ?? '').' '.($profil->nom ?? '')),
                ];
            })
            ->filter()
            ->values();
    }

    private function displayName(User $user): string
    {
        $user->loadMissing('profil');
        $p = $user->profil;
        if ($p) {
            return trim(($p->prenom ?? '').' '.($p->nom ?? ''));
        }

        return trim((string) ($user->name ?? $user->email ?? ''));
    }

    private function formatClockTime(Carbon $at): string
    {
        return $at->format('g:i:s A');
    }

    private function formatClockTimeShort(Carbon $at): string
    {
        return $at->format('G:i');
    }

    private function formatTimeShort(string $time): string
    {
        $parts = explode(':', $time);
        $h = (int) ($parts[0] ?? 0);
        $m = (int) ($parts[1] ?? 0);

        return $m > 0 ? sprintf('%d:%02d', $h, $m) : (string) $h;
    }

    private function minutesToHourColon(int $minutes): string
    {
        if ($minutes <= 0) {
            return '0:00';
        }
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        return sprintf('%d:%02d', $h, $m);
    }
}
