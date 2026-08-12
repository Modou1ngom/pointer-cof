<?php

namespace App\Http\Controllers;

use App\Models\Agence;
use App\Models\Departement;
use App\Models\Pointage;
use App\Models\PointageDeclaration;
use App\Models\PointageHoraireProfile;
use App\Models\Profil;
use App\Models\User;
use App\Services\Pointage\PointageHeuresCalculService;
use App\Services\Pointage\PointageHorairesCalendrierService;
use App\Services\Pointage\PointageRecuperationService;
use App\Support\FrenchDateFormat;
use App\Support\PointageDeclarationCouverture;
use App\Support\PointageDeclarationTypes;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PointageRapportController extends Controller
{
    private readonly PointageHeuresCalculService $heures;

    public function __construct(?PointageHeuresCalculService $heures = null)
    {
        $this->heures = $heures ?? new PointageHeuresCalculService;
    }

    public function index(Request $request): Response
    {
        $user = Auth::user();
        abort_unless($user && ($user->isRh() || $user->isAdmin() || $user->isSuperAdmin()), 403);

        $today = Carbon::today();
        $filters = [
            'date' => $request->input('date', $today->toDateString()),
            'mois' => $request->input('mois', $today->format('Y-m')),
            'annee' => $request->input('annee', $today->format('Y')),
            'date_debut' => $request->input('date_debut', $today->copy()->startOfMonth()->toDateString()),
            'date_fin' => $request->input('date_fin', $today->toDateString()),
            'agence_id' => $request->filled('agence_id') ? (int) $request->input('agence_id') : null,
            'departement' => $request->filled('departement') ? (string) $request->input('departement') : null,
            'user_id' => $request->filled('user_id') ? (int) $request->input('user_id') : null,
            'statut' => $request->filled('statut') ? (string) $request->input('statut') : null,
            'format' => 'csv',
        ];

        return Inertia::render('Pointage/ReportingRh', [
            'defaults' => $filters,
            'rapports' => $this->rapportsCatalog(),
            'agences' => Agence::query()->where('actif', true)->orderBy('nom')->get(['id', 'nom']),
            'departements' => Departement::query()->where('actif', true)->orderBy('nom')->pluck('nom')->values(),
            'collaborateurs' => $this->collaborateursOptions(),
        ]);
    }

    public function export(Request $request, PointageRecuperationService $recuperation): StreamedResponse|HttpResponse
    {
        $user = Auth::user();
        abort_unless($user && ($user->isRh() || $user->isAdmin() || $user->isSuperAdmin()), 403);

        $validated = $request->validate([
            'type' => 'required|string|in:'.implode(',', array_column($this->rapportsCatalog(), 'id')),
            'format' => 'nullable|in:csv,pdf',
            'date' => 'nullable|date',
            'mois' => 'nullable|string|regex:/^\d{4}-\d{2}$/',
            'annee' => 'nullable|string|regex:/^\d{4}$/',
            'date_debut' => 'nullable|date',
            'date_fin' => 'nullable|date',
            'agence_id' => 'nullable|integer',
            'departement' => 'nullable|string|max:120',
            'user_id' => 'nullable|integer',
        ]);

        $type = $validated['type'];
        $format = $validated['format'] ?? 'csv';
        $payload = $this->buildRapportPayload($type, $validated, $recuperation);
        $filenameBase = 'rapport-rh-'.$type.'-'.now()->format('Ymd-His');

        if ($format === 'pdf') {
            return $this->pdfDownload($filenameBase.'.pdf', $payload['title'], $payload['headers'], $payload['rows']);
        }

        return $this->csvStream($filenameBase.'.csv', function () use ($payload): \Generator {
            yield $payload['headers'];
            foreach ($payload['rows'] as $row) {
                yield $row;
            }
        });
    }

    public function exportMensuelRh(Request $request): StreamedResponse
    {
        $request->merge(['type' => 'mensuel', 'format' => 'csv']);

        return $this->export($request, app(PointageRecuperationService::class));
    }

    public function exportQuotidien(Request $request): StreamedResponse
    {
        $request->merge(['type' => 'quotidien', 'format' => 'csv']);

        return $this->export($request, app(PointageRecuperationService::class));
    }

    public function exportJournalierRh(Request $request): StreamedResponse
    {
        return $this->exportQuotidien($request);
    }

    public function exportSyntheseRh(Request $request): StreamedResponse
    {
        $request->merge(['type' => 'mensuel', 'format' => 'csv']);

        return $this->export($request, app(PointageRecuperationService::class));
    }

    /**
     * @return list<array{id: string, title: string, description: string, period: string, icon: string, color: string, featured: bool}>
     */
    private function rapportsCatalog(): array
    {
        return [
            [
                'id' => 'quotidien',
                'title' => 'Rapport quotidien',
                'description' => 'Présences du jour : heures effectives, retards, départs anticipés, absences.',
                'period' => 'date',
                'icon' => 'calendar-day',
                'color' => 'blue',
                'featured' => true,
            ],
            [
                'id' => 'hebdomadaire',
                'title' => 'Rapport hebdomadaire',
                'description' => 'Synthèse de la semaine : présences, absences, retards, départs anticipés.',
                'period' => 'semaine',
                'icon' => 'calendar-week',
                'color' => 'green',
                'featured' => true,
            ],
            [
                'id' => 'mensuel',
                'title' => 'Rapport mensuel',
                'description' => 'Synthèse mensuelle : présences, absences, retards, départs anticipés, heures.',
                'period' => 'mois',
                'icon' => 'calendar-month',
                'color' => 'purple',
                'featured' => true,
            ],
            [
                'id' => 'annuel',
                'title' => 'Rapport annuel',
                'description' => 'Synthèse année : effectif, taux de présence / absence, retards et congés.',
                'period' => 'annee',
                'icon' => 'calendar-range',
                'color' => 'orange',
                'featured' => true,
            ],
            [
                'id' => 'absences',
                'title' => 'Rapport absences',
                'description' => 'Déclarations d’absence sur la période.',
                'period' => 'range',
                'icon' => 'user-x',
                'color' => 'red',
                'featured' => true,
            ],
            [
                'id' => 'retards',
                'title' => 'Rapport retards',
                'description' => 'Pointages d’arrivée en retard.',
                'period' => 'range',
                'icon' => 'clock',
                'color' => 'orange',
                'featured' => true,
            ],
            [
                'id' => 'heures_sup',
                'title' => 'Rapport heures sup.',
                'description' => 'Estimation des heures supplémentaires par collaborateur.',
                'period' => 'mois',
                'icon' => 'timer',
                'color' => 'teal',
                'featured' => true,
            ],
            [
                'id' => 'anomalies',
                'title' => 'Rapport anomalies',
                'description' => 'Entrées sans sortie, QR non validé, biométrie manquante.',
                'period' => 'range',
                'icon' => 'alert',
                'color' => 'purple',
                'featured' => true,
            ],
            [
                'id' => 'agence',
                'title' => 'Rapport par agence',
                'description' => 'Effectif, présences, absences, congés, missions et taux par site.',
                'period' => 'range',
                'icon' => 'building',
                'color' => 'blue',
                'featured' => false,
            ],
            [
                'id' => 'departement',
                'title' => 'Rapport par département',
                'description' => 'Effectif, présences, absences, congés, missions et taux par direction.',
                'period' => 'range',
                'icon' => 'users',
                'color' => 'green',
                'featured' => false,
            ],
            [
                'id' => 'individuel',
                'title' => 'Rapport individuel',
                'description' => 'Détail des pointages d’un collaborateur.',
                'period' => 'range_user',
                'icon' => 'user',
                'color' => 'purple',
                'featured' => false,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{title: string, headers: list<string>, rows: list<list<string>>}
     */
    private function buildRapportPayload(string $type, array $filters, PointageRecuperationService $recuperation): array
    {
        [$from, $to, $date, $mois, $annee] = $this->resolvePeriod($filters);

        return match ($type) {
            'quotidien' => $this->rapportQuotidien($date, $filters, $recuperation),
            'hebdomadaire' => $this->rapportHebdomadaire($date, $filters),
            'mensuel' => $this->rapportMensuel($mois, $filters),
            'annuel' => $this->rapportAnnuel($annee, $filters),
            'absences' => $this->rapportAbsences($from, $to, $filters),
            'retards' => $this->rapportRetards($from, $to, $filters),
            'heures_sup' => $this->rapportHeuresSup($mois, $filters),
            'anomalies' => $this->rapportAnomalies($from, $to, $filters),
            'agence' => $this->rapportParAgence($from, $to, $filters),
            'departement' => $this->rapportParDepartement($from, $to, $filters),
            'individuel' => $this->rapportIndividuel($from, $to, $filters, $recuperation),
            default => ['title' => 'Rapport', 'headers' => [], 'rows' => []],
        };
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{0: Carbon, 1: Carbon, 2: Carbon, 3: string, 4: string}
     */
    private function resolvePeriod(array $filters): array
    {
        $date = Carbon::parse($filters['date'] ?? today()->toDateString())->startOfDay();
        $mois = is_string($filters['mois'] ?? null) && preg_match('/^\d{4}-\d{2}$/', $filters['mois'])
            ? $filters['mois']
            : $date->format('Y-m');
        $annee = is_string($filters['annee'] ?? null) && preg_match('/^\d{4}$/', $filters['annee'])
            ? $filters['annee']
            : substr($mois, 0, 4);
        $from = Carbon::parse($filters['date_debut'] ?? $date->copy()->startOfMonth()->toDateString())->startOfDay();
        $to = Carbon::parse($filters['date_fin'] ?? $date->toDateString())->endOfDay();
        if ($to->lt($from)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        return [$from, $to, $date, $mois, $annee];
    }

    /**
     * Template reporting quotidien :
     * Matricule | Employé | Direction | Date | Heure entrée | Heure sortie | Retard | Départ anticipé | Absence | Statut
     * Heure entrée / sortie = heures effectives.
     * Départ anticipé = Oui si heure de sortie effective < heure_depart (défaut 17:00).
     *
     * @param  array<string, mixed>  $filters
     * @return array{title: string, headers: list<string>, rows: list<list<string>>}
     */
    private function rapportQuotidien(Carbon $date, array $filters, PointageRecuperationService $recuperation): array
    {
        $userIds = $this->filteredUserIds($filters);
        $users = User::query()
            ->with('profil')
            ->whereIn('id', $userIds ?: [0])
            ->orderBy('name')
            ->get();

        $pointagesByUser = Pointage::query()
            ->whereIn('user_id', $userIds ?: [0])
            ->whereDate('clocked_at', $date->toDateString())
            ->orderBy('clocked_at')
            ->get()
            ->groupBy('user_id');

        $justifs = PointageDeclarationCouverture::mapPourUsersJour($userIds, $date);

        $heureArriveeRef = $this->hhmmToMinutes((string) config('pointage.heure_arrivee', '08:00'));
        $heureDepartRef = $this->hhmmToMinutes((string) config('pointage.heure_depart', '17:00'));
        $dateLabel = $date->format('d/m/Y');

        $rows = [];
        foreach ($users as $user) {
            /** @var Collection<int, Pointage> $items */
            $items = $pointagesByUser->get($user->id) ?? collect();
            $arrivee = $items->firstWhere('type', 'arrivee');
            $depart = $items
                ->filter(fn (Pointage $p) => $p->type === 'depart')
                ->sortByDesc(fn (Pointage $p) => $p->clocked_at->timestamp)
                ->first();

            $profil = $user->profil;
            $employe = $profil
                ? trim(($profil->prenom ?? '').' '.($profil->nom ?? ''))
                : (string) ($user->full_name ?: $user->name ?: '—');
            if ($employe === '') {
                $employe = '—';
            }

            $ha = $arrivee ? $this->heureEffectiveColon($arrivee) : '—';
            $hd = $depart ? $this->heureEffectiveColon($depart) : '—';

            $retard = '—';
            if ($arrivee !== null) {
                $effMins = $this->effectiveMinutes($arrivee);
                $delta = max(0, $effMins - $heureArriveeRef);
                $retard = sprintf('%02d min', $delta);
            }

            $departAnticipe = '—';
            if ($depart !== null) {
                $departAnticipe = $this->effectiveMinutes($depart) < $heureDepartRef ? 'Oui' : 'Non';
            }

            $isAbsent = $arrivee === null && $depart === null;
            $justif = $justifs->get($user->id) ?? ['couvert' => false];

            if ($isAbsent && ($justif['couvert'] ?? false)) {
                $absence = 'Non';
                $statut = (string) ($justif['label'] ?? 'Justifié');
            } elseif ($isAbsent) {
                $absence = 'Oui';
                $statut = 'Absent';
            } else {
                $absence = 'Non';
                if (($arrivee?->statut ?? null) === 'retard' || ($arrivee !== null && $this->effectiveMinutes($arrivee) > $heureArriveeRef)) {
                    $statut = 'Retard';
                } elseif (($arrivee?->statut ?? null) === 'ferie_auto' || ($depart?->statut ?? null) === 'ferie_auto') {
                    $statut = 'Férié';
                } else {
                    $statut = 'Présent';
                }
            }

            $rows[] = [
                (string) ($profil?->matricule ?: '—'),
                $employe,
                (string) ($profil?->departement ?: '—'),
                $dateLabel,
                $ha,
                $hd,
                $retard,
                $departAnticipe,
                $absence,
                $statut,
            ];
        }

        return [
            'title' => 'Rapport quotidien de présence — '.FrenchDateFormat::date($date),
            'headers' => [
                'Matricule', 'Employé', 'Direction', 'Date',
                'Heure entrée', 'Heure sortie', 'Retard',
                'Départ anticipé', 'Absence', 'Statut',
            ],
            'rows' => $rows,
        ];
    }

    private function heureEffectiveColon(Pointage $p): string
    {
        $meta = $p->meta ?? [];
        if (is_string($meta['heure_effective'] ?? null) && $meta['heure_effective'] !== '') {
            return $this->normalizeHhmmColon((string) $meta['heure_effective']);
        }

        return $p->clocked_at?->format('H:i') ?? '—';
    }

    private function effectiveMinutes(Pointage $p): int
    {
        $meta = $p->meta ?? [];
        if (is_string($meta['heure_effective'] ?? null) && $meta['heure_effective'] !== '') {
            return $this->hhmmToMinutes((string) $meta['heure_effective']);
        }

        if ($p->clocked_at === null) {
            return 0;
        }

        return ((int) $p->clocked_at->format('H')) * 60 + (int) $p->clocked_at->format('i');
    }

    private function hhmmToMinutes(string $hhmm): int
    {
        $clean = str_replace(['h', 'H'], ':', trim($hhmm));
        $parts = explode(':', $clean);

        return ((int) ($parts[0] ?? 0)) * 60 + (int) ($parts[1] ?? 0);
    }

    private function normalizeHhmmColon(string $hhmm): string
    {
        $mins = $this->hhmmToMinutes($hhmm);

        return sprintf('%02d:%02d', intdiv($mins, 60), $mins % 60);
    }

    /**
     * Template reporting hebdomadaire (mêmes colonnes que le mensuel, période = semaine ISO).
     *
     * @param  array<string, mixed>  $filters
     * @return array{title: string, headers: list<string>, rows: list<list<string>>}
     */
    private function rapportHebdomadaire(Carbon $date, array $filters): array
    {
        $weekStart = $date->copy()->startOfWeek(Carbon::MONDAY)->startOfDay();
        $weekEnd = $date->copy()->endOfWeek(Carbon::SUNDAY)->endOfDay();
        $label = FrenchDateFormat::date($weekStart).' → '.FrenchDateFormat::date($weekEnd);

        return $this->rapportSynthesePeriode(
            $weekStart,
            $weekEnd,
            $filters,
            'Rapport hebdomadaire de pointage — '.$label,
        );
    }

    /**
     * Template reporting mensuel :
     * Employé | Jours travaillés | Présences | Absences | Retards | Départs anticipés | Heures travaillées
     * (Heures supplémentaires exclues — barrées sur le template métier.)
     * Heures travaillées = heures effectives.
     * Départ anticipé = sortie effective avant heure_depart (défaut 17:00).
     *
     * @param  array<string, mixed>  $filters
     * @return array{title: string, headers: list<string>, rows: list<list<string>>}
     */
    private function rapportMensuel(string $mois, array $filters): array
    {
            $monthStart = Carbon::createFromFormat('Y-m-d', $mois.'-01')->startOfMonth();
        $monthEnd = $monthStart->copy()->endOfMonth()->endOfDay();

        return $this->rapportSynthesePeriode(
            $monthStart,
            $monthEnd,
            $filters,
            'Rapport mensuel de pointage — '.$mois,
        );
    }

    /**
     * Template reporting annuel :
     * Mois | Effectif moyen | Taux présence | Taux absence | Retards | Congés
     *
     * @param  array<string, mixed>  $filters
     * @return array{title: string, headers: list<string>, rows: list<list<string>>}
     */
    private function rapportAnnuel(string $annee, array $filters): array
    {
        $year = (int) $annee;
        $yearStart = Carbon::create($year, 1, 1)->startOfDay();
        $yearEnd = Carbon::create($year, 12, 31)->endOfDay();

        $userIds = $this->filteredUserIds($filters);
        $effectif = count($userIds);

        $pointagesByUser = Pointage::query()
            ->whereIn('user_id', $userIds ?: [0])
            ->whereBetween('clocked_at', [$yearStart, $yearEnd])
            ->orderBy('clocked_at')
            ->get()
            ->groupBy('user_id');

        $declsByUser = $this->declarationsValidesParUser($userIds, $yearStart, $yearEnd);

        $moisNoms = [
            1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril',
            5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Août',
            9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre',
        ];

        $rows = [];
        for ($m = 1; $m <= 12; $m++) {
            $monthStart = Carbon::create($year, $m, 1)->startOfDay();
        $monthEnd = $monthStart->copy()->endOfMonth()->endOfDay();
            $joursOuvres = $this->joursOuvresDansPeriode($monthStart, $monthEnd);
            $joursOuvresSet = array_fill_keys($joursOuvres, true);
            $joursCount = count($joursOuvres);

            $presences = 0;
            $retards = 0;
            $conges = 0;

            foreach ($userIds as $uid) {
                /** @var Collection<int, Pointage> $events */
                $events = $pointagesByUser->get($uid) ?? collect();
                $byDay = $events
                    ->filter(fn (Pointage $p) => $p->clocked_at->between($monthStart, $monthEnd))
                    ->groupBy(fn (Pointage $p) => $p->clocked_at->format('Y-m-d'));

                $coverage = $this->declarationCoverageByDay(
                    $declsByUser->get($uid) ?? collect(),
                    $joursOuvresSet,
                );

                foreach ($joursOuvres as $day) {
                    /** @var Collection<int, Pointage> $items */
                    $items = $byDay->get($day) ?? collect();
                    $arrivee = $items->firstWhere('type', 'arrivee');
                    $depart = $items
                        ->filter(fn (Pointage $p) => $p->type === 'depart')
                        ->sortByDesc(fn (Pointage $p) => $p->clocked_at->timestamp)
                        ->first();

                    if ($arrivee !== null || $depart !== null) {
                        $presences++;
                        if ($arrivee !== null && $arrivee->statut === 'retard') {
                            $retards++;
                        }

                        continue;
                    }

                    if (($coverage[$day] ?? null) === 'conge') {
                        $conges++;
                    }
                }
            }

            $tauxPresence = $effectif > 0 && $joursCount > 0
                ? (int) round(100 * $presences / ($effectif * $joursCount))
                : 0;
            $tauxAbsence = max(0, 100 - $tauxPresence);

            $rows[] = [
                $moisNoms[$m],
                (string) $effectif,
                $tauxPresence.' %',
                $tauxAbsence.' %',
                (string) $retards,
                (string) $conges,
            ];
        }

        return [
            'title' => 'Rapport annuel de pointage — '.$annee,
            'headers' => [
                'Mois', 'Effectif moyen', 'Taux présence', 'Taux absence', 'Retards', 'Congés',
            ],
            'rows' => $rows,
        ];
    }

    /**
     * Synthèse présence sur une période (hebdo / mensuel).
     *
     * @param  array<string, mixed>  $filters
     * @return array{title: string, headers: list<string>, rows: list<list<string>>}
     */
    private function rapportSynthesePeriode(Carbon $from, Carbon $to, array $filters, string $title): array
    {
        $userIds = $this->filteredUserIds($filters);

        $users = User::query()
            ->with('profil')
            ->whereIn('id', $userIds ?: [0])
            ->orderBy('name')
            ->get();

        $joursOuvres = $this->joursOuvresDansPeriode($from, $to);
        $joursTravailles = count($joursOuvres);
        $heureDepartRef = $this->hhmmToMinutes((string) config('pointage.heure_depart', '17:00'));

        $pointagesByUser = Pointage::query()
            ->whereIn('user_id', $userIds ?: [0])
            ->whereBetween('clocked_at', [$from, $to])
            ->orderBy('clocked_at')
            ->get()
            ->groupBy('user_id');

        $rows = [];
        foreach ($users as $user) {
            $profil = $user->profil;
            $employe = $profil
                ? trim(($profil->prenom ?? '').' '.($profil->nom ?? ''))
                : (string) ($user->full_name ?: $user->name ?: '—');
            if ($employe === '') {
                $employe = '—';
            }

            /** @var Collection<int, Pointage> $events */
            $events = $pointagesByUser->get($user->id) ?? collect();
            $byDay = $events->groupBy(fn (Pointage $p) => $p->clocked_at->format('Y-m-d'));

            $presences = 0;
            $retards = 0;
            $departsAnticipes = 0;

            foreach ($joursOuvres as $day) {
                /** @var Collection<int, Pointage> $items */
                $items = $byDay->get($day) ?? collect();
                $arrivee = $items->firstWhere('type', 'arrivee');
                $depart = $items
                    ->filter(fn (Pointage $p) => $p->type === 'depart')
                    ->sortByDesc(fn (Pointage $p) => $p->clocked_at->timestamp)
                    ->first();

                if ($arrivee === null && $depart === null) {
                    continue;
                }

                $presences++;

                if ($arrivee !== null && $arrivee->statut === 'retard') {
                    $retards++;
                }
                if ($depart !== null && $this->effectiveMinutes($depart) < $heureDepartRef) {
                    $departsAnticipes++;
                }
            }

            $absences = max(0, $joursTravailles - $presences);
            $mins = $this->heures->minutesFromCollection($events);

            $rows[] = [
                $employe,
                (string) $joursTravailles,
                (string) $presences,
                (string) $absences,
                (string) $retards,
                (string) $departsAnticipes,
                $this->heures->formatMinutes($mins['effective']),
            ];
        }

        usort($rows, fn (array $a, array $b) => strcasecmp($a[0], $b[0]));

        return [
            'title' => $title,
            'headers' => [
                'Employé', 'Jours travaillés', 'Présences', 'Absences',
                'Retards', 'Départs anticipés', 'Heures travaillées',
            ],
            'rows' => $rows,
        ];
    }

    /**
     * Snapshot dashboard Reporting RH pour une date de référence.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    /**
     * Données KPI / graphiques pour le dashboard RH (réutilisé par Reporting RH).
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function buildDashboard(Carbon $dateRef, array $filters): array
    {
        return $this->buildReportingDashboard($dateRef, $filters);
    }

    private function buildReportingDashboard(Carbon $dateRef, array $filters): array
    {
        $baseUserIds = $this->filteredUserIds($filters);
        $statutFilter = ! empty($filters['statut']) ? (string) $filters['statut'] : null;
        if ($statutFilter === 'tous' || $statutFilter === '') {
            $statutFilter = null;
        }

        $userIds = $statutFilter !== null
            ? $this->filterUserIdsByStatutJour($dateRef, $baseUserIds, $statutFilter)
            : $baseUserIds;

        $effectif = count($userIds);
        $yesterday = $dateRef->copy()->subDay();

        $todayStats = $this->dayPresenceStats($dateRef, $userIds);
        $yestIds = $statutFilter !== null
            ? $this->filterUserIdsByStatutJour($yesterday, $baseUserIds, $statutFilter)
            : $baseUserIds;
        $yestStats = $this->dayPresenceStats($yesterday, $yestIds);

        $presentsPct = $effectif > 0 ? (int) round(100 * $todayStats['presents'] / $effectif) : 0;
        $yestPresentsPct = count($yestIds) > 0 ? (int) round(100 * $yestStats['presents'] / count($yestIds)) : 0;

        $monthStart = $dateRef->copy()->startOfMonth();
        $monthEnd = $dateRef->copy()->endOfMonth()->endOfDay();
        $heuresSupMinutes = 0;
        foreach ($userIds as $uid) {
            $mins = $this->heures->minutesUserBetween((int) $uid, $monthStart, $monthEnd);
            $sup = $this->heures->heuresSupplementaires($mins['effective'], $mins['jours_complets']);
            $heuresSupMinutes += $sup['minutes'];
        }

        $prevMonthStart = $dateRef->copy()->subMonthNoOverflow()->startOfMonth();
        $prevMonthEnd = $dateRef->copy()->subMonthNoOverflow()->endOfMonth()->endOfDay();
        $prevSupMinutes = 0;
        foreach ($userIds as $uid) {
            $mins = $this->heures->minutesUserBetween((int) $uid, $prevMonthStart, $prevMonthEnd);
            $sup = $this->heures->heuresSupplementaires($mins['effective'], $mins['jours_complets']);
            $prevSupMinutes += $sup['minutes'];
        }

        $evolution = [];
        for ($i = 6; $i >= 0; $i--) {
            $d = $dateRef->copy()->subDays($i);
            $idsDay = $statutFilter !== null
                ? $this->filterUserIdsByStatutJour($d, $baseUserIds, $statutFilter)
                : $baseUserIds;
            $s = $this->dayPresenceStats($d, $idsDay);
            $taux = count($idsDay) > 0 ? (int) round(100 * $s['presents'] / count($idsDay)) : 0;
            $evolution[] = [
                'date' => $d->toDateString(),
                'label' => $d->translatedFormat('d M'),
                'taux' => $taux,
            ];
        }

        $byDept = $this->presenceParDepartementPourJour($dateRef, $userIds);
        $alertesDetail = $this->alertesRhPourJour($dateRef, $userIds, $todayStats);

        $moyenne7j = count($evolution) > 0
            ? round(array_sum(array_column($evolution, 'taux')) / count($evolution), 1)
            : 0.0;
        $objectifPresence = 95;

        $pct = fn (int $n): float => $effectif > 0 ? round(100 * $n / $effectif, 1) : 0.0;

        return [
            'date_label' => FrenchDateFormat::dateLong($dateRef),
            'date_short' => $dateRef->locale('fr')->isoFormat('D MMMM YYYY'),
            'kpis' => [
                'effectif' => $effectif,
                'effectif_delta' => 0,
                'presents' => $todayStats['presents'],
                'presents_pct' => $presentsPct,
                'presents_delta_pct' => $presentsPct - $yestPresentsPct,
                'retards' => $todayStats['retards'],
                'retards_pct' => $pct($todayStats['retards']),
                'retards_delta_pct' => $this->pctDelta($todayStats['retards'], $yestStats['retards']),
                'absents' => $todayStats['absents'],
                'absents_pct' => $pct($todayStats['absents']),
                'absents_delta_pct' => $this->pctDelta($todayStats['absents'], $yestStats['absents']),
                'conges' => $todayStats['conges'],
                'conges_pct' => $pct($todayStats['conges']),
                'conges_delta' => $todayStats['conges'] - $yestStats['conges'],
                'missions' => $todayStats['missions'],
                'missions_pct' => $pct($todayStats['missions']),
                'missions_delta' => $todayStats['missions'] - $yestStats['missions'],
                'permissions' => $todayStats['permissions'],
                'permissions_pct' => $pct($todayStats['permissions']),
                'permissions_delta' => $todayStats['permissions'] - $yestStats['permissions'],
                'heures_sup' => $this->heures->formatMinutes($heuresSupMinutes),
                'heures_sup_delta' => $this->heures->formatMinutes($heuresSupMinutes - $prevSupMinutes),
                'heures_sup_delta_positive' => ($heuresSupMinutes - $prevSupMinutes) >= 0,
            ],
            'evolution_7j' => $evolution,
            'evolution_meta' => [
                'moyenne_7j' => $moyenne7j,
                'mois_en_cours' => $presentsPct,
                'mois_delta_pct' => $presentsPct - $yestPresentsPct,
                'objectif' => $objectifPresence,
            ],
            'presence_departements' => $byDept,
            'repartition' => $this->repartitionStatutsDetail($dateRef, $userIds),
            'alertes' => array_sum(array_column($alertesDetail, 'count')),
            'alertes_list' => $alertesDetail,
            'pointages_temps_reel' => $this->pointagesTempsReelPourJour($dateRef, $userIds),
            'statut_types' => [
                ['value' => 'present', 'label' => 'Présent'],
                ['value' => 'retard', 'label' => 'Retard'],
                ['value' => 'absence', 'label' => 'Absence'],
                ['value' => 'conge_annuel', 'label' => 'Congé annuel'],
                ['value' => 'conge_maladie', 'label' => 'Congé maladie'],
                ['value' => 'permission_exceptionnelle', 'label' => 'Permission exceptionnelle'],
                ['value' => 'mission', 'label' => 'Mission'],
                ['value' => 'formation', 'label' => 'Formation'],
            ],
        ];
    }

    /**
     * Filtre les utilisateurs selon leur statut de présence / demande du jour.
     *
     * @param  list<int>  $userIds
     * @return list<int>
     */
    private function filterUserIdsByStatutJour(Carbon $day, array $userIds, string $statut): array
    {
        if ($userIds === []) {
            return [];
        }

        $statut = PointageDeclarationTypes::normalize($statut);
        $joursSet = [$day->toDateString() => true];
        $isOuvre = in_array($day->toDateString(), $this->joursOuvresDansPeriode($day, $day), true);

        $pointagesByUser = Pointage::query()
            ->whereIn('user_id', $userIds)
            ->whereDate('clocked_at', $day->toDateString())
            ->orderBy('clocked_at')
            ->get()
            ->groupBy('user_id');
        $declsByUser = $this->declarationsValidesParUser($userIds, $day, $day);

        $out = [];
        foreach ($userIds as $uid) {
            $items = $pointagesByUser->get($uid) ?? collect();
            $coverage = $this->declarationCoverageByDay(
                $declsByUser->get($uid) ?? collect(),
                $joursSet,
            );
            $declKind = $coverage[$day->toDateString()] ?? null;
            $status = $this->classifyPresenceJour($items, $declKind);
            $kind = $status['kind'];

            $match = match ($statut) {
                'present' => $kind === 'present',
                'retard' => $kind === 'present' && $status['is_retard'],
                'absence' => $isOuvre && ($kind === null || $kind === 'absence'),
                'mission' => $kind === 'mission',
                'conge_annuel', 'conge_maladie', 'permission_exceptionnelle', 'formation' => $kind === $statut,
                default => true,
            };

            if ($match) {
                $out[] = (int) $uid;
            }
        }

        return $out;
    }

    /**
     * Derniers pointages du jour pour le widget « temps réel ».
     *
     * @param  list<int>  $userIds
     * @return list<array{id: int, employe: string, type: string, type_label: string, heure: string, agence: string}>
     */
    private function pointagesTempsReelPourJour(Carbon $day, array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        return Pointage::query()
            ->with(['user.profil', 'agence'])
            ->whereIn('user_id', $userIds)
            ->whereDate('clocked_at', $day->toDateString())
            ->where(function ($q): void {
                $q->whereNull('statut')
                    ->orWhere('statut', '!=', 'ferie_auto');
            })
            ->orderByDesc('clocked_at')
            ->limit(8)
            ->get()
            ->map(function (Pointage $p): array {
                $profil = $p->user?->profil;
                $nom = trim(($profil?->prenom ?? '').' '.($profil?->nom ?? ''));
                if ($nom === '') {
                    $nom = (string) ($p->user?->name ?? 'Collaborateur');
                }

                return [
                    'id' => (int) $p->id,
                    'employe' => $nom,
                    'type' => (string) $p->type,
                    'type_label' => $p->type === 'arrivee' ? 'Arrivée' : 'Départ',
                    'heure' => $p->heureReelleAffichee(),
                    'agence' => (string) ($p->agence?->nom ?? '—'),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $userIds
     * @param  array{presents: int, retards: int, absents: int, conges: int, missions: int, permissions: int}  $todayStats
     * @return list<array{id: string, label: string, count: int, severity: string}>
     */
    private function alertesRhPourJour(Carbon $day, array $userIds, array $todayStats): array
    {
        $heureDepartRef = $this->hhmmToMinutes((string) config('pointage.heure_depart', '17:00'));

        $pointages = Pointage::query()
            ->whereIn('user_id', $userIds ?: [0])
            ->whereDate('clocked_at', $day->toDateString())
            ->orderBy('clocked_at')
            ->get()
            ->groupBy('user_id');

        $retards15 = 0;
        $oublis = 0;
        $departsAnticipes = 0;
        $anomalies = 0;

        foreach ($userIds as $uid) {
            /** @var Collection<int, Pointage> $items */
            $items = $pointages->get($uid) ?? collect();
            $arrivee = $items->firstWhere('type', 'arrivee');
            $depart = $items
                ->filter(fn (Pointage $p) => $p->type === 'depart')
                ->sortByDesc(fn (Pointage $p) => $p->clocked_at->timestamp)
                ->first();

            if ($arrivee !== null && $arrivee->statut === 'retard') {
                $eff = $this->effectiveMinutes($arrivee);
                $ref = $this->hhmmToMinutes((string) config('pointage.heure_arrivee', '08:00'));
                if (($eff - $ref) > 15) {
                    $retards15++;
                }
            }

            if ($arrivee !== null && $depart === null) {
                $oublis++;
                $anomalies++;
            } elseif ($arrivee === null && $depart !== null) {
                $anomalies++;
            }

            if ($depart !== null && $this->effectiveMinutes($depart) < $heureDepartRef) {
                $departsAnticipes++;
            }

            if ($items->contains(fn (Pointage $p) => ! $p->qr_verified)) {
                $anomalies++;
            }
        }

        return [
            ['id' => 'retards_15', 'label' => 'Retards > 15 min', 'count' => $retards15, 'severity' => 'orange'],
            ['id' => 'absences', 'label' => 'Absences non justifiées', 'count' => $todayStats['absents'], 'severity' => 'red'],
            ['id' => 'oublis', 'label' => 'Oubli de pointage', 'count' => $oublis, 'severity' => 'orange'],
            ['id' => 'departs', 'label' => 'Départs avant l’heure', 'count' => $departsAnticipes, 'severity' => 'orange'],
            ['id' => 'anomalies', 'label' => 'Anomalies de pointage', 'count' => $anomalies, 'severity' => 'red'],
        ];
    }

    private function pctDelta(int $current, int $previous): float
    {
        if ($previous <= 0) {
            return $current > 0 ? 100.0 : 0.0;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    /**
     * @param  list<int>  $userIds
     * @return array{presents: int, retards: int, absents: int, conges: int, missions: int, permissions: int}
     */
    private function dayPresenceStats(Carbon $day, array $userIds): array
    {
        $joursSet = [$day->toDateString() => true];
        $isOuvre = in_array($day->toDateString(), $this->joursOuvresDansPeriode($day, $day), true);

        $pointagesByUser = Pointage::query()
            ->whereIn('user_id', $userIds ?: [0])
            ->whereDate('clocked_at', $day->toDateString())
            ->orderBy('clocked_at')
            ->get()
            ->groupBy('user_id');

        $declsByUser = $this->declarationsValidesParUser($userIds, $day, $day);

        $presents = 0;
        $retards = 0;
        $absents = 0;
        $conges = 0;
        $missions = 0;
        $permissions = 0;

        foreach ($userIds as $uid) {
            /** @var Collection<int, Pointage> $items */
            $items = $pointagesByUser->get($uid) ?? collect();
            $coverage = $this->declarationCoverageByDay(
                $declsByUser->get($uid) ?? collect(),
                $joursSet,
            );
            $declKind = $coverage[$day->toDateString()] ?? null;
            $status = $this->classifyPresenceJour($items, $declKind);

            if ($status['kind'] === 'mission') {
                $missions++;

                continue;
            }
            if ($status['kind'] === 'permission_exceptionnelle') {
                $permissions++;

                continue;
            }
            if (in_array($status['kind'], ['conge_annuel', 'conge_maladie', 'formation'], true)) {
                $conges++;

                continue;
            }
            if ($status['kind'] === 'present') {
                $presents++;
                if ($status['is_retard']) {
                    $retards++;
                }

                continue;
            }

            if (! $isOuvre) {
                continue;
            }

            $absents++;
        }

        return compact('presents', 'retards', 'absents', 'conges', 'missions', 'permissions');
    }

    /**
     * Présence réelle vs déclaration justifiée (ignore les pointages synthétiques RH / férié).
     *
     * @param  Collection<int, Pointage>  $items
     * @return array{kind: string|null, is_retard: bool}
     */
    private function classifyPresenceJour(Collection $items, ?string $declarationKind): array
    {
        $justificatifs = [
            'conge_annuel',
            'conge_maladie',
            'permission_exceptionnelle',
            'mission',
            'formation',
            'absence',
        ];
        if ($declarationKind !== null && in_array($declarationKind, $justificatifs, true)) {
            return ['kind' => $declarationKind, 'is_retard' => false];
        }

        $real = $items->filter(fn (Pointage $p) => ! $p->isSynthetic());
        $arrivee = $real->first(fn (Pointage $p) => $p->type === 'arrivee');
        $depart = $real->first(fn (Pointage $p) => $p->type === 'depart');

        if ($arrivee !== null || $depart !== null) {
            return [
                'kind' => 'present',
                'is_retard' => $arrivee !== null && $arrivee->statut === 'retard',
            ];
        }

        return ['kind' => null, 'is_retard' => false];
    }

    /**
     * Répartition des statuts (Présent + types de déclaration) pour le donut Reporting RH.
     *
     * @param  list<int>  $userIds
     * @return array{total: int, items: list<array{value: string, label: string, count: int, pct: float, color: string}>}
     */
    private function repartitionStatutsDetail(Carbon $day, array $userIds): array
    {
        $order = [
            'present' => '#22C55E',
            'absence' => '#EF4444',
            'conge_annuel' => '#A855F7',
            'conge_maladie' => '#EC4899',
            'permission_exceptionnelle' => '#F59E0B',
            'mission' => '#14B8A6',
            'formation' => '#3B82F6',
        ];
        $counts = array_fill_keys(array_keys($order), 0);

        $joursSet = [$day->toDateString() => true];
        $isOuvre = in_array($day->toDateString(), $this->joursOuvresDansPeriode($day, $day), true);

        $pointagesByUser = Pointage::query()
            ->whereIn('user_id', $userIds ?: [0])
            ->whereDate('clocked_at', $day->toDateString())
            ->get()
            ->groupBy('user_id');
        $declsByUser = $this->declarationsValidesParUser($userIds, $day, $day);

        foreach ($userIds as $uid) {
            $items = $pointagesByUser->get($uid) ?? collect();
            $coverage = $this->declarationCoverageByDay(
                $declsByUser->get($uid) ?? collect(),
                $joursSet,
            );
            $declKind = $coverage[$day->toDateString()] ?? null;
            $status = $this->classifyPresenceJour($items, $declKind);

            if ($status['kind'] === 'present') {
                $counts['present']++;

                continue;
            }

            if (! $isOuvre) {
                continue;
            }

            $kind = $status['kind'] ?? 'absence';
            if (! isset($counts[$kind])) {
                $kind = 'absence';
            }
            $counts[$kind]++;
        }

        return $this->formatRepartitionStatuts($counts, $order);
    }

    /**
     * @param  array<string, int>  $counts
     * @param  array<string, string>  $order
     * @return array{total: int, items: list<array{value: string, label: string, count: int, pct: float, color: string}>}
     */
    private function formatRepartitionStatuts(array $counts, array $order): array
    {
        $total = array_sum($counts);
        $items = [];
        foreach ($order as $value => $color) {
            $count = (int) ($counts[$value] ?? 0);
            $items[] = [
                'value' => $value,
                'label' => $value === 'present' ? 'Présent' : PointageDeclarationTypes::label($value),
                'count' => $count,
                'pct' => $total > 0 ? round(100 * $count / $total, 1) : 0.0,
                'color' => $color,
            ];
        }

        return ['total' => $total, 'items' => $items];
    }

    /**
     * @param  list<int>  $userIds
     * @return list<array{nom: string, taux: int, presents: int, effectif: int}>
     */
    private function presenceParDepartementPourJour(Carbon $day, array $userIds): array
    {
        $users = User::query()
            ->with('profil')
            ->whereIn('id', $userIds ?: [0])
            ->get();

        $pointagesByUser = Pointage::query()
            ->whereIn('user_id', $userIds ?: [0])
            ->whereDate('clocked_at', $day->toDateString())
            ->get()
            ->groupBy('user_id');

        /** @var array<string, array{effectif: int, presents: int}> $groups */
        $groups = [];
        foreach ($users as $user) {
            $dept = (string) ($user->profil?->departement ?: 'Sans direction');
            if (! isset($groups[$dept])) {
                $groups[$dept] = ['effectif' => 0, 'presents' => 0];
            }
            $groups[$dept]['effectif']++;
            $items = $pointagesByUser->get($user->id) ?? collect();
            $hasReal = $items->contains(fn (Pointage $p) => ! $p->isSynthetic());
            if ($hasReal) {
                $groups[$dept]['presents']++;
            }
        }

        $out = [];
        foreach ($groups as $nom => $g) {
            $out[] = [
                'nom' => $nom,
                'presents' => $g['presents'],
                'effectif' => $g['effectif'],
                'taux' => $g['effectif'] > 0 ? (int) round(100 * $g['presents'] / $g['effectif']) : 0,
            ];
        }
        usort($out, fn ($a, $b) => $b['taux'] <=> $a['taux']);

        return array_slice($out, 0, 6);
    }

    /**
     * Jours ouvrés attendus sur la période (hors week-end profil / fériés chômés).
     *
     * @return list<string> dates Y-m-d
     */
    private function joursOuvresDansPeriode(Carbon $from, Carbon $to): array
    {
        $calendrier = app(PointageHorairesCalendrierService::class);
        $profile = PointageHoraireProfile::query()
            ->where('scope_type', 'global')
            ->where('actif', true)
            ->orderBy('id')
            ->first()
            ?? PointageHoraireProfile::query()->orderBy('id')->first();

        $feries = $profile !== null ? $calendrier->feriesChargees() : null;
        $out = [];
        $d = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();
        while ($d->lte($end)) {
            if ($profile === null) {
                if (! $d->isWeekend()) {
                    $out[] = $d->toDateString();
                }
            } elseif ($calendrier->jourCompteDansBasePresence($d, $profile, $feries)) {
                $out[] = $d->toDateString();
            }
            $d->addDay();
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{title: string, headers: list<string>, rows: list<list<string>>}
     */
    private function rapportAbsences(Carbon $from, Carbon $to, array $filters): array
    {
        $q = PointageDeclaration::query()
            ->with(['user.profil'])
            ->where('type', 'absence')
            ->whereBetween('date_concernee', [$from->toDateString(), $to->toDateString()])
            ->orderBy('date_concernee');

        if (! empty($filters['user_id'])) {
            $q->where('user_id', (int) $filters['user_id']);
        } else {
            $ids = $this->filteredUserIds($filters);
            $q->whereIn('user_id', $ids ?: [0]);
        }

        $rows = [];
        foreach ($q->get() as $d) {
            $profil = $d->user?->profil;
            $rows[] = [
                FrenchDateFormat::date(Carbon::parse($d->date_concernee)),
                $profil ? trim(($profil->prenom ?? '').' '.($profil->nom ?? '')) : ($d->user?->name ?? '—'),
                $d->user?->email ?? '—',
                $profil?->departement ?: '—',
                $profil?->site ?: '—',
                (string) ($d->motif ?? '—'),
                (string) $d->statut,
            ];
        }

        return [
            'title' => 'Rapport des absences — '.FrenchDateFormat::date($from).' → '.FrenchDateFormat::date($to),
            'headers' => ['Date', 'Employé', 'Email', 'Service', 'Agence', 'Motif', 'Statut'],
            'rows' => $rows,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{title: string, headers: list<string>, rows: list<list<string>>}
     */
    private function rapportRetards(Carbon $from, Carbon $to, array $filters): array
    {
            $q = Pointage::query()
            ->with(['user.profil', 'agence'])
            ->where('type', 'arrivee')
            ->where('statut', 'retard')
            ->whereBetween('clocked_at', [$from, $to])
                ->orderBy('clocked_at');
        $this->applyCommonFilters($q, $filters);

        $rows = [];
        foreach ($q->get() as $p) {
            $profil = $p->user?->profil;
            $rows[] = [
                FrenchDateFormat::date($p->clocked_at),
                $profil ? trim(($profil->prenom ?? '').' '.($profil->nom ?? '')) : ($p->user?->name ?? '—'),
                $p->user?->email ?? '—',
                $profil?->departement ?: '—',
                $p->agence?->nom ?? ($profil?->site ?: '—'),
                $p->heureAffichee(),
            ];
        }

        return [
            'title' => 'Rapport des retards — '.FrenchDateFormat::date($from).' → '.FrenchDateFormat::date($to),
            'headers' => ['Date', 'Employé', 'Email', 'Service', 'Agence', 'H.A. effective'],
            'rows' => $rows,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{title: string, headers: list<string>, rows: list<list<string>>}
     */
    private function rapportHeuresSup(string $mois, array $filters): array
    {
        $monthStart = Carbon::createFromFormat('Y-m-d', $mois.'-01')->startOfMonth();
        $monthEnd = $monthStart->copy()->endOfMonth()->endOfDay();
        $rows = $this->rapportRhLignesEmployes($monthStart, $monthEnd, $this->filteredUserIds($filters))
            ->filter(fn (array $r) => ($r['heures_sup_minutes'] ?? 0) > 0)
            ->sortByDesc('heures_sup_minutes')
            ->values();

        return [
            'title' => 'Rapport des heures supplémentaires — '.$mois,
            'headers' => ['Employé', 'Total H.effective', 'Jours complets', 'Heures sup. (effective)'],
            'rows' => $rows->map(fn (array $r) => [
                $r['nom'],
                $r['heures_effective'],
                (string) $r['jours_complets'],
                $r['heures_sup_label'],
            ])->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{title: string, headers: list<string>, rows: list<list<string>>}
     */
    private function rapportAnomalies(Carbon $from, Carbon $to, array $filters): array
    {
        $q = Pointage::query()
            ->with(['user.profil', 'agence'])
            ->whereBetween('clocked_at', [$from, $to])
            ->orderBy('clocked_at');
        $this->applyCommonFilters($q, $filters);
        $pointages = $q->get();

        $rows = [];
        $byUserDay = $pointages->groupBy(fn (Pointage $p) => $p->user_id.'|'.$p->clocked_at->toDateString());
        foreach ($byUserDay as $items) {
            /** @var Collection<int, Pointage> $items */
            $arrivee = $items->firstWhere('type', 'arrivee');
            $depart = $items->filter(fn (Pointage $p) => $p->type === 'depart')->sortByDesc('clocked_at')->first();
            $ref = $arrivee ?? $depart;
            if ($ref === null) {
                continue;
            }
            $anomalies = [];
            if ($arrivee && ! $depart) {
                $anomalies[] = 'Entrée sans sortie';
            }
            if ($arrivee && ! $arrivee->qr_verified) {
                $anomalies[] = 'QR non validé (arrivée)';
            }
            if ($depart && ! $depart->qr_verified) {
                $anomalies[] = 'QR non validé (départ)';
            }
            if ($arrivee && ! $arrivee->biometric_ok) {
                $anomalies[] = 'Biométrie manquante (arrivée)';
            }
            if ($depart && ! $depart->biometric_ok) {
                $anomalies[] = 'Biométrie manquante (départ)';
            }
            if ($anomalies === []) {
                continue;
            }
            $profil = $ref->user?->profil;
            $rows[] = [
                FrenchDateFormat::date($ref->clocked_at),
                $profil ? trim(($profil->prenom ?? '').' '.($profil->nom ?? '')) : ($ref->user?->name ?? '—'),
                $ref->user?->email ?? '—',
                $ref->agence?->nom ?? ($profil?->site ?: '—'),
                implode(' ; ', $anomalies),
            ];
        }

        return [
            'title' => 'Rapport des anomalies — '.FrenchDateFormat::date($from).' → '.FrenchDateFormat::date($to),
            'headers' => ['Date', 'Employé', 'Email', 'Agence', 'Anomalies'],
            'rows' => $rows,
        ];
    }

    /**
     * Template : Agence | Effectif | Présences | Absences | Congés | Missions | Retards | Taux présence
     *
     * @param  array<string, mixed>  $filters
     * @return array{title: string, headers: list<string>, rows: list<list<string>>}
     */
    private function rapportParAgence(Carbon $from, Carbon $to, array $filters): array
    {
        return $this->rapportAgregationParGroupe(
            $from,
            $to,
            $filters,
            'agence',
            'Agence',
            'Rapport par agence — '.FrenchDateFormat::date($from).' → '.FrenchDateFormat::date($to),
        );
    }

    /**
     * Template : Direction | Effectif | Présences | Absences | Congés | Missions | Retards | Taux présence
     *
     * @param  array<string, mixed>  $filters
     * @return array{title: string, headers: list<string>, rows: list<list<string>>}
     */
    private function rapportParDepartement(Carbon $from, Carbon $to, array $filters): array
    {
        return $this->rapportAgregationParGroupe(
            $from,
            $to,
            $filters,
            'direction',
            'Direction',
            'Rapport par département — '.FrenchDateFormat::date($from).' → '.FrenchDateFormat::date($to),
        );
    }

    /**
     * @param  'agence'|'direction'  $mode
     * @param  array<string, mixed>  $filters
     * @return array{title: string, headers: list<string>, rows: list<list<string>>}
     */
    private function rapportAgregationParGroupe(
        Carbon $from,
        Carbon $to,
        array $filters,
        string $mode,
        string $firstHeader,
        string $title,
    ): array {
        $userIds = $this->filteredUserIds($filters);
        $users = User::query()
            ->with('profil')
            ->whereIn('id', $userIds ?: [0])
            ->orderBy('name')
                ->get();

        $joursOuvres = $this->joursOuvresDansPeriode($from, $to);
        $joursOuvresSet = array_fill_keys($joursOuvres, true);

        $pointagesByUser = Pointage::query()
            ->whereIn('user_id', $userIds ?: [0])
            ->whereBetween('clocked_at', [$from, $to])
            ->orderBy('clocked_at')
            ->get()
            ->groupBy('user_id');

        $declsByUser = $this->declarationsValidesParUser($userIds, $from, $to);

        /** @var array<string, array{effectif:int,presences:int,absences:int,conges:int,missions:int,retards:int}> $stats */
        $stats = [];

        foreach ($users as $user) {
            $profil = $user->profil;
            $groupe = $mode === 'agence'
                ? (string) ($profil?->site ?: 'Sans agence')
                : (string) ($profil?->departement ?: 'Sans direction');

            if (! isset($stats[$groupe])) {
                $stats[$groupe] = [
                    'effectif' => 0,
                    'presences' => 0,
                    'absences' => 0,
                    'conges' => 0,
                    'missions' => 0,
                    'retards' => 0,
                ];
            }
            $stats[$groupe]['effectif']++;

            /** @var Collection<int, Pointage> $events */
            $events = $pointagesByUser->get($user->id) ?? collect();
            $byDay = $events->groupBy(fn (Pointage $p) => $p->clocked_at->format('Y-m-d'));
            $coverage = $this->declarationCoverageByDay(
                $declsByUser->get($user->id) ?? collect(),
                $joursOuvresSet,
            );

            foreach ($joursOuvres as $day) {
                /** @var Collection<int, Pointage> $items */
                $items = $byDay->get($day) ?? collect();
                $status = $this->classifyPresenceJour($items, $coverage[$day] ?? null);

                if ($status['kind'] === 'mission') {
                    $stats[$groupe]['missions']++;
                } elseif (in_array($status['kind'], ['conge_annuel', 'conge_maladie', 'permission_exceptionnelle', 'formation'], true)) {
                    $stats[$groupe]['conges']++;
                } elseif ($status['kind'] === 'present') {
                    $stats[$groupe]['presences']++;
                    if ($status['is_retard']) {
                        $stats[$groupe]['retards']++;
                    }
                } else {
                    $stats[$groupe]['absences']++;
                }
            }
        }

        $joursCount = max(1, count($joursOuvres));
        $rows = [];
        foreach ($stats as $label => $s) {
            $attendu = $s['effectif'] * $joursCount;
            $taux = $attendu > 0
                ? (int) round(100 * $s['presences'] / $attendu)
                : 0;
            $rows[] = [
                $label,
                (string) $s['effectif'],
                (string) $s['presences'],
                (string) $s['absences'],
                (string) $s['conges'],
                (string) $s['missions'],
                (string) $s['retards'],
                $taux.' %',
            ];
        }
        usort($rows, fn (array $a, array $b) => strcasecmp($a[0], $b[0]));

        return [
            'title' => $title,
            'headers' => [
                $firstHeader, 'Effectif', 'Présences', 'Absences',
                'Congés', 'Missions', 'Retards', 'Taux présence',
            ],
            'rows' => $rows,
        ];
    }

    /**
     * @param  list<int>  $userIds
     * @return Collection<int, Collection<int, PointageDeclaration>>
     */
    private function declarationsValidesParUser(array $userIds, Carbon $from, Carbon $to): Collection
    {
        $fromDay = $from->toDateString();
        $toDay = $to->toDateString();
        $hasDateFin = Schema::hasColumn('pointage_declarations', 'date_fin');

        $q = PointageDeclaration::query()
            ->whereIn('user_id', $userIds ?: [0])
            ->where('statut', 'valide')
            ->whereIn('type', PointageDeclarationTypes::TYPES_JUSTIFICATIFS_PRESENCE)
            ->whereDate('date_concernee', '<=', $toDay);

        if ($hasDateFin) {
            $q->where(function ($w) use ($fromDay): void {
                $w->where(function ($x) use ($fromDay): void {
                    $x->whereNotNull('date_fin')->whereDate('date_fin', '>=', $fromDay);
                })->orWhere(function ($x) use ($fromDay): void {
                    $x->whereNull('date_fin')->whereDate('date_concernee', '>=', $fromDay);
                });
            });
        } else {
            $q->whereDate('date_concernee', '>=', $fromDay);
        }

        return $q->orderByDesc('id')->get()->groupBy('user_id');
    }

    /**
     * Couverture jour → mission | conge | absence (justifié).
     *
     * @param  Collection<int, PointageDeclaration>  $decls
     * @param  array<string, bool>  $joursOuvresSet
     * @return array<string, 'mission'|'conge'|'absence'>
     */
    private function declarationCoverageByDay(Collection $decls, array $joursOuvresSet): array
    {
        $out = [];
        foreach ($decls as $d) {
            $start = $d->date_concernee?->toDateString();
            if ($start === null) {
                continue;
            }
            $end = $d->date_fin?->toDateString() ?? $start;
            $type = PointageDeclarationTypes::normalize((string) $d->type);
            $kind = match ($type) {
                'mission' => 'mission',
                'conge_annuel' => 'conge_annuel',
                'conge_maladie' => 'conge_maladie',
                'permission_exceptionnelle' => 'permission_exceptionnelle',
                'formation' => 'formation',
                'absence' => 'absence',
                default => 'absence',
            };

            $cursor = Carbon::parse($start)->startOfDay();
            $endC = Carbon::parse($end)->startOfDay();
            while ($cursor->lte($endC)) {
                $key = $cursor->toDateString();
                if (isset($joursOuvresSet[$key]) && ! isset($out[$key])) {
                    $out[$key] = $kind;
                }
                $cursor->addDay();
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{title: string, headers: list<string>, rows: list<list<string>>}
     */
    private function rapportIndividuel(Carbon $from, Carbon $to, array $filters, PointageRecuperationService $recuperation): array
    {
        $userId = (int) ($filters['user_id'] ?? 0);
        if ($userId <= 0) {
            abort(422, 'Sélectionnez un collaborateur.');
        }
        $q = Pointage::query()
            ->with(['user.profil', 'agence'])
            ->where('user_id', $userId)
            ->whereBetween('clocked_at', [$from, $to])
            ->orderBy('clocked_at');
        $this->applyCommonFilters($q, $filters);

        $mapped = $recuperation->mapExportJourneeRows($q->get());
        $u = User::query()->with('profil')->find($userId);
        $name = $u?->profil
            ? trim(($u->profil->prenom ?? '').' '.($u->profil->nom ?? ''))
            : ($u?->name ?? 'Collaborateur');

        $totEff = 0;
        foreach ($mapped as $r) {
            $totEff += (int) ($r['total_effective_minutes'] ?? 0);
        }

        $detailRows = array_map(fn (array $r) => [
            $r['date'], $r['agence'], $r['ha_effective'], $r['hd_effective'],
            $r['total_effective'], $r['statut_label'],
        ], $mapped);

        $detailRows[] = [
            'TOTAL', '—', '—', '—',
            $this->heures->formatMinutes($totEff),
            '—',
        ];

        return [
            'title' => 'Rapport individuel — '.$name.' — '.FrenchDateFormat::date($from).' → '.FrenchDateFormat::date($to),
            'headers' => [
                'Date', 'Agence', 'H.A. effective', 'H.D. effective',
                'Total H.effective', 'Statut',
            ],
            'rows' => $detailRows,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyCommonFilters($query, array $filters): void
    {
        if (! empty($filters['agence_id'])) {
            $query->where('agence_id', (int) $filters['agence_id']);
        }
        if (! empty($filters['user_id'])) {
            $query->where('user_id', (int) $filters['user_id']);
        }
        if (! empty($filters['departement'])) {
            $dept = (string) $filters['departement'];
            $query->whereHas('user.profil', fn ($p) => $p->where('departement', $dept));
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<int>
     */
    private function filteredUserIds(array $filters): array
    {
        $ids = $this->rapportRhActifUserIds();
        if (! empty($filters['user_id'])) {
            $uid = (int) $filters['user_id'];

            return in_array($uid, $ids, true) ? [$uid] : [$uid];
        }
        if (empty($filters['departement']) && empty($filters['agence_id'])) {
            return $ids;
        }

        $q = User::query()->whereIn('id', $ids ?: [0])->where('is_active', true);
        if (! empty($filters['departement'])) {
            $dept = (string) $filters['departement'];
            $q->whereHas('profil', fn ($p) => $p->where('departement', $dept));
        }
        if (! empty($filters['agence_id'])) {
            $agence = Agence::query()->find((int) $filters['agence_id']);
            if ($agence) {
                $nom = $agence->nom;
                $q->whereHas('profil', fn ($p) => $p->where('site', $nom));
            }
        }

        return $q->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * @return list<array{id: int, label: string}>
     */
    private function collaborateursOptions(): array
    {
        return Profil::query()
            ->where('statut', 'actif')
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->orderBy('nom')
            ->orderBy('prenom')
            ->get(['id', 'nom', 'prenom', 'email', 'matricule'])
            ->map(function (Profil $p): ?array {
                $u = User::query()->where('email', $p->email)->where('is_active', true)->first();
                if ($u === null) {
                    return null;
                }

                return [
                    'id' => $u->id,
                    'label' => trim(($p->prenom ?? '').' '.($p->nom ?? '')).($p->matricule ? ' ('.$p->matricule.')' : ''),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  callable(): \Generator  $rows
     */
    private function csvStream(string $filename, callable $rows): StreamedResponse
    {
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ];

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
            foreach ($rows() as $line) {
                fputcsv($out, $line, ';');
            }
            fclose($out);
        }, $filename, $headers);
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     */
    private function pdfDownload(string $filename, string $title, array $headers, array $rows): HttpResponse
    {
        $th = '';
        foreach ($headers as $h) {
            $th .= '<th style="border:1px solid #ccc;padding:6px;background:#F5FAFF;text-align:left;font-size:11px;">'
                .e($h).'</th>';
        }
        $body = '';
        foreach ($rows as $row) {
            $body .= '<tr>';
            foreach ($row as $cell) {
                $body .= '<td style="border:1px solid #ddd;padding:5px;font-size:10px;">'.e((string) $cell).'</td>';
            }
            $body .= '</tr>';
        }
        if ($rows === []) {
            $body = '<tr><td colspan="'.max(1, count($headers)).'" style="padding:12px;text-align:center;">Aucune donnée</td></tr>';
        }

        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><style>
            body{font-family: DejaVu Sans, sans-serif;color:#0C447C;}
            h1{font-size:16px;margin:0 0 12px;}
            table{border-collapse:collapse;width:100%;}
        </style></head><body>
            <h1>'.e($title).'</h1>
            <table><thead><tr>'.$th.'</tr></thead><tbody>'.$body.'</tbody></table>
        </body></html>';

        $dompdf = new \Dompdf\Dompdf;
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**
     * @return list<int>
     */
    private function rapportRhActifUserIds(): array
    {
        $emails = Profil::query()
            ->where('statut', 'actif')
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->whereExists(function ($sub): void {
                $sub->selectRaw('1')
                    ->from('users')
                    ->whereColumn('users.email', 'profiles.email')
                    ->where('users.is_active', true);
            })
            ->whereNotExists(function ($sub): void {
                $sub->selectRaw('1')
                    ->from('pointage_affectations')
                    ->whereColumn('pointage_affectations.profil_id', 'profiles.id')
                    ->where('pointage_affectations.statut_activation', false);
            })
            ->pluck('email')
            ->filter()
            ->unique()
            ->values();

        return User::query()
            ->whereIn('email', $emails)
            ->where('is_active', true)
            ->whereNotExists(function ($sub): void {
                $sub->selectRaw('1')
                    ->from('pointage_affectations')
                    ->whereColumn('pointage_affectations.user_id', 'users.id')
                    ->where('pointage_affectations.statut_activation', false);
            })
            ->orderBy('name')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @param  list<int>  $userIds
     * @return Collection<int, array<string, mixed>>
     */
    private function rapportRhLignesEmployes(Carbon $monthStart, Carbon $monthEnd, array $userIds): Collection
    {
        $penaltyUnit = max(0, (int) config('pointage.employe_penalty_retard_fcfa', 2500));

        $rows = collect();
        foreach ($userIds as $uid) {
            $u = User::query()->find($uid);
            if (! $u) {
                continue;
            }

            $mins = $this->heures->minutesUserBetween((int) $uid, $monthStart, $monthEnd);
            $sup = $this->heures->heuresSupplementaires($mins['effective'], $mins['jours_complets']);

            $retards = (int) Pointage::query()
                ->where('user_id', $uid)
                ->whereBetween('clocked_at', [$monthStart, $monthEnd])
                ->where('type', 'arrivee')
                ->where('statut', 'retard')
                ->count();

            $absences = (int) PointageDeclaration::query()
                ->where('user_id', $uid)
                ->where('type', 'absence')
                ->where('statut', 'valide')
                ->whereBetween('date_concernee', [$monthStart->toDateString(), $monthEnd->toDateString()])
                ->count();

            $penalites = $retards * $penaltyUnit;

            $rows->push([
                'user_id' => (int) $uid,
                'nom' => (string) $u->name,
                'heures_effective' => $this->heures->formatMinutes($mins['effective']),
                'heures_reelle' => $this->heures->formatMinutes($mins['reelle']),
                'heures_travaillees' => $this->heures->formatMinutes($mins['effective']),
                'heures_travaillees_int' => (int) floor($mins['effective'] / 60),
                'jours_complets' => $mins['jours_complets'],
                'retards' => $retards,
                'absences' => $absences,
                'heures_sup' => (int) floor($sup['minutes'] / 60),
                'heures_sup_minutes' => $sup['minutes'],
                'heures_sup_label' => $sup['label'],
                'penalites_fcfa' => $penalites,
            ]);
        }

        return $rows;
    }
}