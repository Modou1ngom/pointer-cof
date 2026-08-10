<?php

namespace App\Http\Controllers;

use App\Models\Agence;
use App\Models\Departement;
use App\Models\Pointage;
use App\Models\PointageDeclaration;
use App\Models\Profil;
use App\Models\User;
use App\Services\Pointage\PointageHeuresCalculService;
use App\Services\Pointage\PointageRecuperationService;
use App\Support\FrenchDateFormat;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
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

        return Inertia::render('Pointage/ReportingRh', [
            'defaults' => [
                'date' => $today->toDateString(),
                'mois' => $today->format('Y-m'),
                'date_debut' => $today->copy()->startOfMonth()->toDateString(),
                'date_fin' => $today->toDateString(),
                'agence_id' => null,
                'departement' => null,
                'user_id' => null,
                'format' => 'csv',
            ],
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
     * @return list<array{id: string, title: string, description: string, period: string}>
     */
    private function rapportsCatalog(): array
    {
        return [
            [
                'id' => 'quotidien',
                'title' => 'Rapport quotidien de présence',
                'description' => 'Présences du jour : arrivées, départs, retards.',
                'period' => 'date',
            ],
            [
                'id' => 'mensuel',
                'title' => 'Rapport mensuel de pointage',
                'description' => 'Synthèse mensuelle heures, retards, absences, heures sup.',
                'period' => 'mois',
            ],
            [
                'id' => 'absences',
                'title' => 'Rapport des absences',
                'description' => 'Déclarations d’absence sur la période.',
                'period' => 'range',
            ],
            [
                'id' => 'retards',
                'title' => 'Rapport des retards',
                'description' => 'Pointages d’arrivée en retard.',
                'period' => 'range',
            ],
            [
                'id' => 'heures_sup',
                'title' => 'Rapport des heures supplémentaires',
                'description' => 'Estimation des heures supplémentaires par collaborateur.',
                'period' => 'mois',
            ],
            [
                'id' => 'anomalies',
                'title' => 'Rapport des anomalies',
                'description' => 'Entrées sans sortie, QR non validé, biométrie manquante.',
                'period' => 'range',
            ],
            [
                'id' => 'agence',
                'title' => 'Rapport par agence',
                'description' => 'Volumes et retards agrégés par site.',
                'period' => 'range',
            ],
            [
                'id' => 'departement',
                'title' => 'Rapport par département',
                'description' => 'Volumes et retards agrégés par service.',
                'period' => 'range',
            ],
            [
                'id' => 'individuel',
                'title' => 'Rapport individuel par collaborateur',
                'description' => 'Détail des pointages d’un collaborateur.',
                'period' => 'range_user',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{title: string, headers: list<string>, rows: list<list<string>>}
     */
    private function buildRapportPayload(string $type, array $filters, PointageRecuperationService $recuperation): array
    {
        [$from, $to, $date, $mois] = $this->resolvePeriod($filters);

        return match ($type) {
            'quotidien' => $this->rapportQuotidien($date, $filters, $recuperation),
            'mensuel' => $this->rapportMensuel($mois, $filters),
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
     * @return array{0: Carbon, 1: Carbon, 2: Carbon, 3: string}
     */
    private function resolvePeriod(array $filters): array
    {
        $date = Carbon::parse($filters['date'] ?? today()->toDateString())->startOfDay();
        $mois = is_string($filters['mois'] ?? null) && preg_match('/^\d{4}-\d{2}$/', $filters['mois'])
            ? $filters['mois']
            : $date->format('Y-m');
        $from = Carbon::parse($filters['date_debut'] ?? $date->copy()->startOfMonth()->toDateString())->startOfDay();
        $to = Carbon::parse($filters['date_fin'] ?? $date->toDateString())->endOfDay();
        if ($to->lt($from)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        return [$from, $to, $date, $mois];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{title: string, headers: list<string>, rows: list<list<string>>}
     */
    private function rapportQuotidien(Carbon $date, array $filters, PointageRecuperationService $recuperation): array
    {
        $q = Pointage::query()
            ->with(['user.profil', 'agence'])
            ->whereDate('clocked_at', $date->toDateString())
            ->orderBy('clocked_at');
        $this->applyCommonFilters($q, $filters);

        $rows = $recuperation->mapExportJourneeRows($q->get());

        return [
            'title' => 'Rapport quotidien de présence — '.FrenchDateFormat::date($date),
            'headers' => [
                'Date', 'Employé', 'Email', 'Matricule', 'Service', 'Agence',
                'H.A. effective', 'H.D. effective',
                'Total H.effective', 'Statut',
            ],
            'rows' => array_map(fn (array $r) => [
                $r['date'], $r['employe'], $r['email'], $r['matricule'], $r['service'], $r['agence'],
                $r['ha_effective'], $r['hd_effective'],
                $r['total_effective'], $r['statut_label'],
            ], $rows),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{title: string, headers: list<string>, rows: list<list<string>>}
     */
    private function rapportMensuel(string $mois, array $filters): array
    {
        $monthStart = Carbon::createFromFormat('Y-m-d', $mois.'-01')->startOfMonth();
        $monthEnd = $monthStart->copy()->endOfMonth()->endOfDay();
        $userIds = $this->filteredUserIds($filters);
        $rows = $this->rapportRhLignesEmployes($monthStart, $monthEnd, $userIds)->sortBy('nom')->values();

        return [
            'title' => 'Rapport mensuel de pointage — '.$mois,
            'headers' => [
                'Employé', 'Total H.effective', 'Retards',
                'Absences (validées)', 'Heures sup. (effective)', 'Pénalités (FCFA)',
            ],
            'rows' => $rows->map(fn (array $r) => [
                $r['nom'],
                $r['heures_effective'],
                (string) $r['retards'],
                (string) $r['absences'],
                $r['heures_sup_label'],
                $r['penalites_fcfa'] > 0 ? '-'.number_format($r['penalites_fcfa'], 0, ',', ' ') : '—',
            ])->all(),
        ];
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
     * @param  array<string, mixed>  $filters
     * @return array{title: string, headers: list<string>, rows: list<list<string>>}
     */
    private function rapportParAgence(Carbon $from, Carbon $to, array $filters): array
    {
        $q = Pointage::query()
            ->with(['agence', 'user.profil'])
            ->whereBetween('clocked_at', [$from, $to]);
        $this->applyCommonFilters($q, $filters);

        $grouped = $q->get()->groupBy(fn (Pointage $p) => $p->agence?->nom
            ?: ($p->user?->profil?->site ?: 'Sans agence'));

        $rows = [];
        foreach ($grouped as $agence => $items) {
            /** @var Collection<int, Pointage> $items */
            $tot = $this->heures->minutesFromCollection($items);
            $rows[] = [
                (string) $agence,
                (string) $items->pluck('user_id')->unique()->count(),
                (string) $items->where('type', 'arrivee')->count(),
                (string) $items->where('type', 'depart')->count(),
                (string) $items->where('type', 'arrivee')->where('statut', 'retard')->count(),
                $this->heures->formatMinutes($tot['effective']),
            ];
        }
        usort($rows, fn ($a, $b) => strcmp($a[0], $b[0]));

        return [
            'title' => 'Rapport par agence — '.FrenchDateFormat::date($from).' → '.FrenchDateFormat::date($to),
            'headers' => ['Agence', 'Collaborateurs', 'Arrivées', 'Départs', 'Retards', 'Total H.effective'],
            'rows' => $rows,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{title: string, headers: list<string>, rows: list<list<string>>}
     */
    private function rapportParDepartement(Carbon $from, Carbon $to, array $filters): array
    {
        $q = Pointage::query()
            ->with(['user.profil', 'agence'])
            ->whereBetween('clocked_at', [$from, $to]);
        $this->applyCommonFilters($q, $filters);

        $grouped = $q->get()->groupBy(fn (Pointage $p) => $p->user?->profil?->departement ?: 'Sans département');

        $rows = [];
        foreach ($grouped as $dept => $items) {
            /** @var Collection<int, Pointage> $items */
            $tot = $this->heures->minutesFromCollection($items);
            $rows[] = [
                (string) $dept,
                (string) $items->pluck('user_id')->unique()->count(),
                (string) $items->where('type', 'arrivee')->count(),
                (string) $items->where('type', 'depart')->count(),
                (string) $items->where('type', 'arrivee')->where('statut', 'retard')->count(),
                $this->heures->formatMinutes($tot['effective']),
            ];
        }
        usort($rows, fn ($a, $b) => strcmp($a[0], $b[0]));

        return [
            'title' => 'Rapport par département — '.FrenchDateFormat::date($from).' → '.FrenchDateFormat::date($to),
            'headers' => ['Département', 'Collaborateurs', 'Arrivées', 'Départs', 'Retards', 'Total H.effective'],
            'rows' => $rows,
        ];
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
            ->pluck('email')
            ->filter()
            ->unique()
            ->values();

        return User::query()
            ->whereIn('email', $emails)
            ->where('is_active', true)
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