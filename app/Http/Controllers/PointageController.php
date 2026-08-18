<?php

namespace App\Http\Controllers;

use App\Exports\PointageFicheHorairesExport;
use App\Models\Agence;
use App\Models\Departement;
use App\Models\Pointage;
use App\Models\PointageAffectation;
use App\Models\PointageAuditLog;
use App\Models\PointageDeclaration;
use App\Models\PointageHoraireProfile;
use App\Models\PointageRhSetting;
use App\Models\Profil;
use App\Models\User;
use App\Services\Pointage\PointageFicheHorairesService;
use App\Services\Pointage\PointageHorairesAjustementService;
use App\Services\Pointage\PointageHorairesCalendrierService;
use App\Services\Pointage\PointagePunchService;
use App\Services\Pointage\PointageRecuperationService;
use App\Services\PointageOtpService;
use App\Services\PointageQrService;
use App\Support\PointageDeclarationCouverture;
use App\Support\PointageDeclarationTypes;
use App\Support\PointageEnrolment;
use App\Support\PointageGeofencing;
use App\Support\PointageJourSemaine;
use App\Support\PointageQrScanUrl;
use App\Support\PointageRhSettingsMerger;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PointageController extends Controller
{
    public function dashboard(Request $request)
    {
        $user = Auth::user();
        abort_unless($user, 403);

        abort_unless($user->isRh() || $user->isSuperAdmin(), 403);

        return redirect()->route('pointage.rh.presence.recuperation-pointages');
    }

    public function pointer(PointageQrService $qrService)
    {
        $user = Auth::user();
        abort_unless($user, 403);
        $agence = $this->resolveAgenceForUser($user);

        $qr = null;
        if ($agence && $agence->actif && $agence->isEnrolledForPointageQr() && ($agence->pointage_qr_enabled ?? true)) {
            $qr = $qrService->mintToken($agence, $user);
        }

        $today = Carbon::today();
        $todayRows = Pointage::query()
            ->where('user_id', $user->id)
            ->whereDate('clocked_at', $today)
            ->orderBy('clocked_at')
            ->get()
            ->map(fn (Pointage $p) => [
                'type' => $p->type,
                'clocked_at' => $p->clocked_at->format('d/m/Y H\hi'),
                'heure_effective' => $p->heureAffichee(),
                'heure_reelle' => $p->heureReelleAffichee(),
                'statut' => $p->statut,
            ]);

        $user->profilCollaborateurAssocie();
        $profil = $user->profil;

        return Inertia::render('pointage/Pointer', [
            'agence' => $agence ? [
                'id' => $agence->id,
                'nom' => $agence->nom,
                'rayon_geofencing_metres' => $agence->rayon_geofencing_metres ?? 50,
                'pointage_qr_type' => $agence->pointage_qr_type ?? 'dynamic',
                'latitude' => $agence->latitude,
                'longitude' => $agence->longitude,
                'actif' => $agence->actif,
            ] : null,
            'qr' => $qr,
            'todayPointages' => $todayRows,
            'contact_hints' => $profil ? [
                'email_masked' => $this->maskEmailForDisplay($this->resolvePointageOtpEmail($profil, $user)),
                'phone_masked' => $this->maskPhoneForDisplay((string) $profil->telephone),
            ] : null,
            'plages_pointage' => app(PointageHorairesAjustementService::class)->plagesConfigForApi(),
        ]);
    }

    public function sendPointageOtp(Request $request, PointageQrService $qrService, PointageOtpService $otpService): \Illuminate\Http\RedirectResponse
    {
        $user = Auth::user();
        abort_unless($user, 403);

        $validated = $request->validate([
            'qr_token' => 'required|string',
        ]);
        $validated['qr_token'] = PointageQrScanUrl::normalizeScannedContent($validated['qr_token']);

        $agence = $this->resolveAgenceForUser($user);
        if (! $agence || ! $agence->actif || ! $agence->isEnrolledForPointageQr() || ! ($agence->pointage_qr_enabled ?? true)) {
            return back()->with('error', 'Aucun site de pointage valide pour votre compte.');
        }

        $result = $otpService->sendOtp($user, $validated['qr_token'], $agence, $qrService);
        if (! $result['ok']) {
            return back()->with('error', $result['message'] ?? 'Envoi impossible.');
        }

        return back()->with('success', $result['message'] ?? 'Code envoyé.');
    }

    public function verifyPointageOtp(Request $request, PointageQrService $qrService, PointageOtpService $otpService): \Illuminate\Http\RedirectResponse
    {
        $user = Auth::user();
        abort_unless($user, 403);

        $validated = $request->validate([
            'qr_token' => 'required|string',
            'otp_code' => 'required|string|max:32',
        ]);
        $validated['qr_token'] = PointageQrScanUrl::normalizeScannedContent($validated['qr_token']);

        $agence = $this->resolveAgenceForUser($user);
        if (! $agence || ! $agence->actif || ! $agence->isEnrolledForPointageQr() || ! ($agence->pointage_qr_enabled ?? true)) {
            return back()->with('error', 'Aucun site de pointage valide pour votre compte.');
        }

        $result = $otpService->verifyOtp($user, $validated['qr_token'], $validated['otp_code'], $agence, $qrService);
        if (! $result['ok']) {
            return back()->with('error', $result['message'] ?? 'Code invalide.');
        }

        return back()->with('success', $result['message'] ?? 'Code validé.')->with('otp_session_token', $result['otp_session_token'] ?? null);
    }

    /**
     * Même logique que {@see PointageOtpService::sendOtp} : e-mail profil RH puis compte.
     */
    private function resolvePointageOtpEmail(Profil $profil, User $user): string
    {
        return strtolower(trim((string) ($profil->email ?: $user->email)));
    }

    private function maskEmailForDisplay(string $email): string
    {
        $email = trim($email);
        if ($email === '' || ! str_contains($email, '@')) {
            return '—';
        }
        [$local, $domain] = explode('@', $email, 2);
        $len = strlen($local);
        if ($len <= 2) {
            $vis = $local;
        } else {
            $vis = substr($local, 0, 2).str_repeat('•', min(8, $len - 2));
        }

        return $vis.'@'.$domain;
    }

    private function maskPhoneForDisplay(string $telephone): string
    {
        $digits = preg_replace('/\D/', '', $telephone) ?? '';
        if (strlen($digits) < 4) {
            return '—';
        }

        return str_repeat('•', max(0, strlen($digits) - 4)).substr($digits, -4);
    }

    public function historique(Request $request)
    {
        $user = Auth::user();
        abort_unless($user, 403);

        $periode = $request->query('periode', 'mois');
        if (! is_string($periode) || ! in_array($periode, ['mois', 'semaine', 'tout'], true)) {
            $periode = 'mois';
        }

        $statut = $request->query('statut', 'tous');
        if (! is_string($statut) || ! in_array($statut, ['tous', 'normal', 'retard'], true)) {
            $statut = 'tous';
        }

        [$start, $end, $periodeMeta] = $this->historiqueResolvePeriod($user->id, $periode);
        $now = Carbon::now();

        $journees = $this->employeHistoriqueJourneesList($user->id, $start, $end, $statut);
        $summary = $this->employeHistoriqueSummaryForPeriod($user->id, $start, $end, $now, $periodeMeta);

        $perPage = 30;
        $page = max(1, (int) $request->query('page', 1));
        $total = count($journees);
        $slice = array_slice($journees, ($page - 1) * $perPage, $perPage);

        $pointages = new LengthAwarePaginator($slice, $total, $perPage, $page, [
            'path' => $request->url(),
            'pageName' => 'page',
        ]);
        $pointages->appends([
            'periode' => $periode,
            'statut' => $statut,
        ]);

        return Inertia::render('pointage/Historique', [
            'pointages' => $pointages,
            'summary' => $summary,
            'filters' => [
                'periode' => $periode,
                'statut' => $statut,
            ],
        ]);
    }

    public function historiqueExport(Request $request): StreamedResponse
    {
        $user = Auth::user();
        abort_unless($user, 403);

        $periode = $request->query('periode', 'mois');
        if (! is_string($periode) || ! in_array($periode, ['mois', 'semaine', 'tout'], true)) {
            $periode = 'mois';
        }

        $statut = $request->query('statut', 'tous');
        if (! is_string($statut) || ! in_array($statut, ['tous', 'normal', 'retard'], true)) {
            $statut = 'tous';
        }

        [$start, $end] = $this->historiqueResolvePeriod($user->id, $periode);
        $rows = $this->employeHistoriqueJourneesList($user->id, $start, $end, $statut);

        $filename = 'historique-pointage-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Date', 'Arrivée', 'Départ', 'Heures', 'GPS', 'Biométrie', 'Statut'], ';');
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r['date_display'],
                    $r['arrivee'],
                    $r['depart'],
                    $r['heures'],
                    $r['gps_ok'] ? 'Oui' : 'Non',
                    $r['biometric_ok'] ? 'Oui' : 'Non',
                    $r['statut_label'],
                ], ';');
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @return array{0: Carbon, 1: Carbon, 2: array<string, mixed>}
     */
    private function historiqueResolvePeriod(int $userId, string $periode): array
    {
        $now = Carbon::now();

        if ($periode === 'semaine') {
            $start = $now->copy()->startOfWeek(Carbon::MONDAY)->startOfDay();
            $weekEnd = $now->copy()->endOfWeek(Carbon::SUNDAY)->endOfDay();
            $end = $weekEnd->greaterThan($now) ? $now->copy()->endOfDay() : $weekEnd;

            return [$start, $end, ['kind' => 'semaine']];
        }

        if ($periode === 'tout') {
            $first = Pointage::query()->where('user_id', $userId)->min('clocked_at');
            $start = $first ? Carbon::parse($first)->startOfDay() : $now->copy()->subYears(3)->startOfDay();
            $end = $now->copy()->endOfDay();

            return [$start, $end, ['kind' => 'tout']];
        }

        $start = $now->copy()->startOfMonth()->startOfDay();
        $end = min($now->copy()->endOfDay(), $now->copy()->endOfMonth()->endOfDay());

        return [$start, $end, ['kind' => 'mois', 'mois_num' => (int) $now->format('n'), 'annee' => (int) $now->format('Y')]];
    }

    /**
     * @return array<string, mixed>
     */
    private function employeHistoriqueSummaryForPeriod(int $userId, Carbon $start, Carbon $end, Carbon $now, array $periodeMeta): array
    {
        $baseHDay = (float) config('pointage.base_heures_jour_reference', 8);
        $baseMinDay = (int) round($baseHDay * 60);

        $weekdays = $this->countWeekdaysInRange($start->copy()->startOfDay(), $end->copy()->endOfDay());
        $baseHeuresPeriode = (int) round($weekdays * $baseHDay);

        $workedMinutes = $this->minutesWorkedUserBetween($userId, $start, $end);
        $workedHeures = (int) floor($workedMinutes / 60);
        $remMin = $workedMinutes % 60;
        $totalHeuresLabel = $remMin > 0 ? sprintf('%dh%02d', $workedHeures, $remMin) : $workedHeures.'h';

        $manqueHeures = max(0, $baseHeuresPeriode - $workedHeures);
        $totalHeuresSousTitre = 'Base '.$baseHeuresPeriode.'h — '.($manqueHeures > 0 ? 'il manque '.$manqueHeures.'h' : 'objectif atteint');

        $joursOuvres = $weekdays;
        $joursPresents = (int) Pointage::query()
            ->where('user_id', $userId)
            ->whereBetween('clocked_at', [$start, $end])
            ->where('type', 'arrivee')
            ->get()
            ->pluck('clocked_at')
            ->map(fn (Carbon $c) => $c->format('Y-m-d'))
            ->unique()
            ->count();

        $tauxPct = $joursOuvres > 0 ? min(100, (int) round($joursPresents / $joursOuvres * 100)) : 0;

        $events = Pointage::query()
            ->where('user_id', $userId)
            ->whereBetween('clocked_at', [$start, $end])
            ->orderBy('clocked_at')
            ->get();
        $byDay = $events->groupBy(fn (Pointage $p) => $p->clocked_at->format('Y-m-d'));
        $suppMinutes = 0;
        foreach ($byDay as $items) {
            /** @var Collection<int, Pointage> $items */
            $sorted = $items->sortBy('clocked_at')->values();
            $arrivee = $sorted->firstWhere('type', 'arrivee');
            $depart = $sorted->filter(fn (Pointage $p) => $p->type === 'depart')->sortByDesc(fn (Pointage $p) => $p->clocked_at)->first();
            if ($arrivee && $depart) {
                $dm = (int) $arrivee->clocked_at->diffInMinutes($depart->clocked_at);
                $suppMinutes += max(0, $dm - $baseMinDay);
            }
        }
        $suppH = intdiv($suppMinutes, 60);
        $suppM = $suppMinutes % 60;
        $heuresSuppLabel = $suppM > 0 ? sprintf('%dh%02d', $suppH, $suppM) : $suppH.'h';

        $retards = Pointage::query()
            ->where('user_id', $userId)
            ->whereBetween('clocked_at', [$start, $end])
            ->where('type', 'arrivee')
            ->where('statut', 'retard')
            ->get();

        $retardsCount = $retards->count();
        $retardMinutesTotal = 0;
        foreach ($retards as $r) {
            $retardMinutesTotal += $this->minutesLateForArrivee($r);
        }

        $penaltyUnit = max(0, (int) config('pointage.employe_penalty_retard_fcfa', 2500));
        $penaltyFcfa = $retardsCount * $penaltyUnit;

        $moisNom = mb_strtoupper($now->locale('fr')->translatedFormat('F'));
        if (($periodeMeta['kind'] ?? '') === 'mois') {
            $cardTitre = $moisNom;
        } elseif (($periodeMeta['kind'] ?? '') === 'semaine') {
            $cardTitre = 'SEMAINE';
        } else {
            $cardTitre = 'TOTAL';
        }

        return [
            'card_periode_titre' => $cardTitre,
            'total_heures_label' => $totalHeuresLabel,
            'total_heures_sous_titre' => $totalHeuresSousTitre,
            'jours_presents_label' => $joursPresents.'/'.$joursOuvres,
            'jours_presents_sous_titre' => $tauxPct.'% de présence',
            'heures_supp_label' => $heuresSuppLabel,
            'heures_supp_sous_titre' => 'Majorées à 125%',
            'retards_label' => (string) $retardsCount,
            'retards_sous_titre' => $retardsCount > 0
                ? $retardMinutesTotal.' min — '.number_format($penaltyFcfa, 0, ',', ' ').' FCFA'
                : 'Aucun retard',
        ];
    }

    private function minutesLateForArrivee(Pointage $arrivee): int
    {
        try {
            $limite = Carbon::parse($arrivee->clocked_at->format('Y-m-d').' '.config('pointage.heure_arrivee', '08:00'))
                ->addMinutes((int) config('pointage.tolerance_minutes', 10));
        } catch (\Throwable) {
            return 0;
        }

        if ($arrivee->clocked_at->lte($limite)) {
            return 0;
        }

        return (int) $limite->diffInMinutes($arrivee->clocked_at);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function employeHistoriqueJourneesList(int $userId, Carbon $start, Carbon $end, string $filtreStatut): array
    {
        $events = Pointage::query()
            ->where('user_id', $userId)
            ->whereBetween('clocked_at', [$start, $end])
            ->orderBy('clocked_at')
            ->get();

        $byDay = $events->groupBy(fn (Pointage $p) => $p->clocked_at->format('Y-m-d'));
        $days = $byDay->keys()->sortDesc()->values();

        $rows = [];
        foreach ($days as $dayStr) {
            $items = $byDay[$dayStr];
            /** @var Collection<int, Pointage> $items */
            $sorted = $items->sortBy('clocked_at')->values();
            $arrivee = $sorted->firstWhere('type', 'arrivee');
            $depart = $sorted->filter(fn (Pointage $p) => $p->type === 'depart')->sortByDesc(fn (Pointage $p) => $p->clocked_at)->first();

            $statut = 'normal';
            if ($sorted->contains(fn (Pointage $p) => $p->statut === 'retard')) {
                $statut = 'retard';
            }

            if ($filtreStatut === 'normal' && $statut === 'retard') {
                continue;
            }
            if ($filtreStatut === 'retard' && $statut !== 'retard') {
                continue;
            }

            $heuresLabel = '—';
            if ($arrivee && $depart) {
                $mins = (int) $arrivee->clocked_at->diffInMinutes($depart->clocked_at);
                $heuresLabel = intdiv($mins, 60).'h'.str_pad((string) ($mins % 60), 2, '0', STR_PAD_LEFT);
            } elseif ($arrivee && ! $depart) {
                $heuresLabel = 'en cours';
            }

            $gpsOk = $arrivee && $arrivee->latitude !== null && $arrivee->longitude !== null;
            $bioOk = (bool) ($arrivee?->biometric_ok);

            $rows[] = [
                'date' => $dayStr,
                'date_display' => $dayStr,
                'arrivee' => $arrivee ? $arrivee->clocked_at->format('H:i') : '—',
                'depart' => $depart ? $depart->clocked_at->format('H:i') : '—',
                'heures' => $heuresLabel,
                'gps_ok' => $gpsOk,
                'biometric_ok' => $bioOk,
                'statut' => $statut,
                'statut_label' => $statut === 'retard' ? 'Retard' : 'Normal',
            ];
        }

        return $rows;
    }

    public function profil(Request $request)
    {
        $user = Auth::user();
        abort_unless($user, 403);
        $user->profilCollaborateurAssocie();

        $now = Carbon::now();
        $heureArr = (string) config('pointage.heure_arrivee', '08:00');
        $heureDep = (string) config('pointage.heure_depart', '17:00');
        try {
            $hA = Carbon::createFromFormat('H:i', strlen($heureArr) === 5 ? $heureArr : '08:00')->format('H\hi');
            $hD = Carbon::createFromFormat('H:i', strlen($heureDep) === 5 ? $heureDep : '17:00')->format('H\hi');
        } catch (\Throwable) {
            $hA = '08h00';
            $hD = '17h00';
        }
        $horaireStandard = $hA.' — '.$hD;

        $statsMois = array_merge(
            [
                'periode_label' => mb_convert_case($now->locale('fr')->translatedFormat('F Y'), MB_CASE_TITLE, 'UTF-8'),
                'semaines' => $this->employeProfilSemainesMois($user->id, $now),
            ],
            $this->employeProfilPresenceMois($user->id, $now)
        );

        return Inertia::render('pointage/Profil', [
            'profil' => $user->profil,
            'horaire_standard' => $horaireStandard,
            'stats_mois' => $statsMois,
            'telephone_affiche' => $this->maskTelephoneProfil($user->profil?->telephone),
            'biometric_registered' => true,
        ]);
    }

    /**
     * @return list<array{label: string, heures: int, tone: string}>
     */
    private function employeProfilSemainesMois(int $userId, Carbon $moisReference): array
    {
        $start = $moisReference->copy()->startOfMonth()->startOfDay();
        $end = min($moisReference->copy()->endOfMonth()->endOfDay(), Carbon::now()->endOfDay());
        $lastDay = (int) $moisReference->copy()->endOfMonth()->format('d');

        $segments = [[1, 7], [8, 14], [15, 21], [22, $lastDay]];
        $bars = [];
        $i = 1;
        foreach ($segments as [$d1, $d2]) {
            if ($d1 > $lastDay) {
                $bars[] = ['label' => 'S'.$i, 'heures' => 0, 'tone' => 'neutral'];
                $i++;

                continue;
            }
            $d2c = min($d2, $lastDay);
            $from = $start->copy()->day($d1)->startOfDay();
            $to = $start->copy()->day($d2c)->endOfDay();
            if ($to->greaterThan($end)) {
                $to = $end->copy();
            }
            $mins = $this->minutesWorkedUserBetween($userId, $from, $to);
            $h = (int) floor($mins / 60);
            $tone = $h >= 40 ? 'good' : ($h >= 32 ? 'warn' : ($h > 0 ? 'bad' : 'neutral'));
            $bars[] = ['label' => 'S'.$i, 'heures' => $h, 'tone' => $tone];
            $i++;
        }

        return $bars;
    }

    /**
     * @return array{presence_pct: int, presence_message: string}
     */
    private function employeProfilPresenceMois(int $userId, Carbon $now): array
    {
        $monthStart = $now->copy()->startOfMonth()->startOfDay();
        $monthEnd = min($now->copy()->endOfDay(), $now->copy()->endOfMonth()->endOfDay());

        $joursOuvres = $this->countWeekdaysInRange($monthStart, $monthEnd);
        $joursPresents = (int) Pointage::query()
            ->where('user_id', $userId)
            ->whereBetween('clocked_at', [$monthStart, $monthEnd])
            ->where('type', 'arrivee')
            ->get()
            ->pluck('clocked_at')
            ->map(fn (Carbon $c) => $c->format('Y-m-d'))
            ->unique()
            ->count();

        $pct = $joursOuvres > 0 ? min(100, (int) round($joursPresents / $joursOuvres * 100)) : 0;

        $message = match (true) {
            $pct >= 95 => 'Excellent !',
            $pct >= 85 => 'Très bien.',
            $pct >= 70 => 'Correct — restez régulier.',
            default => 'À améliorer — contactez votre manager.',
        };

        return [
            'presence_pct' => $pct,
            'presence_message' => $message,
        ];
    }

    private function maskTelephoneProfil(?string $telephone): ?string
    {
        if ($telephone === null || trim($telephone) === '') {
            return null;
        }
        $t = trim($telephone);
        if (mb_strlen($t) < 10) {
            return $t;
        }

        return mb_substr($t, 0, 8).' XXX XX XX';
    }

    public function equipe(Request $request)
    {
        $user = Auth::user();
        abort_unless($user && ($user->isResponsableDepartement() || $user->isAdmin() || $user->isRh() || $user->isSuperAdmin()), 403);

        $user->profilCollaborateurAssocie();
        $me = $user->profil;

        $profilsQuery = Profil::query()
            ->where('statut', 'actif')
            ->whereNotExists(function ($q): void {
                $q->selectRaw('1')
                    ->from('pointage_affectations')
                    ->whereColumn('pointage_affectations.profil_id', 'profiles.id')
                    ->where('pointage_affectations.statut_activation', false);
            });

        if ($me && $user->isResponsableDepartement() && ! $user->isAdmin() && ! $user->isRh()) {
            $dept = \App\Models\Departement::query()
                ->where('responsable_departement_id', $me->id)
                ->where('actif', true)
                ->pluck('nom');
            $profilsQuery->whereIn('departement', $dept);
        }

        $profils = $profilsQuery->orderBy('nom')->limit(200)->get();

        $today = Carbon::today()->toDateString();
        $emails = $profils->pluck('email')->filter()->values();
        $activeUsers = User::query()
            ->whereIn('email', $emails)
            ->where('is_active', true)
            ->get(['id', 'email']);
        $userIds = $activeUsers->pluck('id');
        $activeEmails = $activeUsers
            ->mapWithKeys(fn (User $u) => [mb_strtolower((string) $u->email) => true]);

        $pointagesToday = Pointage::query()
            ->whereDate('clocked_at', $today)
            ->whereIn('user_id', $userIds)
            ->get()
            ->keyBy('user_id');

        // Comptes désactivés : hors équipe / hors absence attendue.
        $profils = $profils
            ->filter(fn (Profil $p) => isset($activeEmails[mb_strtolower((string) $p->email)]))
            ->values();

        return Inertia::render('pointage/Equipe', [
            'profils' => $profils,
            'pointagesToday' => $pointagesToday,
        ]);
    }

    public function retards(Request $request)
    {
        $user = Auth::user();
        abort_unless($user && ($user->isResponsableDepartement() || $user->isAdmin() || $user->isRh()), 403);

        $rows = Pointage::query()
            ->with('user:id,name,email')
            ->where('statut', 'retard')
            ->orderByDesc('clocked_at')
            ->limit(100)
            ->get();

        return Inertia::render('pointage/Retards', ['pointages' => $rows]);
    }

    public function rapportsService(Request $request)
    {
        $user = Auth::user();
        abort_unless($user && ($user->isResponsableDepartement() || $user->isAdmin() || $user->isRh()), 403);

        return Inertia::render('pointage/RapportsService', [
            'resume' => $this->resumePointagesPeriode(now()->startOfMonth(), now()),
        ]);
    }

    public function rhEmployes(Request $request)
    {
        $user = Auth::user();
        abort_unless($user && ($user->isRh() || $user->isAdmin()), 403);

        $agenceFiltre = $this->agenceFilterFromRequest($request);
        $serviceFiltre = $request->query('service', 'tous');
        if (! is_string($serviceFiltre)) {
            $serviceFiltre = 'tous';
        }
        $filialeRaw = $request->query('filiale', 'tous');
        $filialeFiltre = is_numeric($filialeRaw) ? (int) $filialeRaw : 'tous';
        $searchFiltre = $request->query('search', '');
        if (! is_string($searchFiltre)) {
            $searchFiltre = '';
        }
        $searchFiltre = trim($searchFiltre);

        $query = PointageAffectation::query()->with(['profil', 'user', 'agences']);

        $perimetreIds = $user->filialeIdsDuPerimetre();
        if ($filialeFiltre !== 'tous' && ! in_array($filialeFiltre, $perimetreIds, true)) {
            $filialeFiltre = 'tous';
        }
        if ($perimetreIds === []) {
            $query->whereRaw('1 = 0');
        } else {
            $query->whereHas('profil', fn ($q) => $q->whereIn('filiale_id', $perimetreIds));
        }

        if ($filialeFiltre !== 'tous') {
            $query->whereHas('profil', fn ($q) => $q->where('filiale_id', $filialeFiltre));
        }
        if ($searchFiltre !== '') {
            $like = '%'.$searchFiltre.'%';
            $query->whereHas('profil', function ($q) use ($like) {
                $q->where(function ($sq) use ($like) {
                    $sq->where('nom', 'like', $like)
                        ->orWhere('prenom', 'like', $like)
                        ->orWhere('matricule', 'like', $like)
                        ->orWhere('email', 'like', $like);
                });
            });
        }
        if ($agenceFiltre !== '' && $agenceFiltre !== 'tous') {
            $query->where(function ($q) use ($agenceFiltre) {
                $q->whereHas('profil', fn ($sq) => $sq->where('site', $agenceFiltre))
                    ->orWhereHas('agences', fn ($aq) => $aq->where('agences.nom', $agenceFiltre));
            });
        }
        if ($serviceFiltre !== '' && $serviceFiltre !== 'tous') {
            $query->whereHas('profil', fn ($q) => $q->where('departement', $serviceFiltre));
        }

        $statutFiltre = $request->query('statut', 'tous');
        if ($statutFiltre === 'actif') {
            $query->where('statut_activation', true);
        } elseif ($statutFiltre === 'inactif') {
            $query->where('statut_activation', false);
        }

        $agencesListeQuery = Agence::query()->where('actif', true)->orderBy('nom');
        $servicesBase = Profil::query()
            ->whereIn('id', PointageAffectation::query()->pluck('profil_id'))
            ->whereNotNull('departement')
            ->where('departement', '!=', '');
        if ($perimetreIds !== []) {
            $agencesListeQuery->whereIn('filiale_id', $perimetreIds);
            $servicesBase->whereIn('filiale_id', $perimetreIds);
        } else {
            $agencesListeQuery->whereRaw('1 = 0');
            $servicesBase->whereRaw('1 = 0');
        }

        $agencesListe = $agencesListeQuery->pluck('nom')
            ->merge(
                Profil::query()
                    ->whereIn('id', PointageAffectation::query()->pluck('profil_id'))
                    ->when($perimetreIds !== [], fn ($q) => $q->whereIn('filiale_id', $perimetreIds))
                    ->whereNotNull('site')
                    ->where('site', '!=', '')
                    ->distinct()
                    ->orderBy('site')
                    ->pluck('site')
            )
            ->unique()
            ->values()
            ->all();

        $servicesListe = $servicesBase
            ->distinct()
            ->orderBy('departement')
            ->pluck('departement')
            ->values()
            ->all();

        $heureArr = (string) config('pointage.heure_arrivee', '08:00');
        $heureDep = (string) config('pointage.heure_depart', '17:00');
        try {
            $hA = Carbon::createFromFormat('H:i', strlen($heureArr) === 5 ? $heureArr : '08:00')->format('G');
            $hD = Carbon::createFromFormat('H:i', strlen($heureDep) === 5 ? $heureDep : '17:00')->format('G');
        } catch (\Throwable) {
            $hA = '8';
            $hD = '17';
        }
        $horaireAffiche = $hA.'h-'.$hD.'h';

        $totalEnroles = (clone $query)->count();
        $totalActifs = (clone $query)->where('statut_activation', true)->count();

        $buildAffectationsPage = function () use ($query) {
            $affectations = (clone $query)
                ->join('profiles', 'profiles.id', '=', 'pointage_affectations.profil_id')
                ->orderBy('profiles.nom')
                ->orderBy('profiles.prenom')
                ->select('pointage_affectations.*')
                ->paginate(25)
                ->withQueryString();

            $userIdsPage = $affectations->getCollection()
                ->pluck('user_id')
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();
            $notesJour = PointageDeclarationCouverture::mapPourUsersJour(
                $userIdsPage,
                Carbon::today(),
                PointageDeclarationTypes::TYPES_NOTES_RH,
            );

            $affectations->getCollection()->transform(function (PointageAffectation $a) use ($notesJour) {
                $payload = PointageRhAffectationController::affectationListItemPayload($a);
                $note = $a->user_id ? $notesJour->get((int) $a->user_id) : null;
                if (is_array($note) && ($note['couvert'] ?? false)) {
                    $payload['note_type'] = $note['type'] ?? null;
                    $payload['note_label'] = $note['label'] ?? null;
                    $payload['note_declaration_id'] = $note['declaration_id'] ?? null;
                } else {
                    $payload['note_type'] = null;
                    $payload['note_label'] = null;
                    $payload['note_declaration_id'] = null;
                }

                return $payload;
            });

            return $affectations;
        };

        return Inertia::render('Pointage/RhEmployes', [
            // Différé : absent du HTML initial (data-page), chargé ensuite via XHR authentifié.
            'affectations' => Inertia::defer($buildAffectationsPage),
            'total_enroles' => $totalEnroles,
            'total_actifs' => $totalActifs,
            'filters' => [
                'agence' => $agenceFiltre,
                'service' => $serviceFiltre,
                'statut' => is_string($statutFiltre) ? $statutFiltre : 'tous',
                'filiale' => $filialeFiltre === 'tous' ? 'tous' : (string) $filialeFiltre,
                'search' => $searchFiltre,
            ],
            'agences' => $agencesListe,
            'services' => $servicesListe,
            'horaire_display' => $horaireAffiche,
            'agences_picker' => PointageRhAffectationController::agencesPickerForActor($user)->values()->all(),
            'type_pointage_options' => PointageRhAffectationController::typePointageOptions(),
            'mode_validation_options' => PointageRhAffectationController::modeValidationOptions(),
            'niveau_acces_options' => PointageRhAffectationController::niveauAccesOptions(),
            'profil_form' => PointageRhAffectationController::profilFormOptions($user),
            'n1_profils' => Inertia::defer(fn () => PointageRhAffectationController::n1ProfilOptions($user)),
        ]);
    }

    public function rhTousPointages(Request $request)
    {
        $user = Auth::user();
        abort_unless($user && ($user->isRh() || $user->isAdmin()), 403);

        $dateRaw = $request->query('date', Carbon::today()->toDateString());
        if (! is_string($dateRaw)) {
            $dateRaw = Carbon::today()->toDateString();
        }
        try {
            $jour = Carbon::parse($dateRaw)->startOfDay();
        } catch (\Throwable) {
            $jour = Carbon::today()->startOfDay();
        }
        $dateStr = $jour->toDateString();

        $agenceFiltre = $this->agenceFilterFromRequest($request);
        $statutFiltre = $request->query('statut', 'tous');
        if (! is_string($statutFiltre)) {
            $statutFiltre = 'tous';
        }
        if (! in_array($statutFiltre, ['tous', 'normal', 'retard', 'absent', 'justifie'], true)) {
            $statutFiltre = 'tous';
        }

        $rows = $this->buildRhTousPointagesJournees($jour, $agenceFiltre, $statutFiltre);

        $agencesListe = Agence::query()->where('actif', true)->orderBy('nom')->pluck('nom')
            ->merge(
                Profil::query()->where('statut', 'actif')->whereNotNull('site')->where('site', '!=', '')->distinct()->orderBy('site')->pluck('site')
            )
            ->unique()
            ->values()
            ->all();

        $dateLabel = mb_convert_case($jour->copy()->locale('fr')->translatedFormat('d F Y'), MB_CASE_TITLE, 'UTF-8');

        $perPage = 25;
        $page = max(1, (int) $request->query('page', 1));
        $total = $rows->count();
        $slice = $rows->forPage($page, $perPage)->values()->all();

        $paginator = new LengthAwarePaginator(
            $slice,
            $total,
            $perPage,
            $page,
            ['path' => $request->url(), 'pageName' => 'page'],
        );
        $paginator->withQueryString();

        return Inertia::render('Pointage/RhTousPointages', [
            'pointages' => $paginator,
            'filtreDate' => $dateStr,
            'date_label' => $dateLabel,
            'total_enregistrements' => $total,
            'filters' => [
                'agence' => $agenceFiltre,
                'statut' => $statutFiltre,
            ],
            'agences' => $agencesListe,
        ]);
    }

    public function rhTousPointagesExport(Request $request): StreamedResponse
    {
        $user = Auth::user();
        abort_unless($user && ($user->isRh() || $user->isAdmin()), 403);

        $dateRaw = $request->query('date', Carbon::today()->toDateString());
        if (! is_string($dateRaw)) {
            $dateRaw = Carbon::today()->toDateString();
        }
        try {
            $jour = Carbon::parse($dateRaw)->startOfDay();
        } catch (\Throwable) {
            $jour = Carbon::today()->startOfDay();
        }
        $dateStr = $jour->toDateString();

        $agenceFiltre = $this->agenceFilterFromRequest($request);
        $statutFiltre = $request->query('statut', 'tous');
        if (! is_string($statutFiltre)) {
            $statutFiltre = 'tous';
        }
        if (! in_array($statutFiltre, ['tous', 'normal', 'retard', 'absent', 'justifie'], true)) {
            $statutFiltre = 'tous';
        }

        $rows = $this->buildRhTousPointagesJournees($jour, $agenceFiltre, $statutFiltre);
        $filename = 'pointage-rh-journalier-'.$dateStr.'.csv';

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($out, ['Employé', 'Email', 'Matricule', 'Service', 'Agence', 'Arrivée', 'Départ', 'Heures', 'GPS', 'Biom.', 'Statut'], ';');
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r['employe'],
                    $r['email'],
                    $r['matricule'],
                    $r['service'],
                    $r['agence'],
                    $r['arrivee'],
                    $r['depart'],
                    $r['heures'],
                    $r['gps_ok'] ? 'OK' : 'KO',
                    $r['biometric_ok'] ? 'OK' : 'KO',
                    $r['statut_label'],
                ], ';');
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    public function rhRecuperationPointages(Request $request, PointageRecuperationService $recuperation, PointageRapportController $rapport)
    {
        $user = Auth::user();
        abort_unless($user && ($user->isRh() || $user->isAdmin()), 403);

        $filters = $recuperation->parseFilters($request->query());
        $query = $recuperation->baseQuery($filters);

        $perPage = 50;
        $page = max(1, (int) $request->query('page', 1));

        $journeeRows = collect($recuperation->mapExportJourneeRows($query->get()));
        $total = $journeeRows->count();
        $slice = $journeeRows->forPage($page, $perPage)->values();

        $paginator = new \Illuminate\Pagination\LengthAwarePaginator(
            $slice,
            $total,
            $perPage,
            $page,
            [
                'path' => $request->url(),
                'query' => $request->query(),
            ]
        );

        $dateRef = \Carbon\Carbon::parse($filters['date_fin'])->startOfDay();
        $departement = $request->filled('departement') ? (string) $request->input('departement') : null;
        $statut = $request->filled('statut_presence') ? (string) $request->input('statut_presence') : null;
        if ($statut === 'tous' || $statut === '') {
            $statut = null;
        }
        $mois = $request->filled('mois') ? (string) $request->input('mois') : $dateRef->format('Y-m');
        if (preg_match('/^\d{4}-\d{2}$/', $mois) === 1 && ! $request->filled('date_fin')) {
            try {
                $moisStart = \Carbon\Carbon::createFromFormat('Y-m', $mois)->startOfMonth();
                $moisEnd = $moisStart->copy()->endOfMonth();
                $today = \Carbon\Carbon::today();
                $dateRef = $moisEnd->gt($today) ? $today->copy() : $moisEnd->copy()->startOfDay();
                $filters['date_debut'] = $moisStart->toDateString();
                $filters['date_fin'] = $dateRef->toDateString();
            } catch (\Throwable) {
                // keep existing dates
            }
        }

        $reportingFilters = [
            'date' => $filters['date_fin'],
            'mois' => $mois,
            'annee' => $dateRef->format('Y'),
            'date_debut' => $filters['date_debut'],
            'date_fin' => $filters['date_fin'],
            'agence_id' => $filters['agence_id'],
            'departement' => $departement,
            'user_id' => null,
            'statut' => $statut,
            'format' => 'csv',
        ];

        $reportingQuery = http_build_query(array_filter([
            'date' => $reportingFilters['date'],
            'agence_id' => $reportingFilters['agence_id'],
            'departement' => $reportingFilters['departement'],
            'statut' => $reportingFilters['statut'],
            'mois' => $reportingFilters['mois'],
        ]));

        $mode = $request->query('mode', 'overview');
        if (! in_array($mode, ['overview', 'lignes'], true)) {
            $mode = 'overview';
        }

        return Inertia::render('Pointage/Presence/RecuperationPointages', [
            'pointages' => $paginator,
            'kpis' => $recuperation->kpis($filters),
            'reporting_dashboard' => $rapport->buildDashboard($dateRef, $reportingFilters),
            'reporting_href' => '/pointage/rapport/reporting'.($reportingQuery !== '' ? '?'.$reportingQuery : ''),
            'mode' => $mode,
            'periode_label' => $recuperation->periodeLabel($filters),
            'filters' => array_merge($filters, [
                'departement' => $departement ?? '',
                'mois' => $mois,
                'statut_presence' => $statut ?? '',
            ]),
            'agences' => $recuperation->agencesOptions(),
            'departements' => Departement::query()->where('actif', true)->orderBy('nom')->pluck('nom')->values(),
            'statut_types' => [
                ['value' => 'present', 'label' => 'Présent'],
                ['value' => 'retard', 'label' => 'Retard'],
                ['value' => 'absence', 'label' => 'Absence'],
                ['value' => 'conge_annuel', 'label' => 'Congé annuel'],
                ['value' => 'conge_maladie', 'label' => 'Congé maladie'],
                ['value' => 'permission_exceptionnelle', 'label' => 'Permission exceptionnelle'],
                ['value' => 'allaitement', 'label' => 'Allaitement'],
                ['value' => 'mission', 'label' => 'Mission'],
                ['value' => 'formation', 'label' => 'Formation'],
            ],
        ]);
    }

    public function rhRecuperationPointagesExport(Request $request, PointageRecuperationService $recuperation): StreamedResponse
    {
        $user = Auth::user();
        abort_unless($user && ($user->isRh() || $user->isAdmin()), 403);

        $filters = $recuperation->parseFilters($request->query());
        $pointages = $recuperation->baseQuery($filters)->get();
        $rows = $recuperation->mapExportJourneeRows($pointages);

        $filename = 'recuperation-pointages-'.$filters['date_debut'].'_'.$filters['date_fin'].'.csv';

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($out, [
                'Date', 'Employé', 'Email', 'Matricule', 'Service', 'Agence',
                'Type', 'H.A. effective', 'H.D. effective',
                'Total H.effective',
                'Horodatage', 'QR', 'Statut', 'Férié auto',
            ], ';');
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r['date'],
                    $r['employe'],
                    $r['email'],
                    $r['matricule'],
                    $r['service'],
                    $r['agence'],
                    $r['type_label'],
                    $r['ha_effective'],
                    $r['hd_effective'],
                    $r['total_effective'],
                    $r['horodatage'],
                    $r['qr_verified'] ? 'OK' : 'KO',
                    $r['statut_label'],
                    $r['auto_ferie'] ? 'Oui' : 'Non',
                ], ';');
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**
     * Synthèse journalière (une ligne par employé actif avec compte) pour la page RH « Tous les pointages ».
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function buildRhTousPointagesJournees(Carbon $jour, string $agenceFiltre, string $statutFiltre): Collection
    {
        $profilsQuery = Profil::query()
            ->where('statut', 'actif')
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->whereExists(function ($q): void {
                $q->selectRaw('1')
                    ->from('users')
                    ->whereColumn('users.email', 'profiles.email')
                    ->where('users.is_active', true);
            })
            ->whereNotExists(function ($q): void {
                $q->selectRaw('1')
                    ->from('pointage_affectations')
                    ->whereColumn('pointage_affectations.profil_id', 'profiles.id')
                    ->where('pointage_affectations.statut_activation', false);
            });

        if ($agenceFiltre !== '' && $agenceFiltre !== 'tous') {
            $profilsQuery->where('site', $agenceFiltre);
        }

        $profils = $profilsQuery->orderBy('nom')->orderBy('prenom')->get();

        if ($profils->isEmpty()) {
            return collect();
        }

        $emails = $profils->pluck('email')->filter()->unique()->values();
        $usersByEmail = User::query()
            ->whereIn('email', $emails)
            ->where('is_active', true)
            ->get()
            ->keyBy(fn (User $u) => mb_strtolower((string) $u->email));

        $dayStart = $jour->copy()->startOfDay();
        $dayEnd = $jour->copy()->endOfDay();

        $userIds = $usersByEmail->pluck('id')->values()->all();

        $eventsByUser = Pointage::query()
            ->whereIn('user_id', $userIds)
            ->whereBetween('clocked_at', [$dayStart, $dayEnd])
            ->orderBy('clocked_at')
            ->get()
            ->groupBy('user_id');

        $couvertureByUser = PointageDeclarationCouverture::mapPourUsersJour($userIds, $jour);

        $rows = collect();

        foreach ($profils as $profil) {
            $emailKey = mb_strtolower((string) $profil->email);
            $u = $usersByEmail->get($emailKey);
            if (! $u) {
                continue;
            }

            /** @var Collection<int, Pointage> $ev */
            $ev = $eventsByUser->get($u->id, collect());
            $sorted = $ev->sortBy(fn (Pointage $p) => $p->clocked_at->timestamp)->values();

            /** @var Pointage|null $arrivee */
            $arrivee = $sorted->firstWhere('type', 'arrivee');
            /** @var Pointage|null $depart */
            $depart = $sorted->filter(fn (Pointage $p) => $p->type === 'depart')->sortByDesc(fn (Pointage $p) => $p->clocked_at->timestamp)->first();

            $couverture = $couvertureByUser->get($u->id, ['couvert' => false]);

            if (! $arrivee) {
                $heuresLabel = '0h00';
                $gpsOk = false;
                $bioOk = false;
                $arriveeStr = '—';
                $departStr = '—';
                $jourStatut = $this->jourStatutSansPointage($jour);
                if ($jourStatut === 'absent' && ($couverture['couvert'] ?? false)) {
                    $jourStatut = 'justifie';
                }
            } else {
                $arriveeStr = $arrivee->clocked_at->format('H:i');
                $departStr = $depart ? $depart->clocked_at->format('H:i') : '—';
                if ($depart) {
                    $mins = (int) $arrivee->clocked_at->diffInMinutes($depart->clocked_at);
                    $heuresLabel = intdiv($mins, 60).'h'.str_pad((string) ($mins % 60), 2, '0', STR_PAD_LEFT);
                } else {
                    $heuresLabel = 'en cours';
                }
                $gpsOk = $arrivee->latitude !== null && $arrivee->longitude !== null;
                $bioOk = (bool) $arrivee->biometric_ok;
                if ($arrivee->statut === 'retard' || $this->minutesLateForArrivee($arrivee) > 0) {
                    $jourStatut = 'retard';
                } else {
                    $jourStatut = 'normal';
                }
            }

            $statutLabel = match ($jourStatut) {
                'retard' => 'Retard',
                'absent' => 'Absent',
                'justifie' => (string) ($couverture['label'] ?? 'Justifié'),
                'ferie' => 'Jour férié',
                'non_ouvre' => 'Non ouvré',
                default => 'Normal',
            };

            if ($statutFiltre !== 'tous' && $jourStatut !== $statutFiltre) {
                continue;
            }

            $rows->push([
                'profil_id' => $profil->id,
                'user_id' => $u->id,
                'employe' => trim($profil->prenom.' '.$profil->nom),
                'email' => $profil->email,
                'matricule' => $profil->matricule ?: '—',
                'service' => $profil->departement ?: '—',
                'agence' => $profil->site ?: '—',
                'arrivee' => $arriveeStr,
                'depart' => $departStr,
                'heures' => $heuresLabel,
                'gps_ok' => $gpsOk,
                'biometric_ok' => $bioOk,
                'statut' => $jourStatut,
                'statut_label' => $statutLabel,
            ]);
        }

        return $rows;
    }


    public function rhParametrage()
    {
        $user = Auth::user();
        abort_unless($user && ($user->isRh() || $user->isAdmin()), 403);

        return Inertia::render('Pointage/RhParametrage', $this->rhParametragePagePayload());
    }

    public function rhParametrageUpdate(Request $request)
    {
        $user = Auth::user();
        abort_unless($user && ($user->isRh() || $user->isAdmin()), 403);

        $motifKeys = array_keys(config('pointage.declaration_motifs_autorises', []));
        $validated = $request->validate([
            'heure_arrivee' => 'required|date_format:H:i',
            'heure_depart' => 'required|date_format:H:i',
            'heure_arrivee_ajustee' => 'required|date_format:H:i',
            'heure_depart_ajustee' => 'required|date_format:H:i',
            'plage_arrivee_debut' => 'required|date_format:H:i',
            'plage_arrivee_fin' => 'required|date_format:H:i|different:plage_arrivee_debut',
            'plage_depart_debut' => 'required|date_format:H:i',
            'plage_depart_fin' => 'required|date_format:H:i|different:plage_depart_debut',
            'tolerance_minutes' => 'required|integer|min:0|max:180',
            'seuil_heures_supplementaires_h_jour' => 'required|numeric|min:0|max:24',
            'seuil_heures_supplementaires_h_semaine' => 'nullable|numeric|min:0|max:168',
            'delai_validation_manager_heures' => 'required|integer|min:1|max:720',
            'relances_automatiques_apres_heures' => 'required|integer|min:1|max:720',
            'employe_penalty_retard_fcfa' => 'required|integer|min:0',
            'penalite_absence_injustifiee_fcfa_jour' => 'required|integer|min:0',
            'majoration_heures_sup_pct' => 'required|integer|min:0|max:200',
            'mode_export_sage_paie' => 'required|in:mensuel_auto_1er,mensuel_manuel,hebdomadaire',
            'gestion_ui' => 'nullable|array',
        ], [
            'plage_arrivee_fin.different' => 'La fin de la plage arrivée doit être différente du début (plage passant minuit autorisée).',
            'plage_depart_fin.different' => 'La fin de la plage départ doit être différente du début (ex. 15:00 → 01:00 autorisé).',
        ]);

        $motifs = [];
        foreach ($motifKeys as $mk) {
            $motifs[$mk] = (bool) ($request->boolean('declaration_motifs_autorises.'.$mk));
        }

        $gestionUi = is_array($request->input('gestion_ui'))
            ? $this->normalizeGestionHorairesUi($request->input('gestion_ui'))
            : $this->defaultGestionHorairesUi();

        $payload = [
            'heure_arrivee' => $validated['heure_arrivee'],
            'heure_depart' => $validated['heure_depart'],
            'heure_arrivee_ajustee' => $validated['heure_arrivee_ajustee'],
            'heure_depart_ajustee' => $validated['heure_depart_ajustee'],
            'plage_arrivee_debut' => $validated['plage_arrivee_debut'],
            'plage_arrivee_fin' => $validated['plage_arrivee_fin'],
            'plage_depart_debut' => $validated['plage_depart_debut'],
            'plage_depart_fin' => $validated['plage_depart_fin'],
            'tolerance_minutes' => (int) $validated['tolerance_minutes'],
            'seuil_heures_supplementaires_h_jour' => (float) $validated['seuil_heures_supplementaires_h_jour'],
            'seuil_heures_supplementaires_h_semaine' => (float) ($validated['seuil_heures_supplementaires_h_semaine'] ?? 40),
            'delai_validation_manager_heures' => (int) $validated['delai_validation_manager_heures'],
            'relances_automatiques_apres_heures' => (int) $validated['relances_automatiques_apres_heures'],
            'employe_penalty_retard_fcfa' => (int) $validated['employe_penalty_retard_fcfa'],
            'penalite_absence_injustifiee_fcfa_jour' => (int) $validated['penalite_absence_injustifiee_fcfa_jour'],
            'majoration_heures_sup_pct' => (int) $validated['majoration_heures_sup_pct'],
            'mode_export_sage_paie' => $validated['mode_export_sage_paie'],
            'declaration_motifs_autorises' => $motifs,
            'gestion_ui' => $gestionUi,
            'updated_by_name' => $user->name,
            'updated_at_label' => now()->format('d/m/Y H:i'),
        ];

        $row = PointageRhSetting::query()->first();
        if ($row === null) {
            PointageRhSetting::query()->create(['payload' => $payload]);
        } else {
            /** @var array<string, mixed> $existing */
            $existing = is_array($row->payload) ? $row->payload : [];
            $row->update(['payload' => array_merge($existing, $payload)]);
        }

        return redirect()->route('pointage.rh.parametrage')->with('success', 'Paramètres enregistrés.');
    }

    public function rhParametragePlagesUpdate(Request $request)
    {
        $user = Auth::user();
        abort_unless($user && ($user->isRh() || $user->isAdmin()), 403);

        $validated = $request->validate([
            'plage_arrivee_debut' => 'required|date_format:H:i',
            'plage_arrivee_fin' => 'required|date_format:H:i|different:plage_arrivee_debut',
            'plage_depart_debut' => 'required|date_format:H:i',
            'plage_depart_fin' => 'required|date_format:H:i|different:plage_depart_debut',
        ], [
            'plage_arrivee_fin.different' => 'La fin de la plage arrivée doit être différente du début (plage passant minuit autorisée).',
            'plage_depart_fin.different' => 'La fin de la plage départ doit être différente du début (ex. 15:00 → 01:00 autorisé).',
        ]);

        $plages = [
            'plage_arrivee_debut' => $validated['plage_arrivee_debut'],
            'plage_arrivee_fin' => $validated['plage_arrivee_fin'],
            'plage_depart_debut' => $validated['plage_depart_debut'],
            'plage_depart_fin' => $validated['plage_depart_fin'],
        ];

        $row = PointageRhSetting::query()->first();
        if ($row === null) {
            PointageRhSetting::query()->create(['payload' => $plages]);
        } else {
            /** @var array<string, mixed> $existing */
            $existing = is_array($row->payload) ? $row->payload : [];
            $row->update(['payload' => array_merge($existing, $plages)]);
        }

        return redirect()->route('pointage.rh.parametrage')->with('success', 'Plages de pointage enregistrées.');
    }

    public function rhParametrageFicheExport(Request $request, PointageFicheHorairesService $ficheService)
    {
        $user = Auth::user();
        abort_unless($user && ($user->isRh() || $user->isAdmin()), 403);

        PointageRhSettingsMerger::mergeStoredPayloadIntoConfig();

        $moisRaw = $request->query('mois', Carbon::now()->format('Y-m'));
        $mois = is_string($moisRaw) ? $moisRaw : Carbon::now()->format('Y-m');
        if (! preg_match('/^\d{4}-\d{2}$/', $mois)) {
            $mois = Carbon::now()->format('Y-m');
        }

        $userId = null;
        $userIdRaw = $request->query('user_id');
        if ($userIdRaw !== null && $userIdRaw !== '' && $userIdRaw !== 'tous') {
            $userId = (int) $userIdRaw;
            if ($userId <= 0) {
                $userId = null;
            }
        }

        $rows = $ficheService->buildRowsForMonth($mois, $userId);
        $suffix = $userId !== null ? '-employe-'.$userId : '';
        $filename = 'fiche-horaires-'.$mois.$suffix.'.xlsx';

        return Excel::download(new PointageFicheHorairesExport($rows), $filename);
    }

    /**
     * @return array<string, mixed>
     */
    private function rhParametragePagePayload(): array
    {
        $motifs = config('pointage.declaration_motifs_autorises', []);
        $motifLabels = [
            'maladie_certificat' => 'Maladie (avec certificat)',
            'mission_externe' => 'Mission externe',
            'conge_annuel' => 'Congé annuel',
            'formation_professionnelle' => 'Formation professionnelle',
            'cas_exceptionnel' => 'Cas exceptionnel',
            'deuil' => 'Deuil',
        ];

        $row = PointageRhSetting::query()->first();
        /** @var array<string, mixed> $stored */
        $stored = is_array($row?->payload) ? $row->payload : [];
        $gestionUi = $this->normalizeGestionHorairesUi(
            is_array($stored['gestion_ui'] ?? null) ? $stored['gestion_ui'] : []
        );

        return [
            'config' => [
                'heure_arrivee' => (string) config('pointage.heure_arrivee'),
                'heure_depart' => (string) config('pointage.heure_depart'),
                'heure_arrivee_ajustee' => (string) config('pointage.heure_arrivee_ajustee', config('pointage.heure_arrivee')),
                'heure_depart_ajustee' => (string) config('pointage.heure_depart_ajustee', config('pointage.heure_depart')),
                'plage_arrivee_debut' => (string) config('pointage.plage_arrivee_debut', '07:00'),
                'plage_arrivee_fin' => (string) config('pointage.plage_arrivee_fin', '12:00'),
                'plage_depart_debut' => (string) config('pointage.plage_depart_debut', '15:00'),
                'plage_depart_fin' => (string) config('pointage.plage_depart_fin', '20:00'),
                'tolerance_minutes' => (int) config('pointage.tolerance_minutes'),
                'base_heures_jour_reference' => (float) config('pointage.base_heures_jour_reference', 8),
                'seuil_heures_supplementaires_h_jour' => (float) config('pointage.seuil_heures_supplementaires_h_jour', 9),
                'seuil_heures_supplementaires_h_semaine' => (float) ($stored['seuil_heures_supplementaires_h_semaine'] ?? 40),
                'delai_validation_manager_heures' => (int) config('pointage.delai_validation_manager_heures', 48),
                'relances_automatiques_apres_heures' => (int) config('pointage.relances_automatiques_apres_heures', 24),
                'employe_penalty_retard_fcfa' => (int) config('pointage.employe_penalty_retard_fcfa'),
                'penalite_absence_injustifiee_fcfa_jour' => (int) config('pointage.penalite_absence_injustifiee_fcfa_jour', 8000),
                'majoration_heures_sup_pct' => (int) config('pointage.majoration_heures_sup_pct', 25),
                'mode_export_sage_paie' => (string) config('pointage.mode_export_sage_paie', 'mensuel_auto_1er'),
                'declaration_motifs_autorises' => $motifs,
                'gestion_ui' => $gestionUi,
            ],
            'mode_export_options' => [
                ['value' => 'mensuel_auto_1er', 'label' => 'Mensuel automatique (le 1er)'],
                ['value' => 'mensuel_manuel', 'label' => 'Mensuel sur demande'],
                ['value' => 'hebdomadaire', 'label' => 'Hebdomadaire'],
            ],
            'motif_labels' => $motifLabels,
            'export_employes' => app(PointageFicheHorairesService::class)->employesActifsPourExport(),
            'export_mois_defaut' => Carbon::now()->format('Y-m'),
            'meta' => [
                'updated_at_label' => (string) ($stored['updated_at_label'] ?? ''),
                'updated_by_name' => (string) ($stored['updated_by_name'] ?? ''),
                'geofencing_radius' => (int) config('pointage.default_geofencing_radius_metres', 100),
                'qr_ttl_seconds' => (int) config('pointage.qr_dynamic_ttl_seconds', 30),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultGestionHorairesUi(): array
    {
        return [
            'pauses_active' => true,
            'pause_obligatoire' => true,
            'pause_duree' => '01:00',
            'pause_minimum' => '00:30',
            'pause_maximum' => '02:00',
            'pause_deduire_auto' => true,
            'pause_controler_insuffisantes' => true,
            'methode_qr' => true,
            'methode_biometrie' => true,
            'methode_mobile' => true,
            'methode_badge' => true,
            'comptage_entree' => true,
            'comptage_sortie' => true,
            'comptage_pauses' => true,
            'autoriser_plusieurs_entrees_sorties' => true,
            'empecher_double_pointage' => true,
            'arrondi_minutes' => 10,
            'secu_gps' => true,
            'secu_wifi' => true,
            'secu_appareils' => true,
            'secu_qr' => true,
            'secu_anti_capture' => true,
            'secu_enregistrer_appareil' => true,
            'rayon_gps_metres' => (int) config('pointage.default_geofencing_radius_metres', 100),
            'qr_validite_secondes' => (int) config('pointage.qr_dynamic_ttl_seconds', 30),
            'tentatives_max' => 3,
            'retard_a_partir' => '08:15',
            'depart_anticipe_apres' => '16:45',
            'absence_apres' => '04:00',
            'calcul_retard_auto' => true,
            'calcul_depart_anticipe_auto' => true,
            'generer_anomalies' => true,
            'anomalie_entree_sans_sortie' => true,
            'anomalie_sortie_sans_entree' => true,
            'anomalie_double_pointage' => true,
            'anomalie_hors_plage' => true,
            'anomalie_retard_important' => true,
            'anomalie_depart_anticipe' => true,
            'anomalie_journee_incomplete' => true,
            'hs_calcul_auto' => true,
            'hs_autorisation_obligatoire' => true,
            'hs_validation_par' => 'manager',
            'majoration_nuit_pct' => 50,
            'majoration_dimanche_pct' => 50,
            'majoration_ferie_pct' => 100,
            'profil_horaire' => 'bureau_standard',
            'jours_travailles' => [1, 2, 3, 4, 5],
            'jours_feries_mode' => 'bloquer',
            'correction_employe' => true,
            'validation_manager' => true,
            'validation_rh' => true,
            'conserver_ancien_pointage' => true,
            'motif_obligatoire' => true,
            'justificatif_obligatoire' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function normalizeGestionHorairesUi(array $input): array
    {
        $defaults = $this->defaultGestionHorairesUi();
        $out = $defaults;

        foreach ($defaults as $key => $default) {
            if (! array_key_exists($key, $input)) {
                continue;
            }
            $value = $input[$key];
            if (is_bool($default)) {
                $out[$key] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            } elseif (is_int($default)) {
                $out[$key] = (int) $value;
            } elseif (is_array($default)) {
                $out[$key] = is_array($value)
                    ? array_values(array_map('intval', $value))
                    : $default;
            } else {
                $out[$key] = is_scalar($value) ? (string) $value : $default;
            }
        }

        return $out;
    }

    public function adminQrcodes(PointageQrService $qrService)
    {
        $user = Auth::user();
        abort_unless($user && $user->isAdmin(), 403);

        $agences = Agence::query()->orderBy('nom')->get();
        $tokens = [];
        foreach ($agences as $a) {
            $tokens[$a->id] = $qrService->mintToken($a);
        }

        return Inertia::render('pointage/AdminQrcodes', [
            'agences' => $agences,
            'qrPreview' => $tokens,
        ]);
    }

    public function adminLogs(Request $request)
    {
        $user = Auth::user();
        abort_unless($user && $user->isAdmin(), 403);

        $logs = PointageAuditLog::query()
            ->with(['actor:id,name', 'agence:id,nom'])
            ->orderByDesc('created_at')
            ->paginate(40);

        return Inertia::render('pointage/AdminLogs', ['logs' => $logs]);
    }

    public function adminSecurite()
    {
        $user = Auth::user();
        abort_unless($user && $user->isAdmin(), 403);

        return Inertia::render('pointage/AdminSecurite');
    }

    public function store(Request $request, PointageQrService $qrService, PointageOtpService $otpService, PointagePunchService $punchService)
    {
        $user = Auth::user();
        abort_unless($user, 403);

        if (PointageJourSemaine::isJourQrInactif()) {
            return back()->with('error', PointageJourSemaine::messageQrInactif());
        }

        $validated = $request->validate([
            'type' => 'nullable|in:arrivee,depart',
            'qr_token' => 'required|string',
            'unlock_code' => 'nullable|string|max:64',
            'otp_session_token' => 'nullable|string|size:64',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'biometric_ok' => 'sometimes|boolean',
            'device_id' => 'nullable|string|max:128',
            'serial_number' => 'nullable|string|max:128',
        ]);
        $validated['qr_token'] = PointageQrScanUrl::normalizeScannedContent($validated['qr_token']);
        if (! empty($validated['device_id'])) {
            $request->merge([
                'device_id' => \App\Support\MobileDeviceId::normalize((string) $validated['device_id']),
            ]);
        }
        if (! empty($validated['serial_number'])) {
            $request->merge([
                'serial_number' => \App\Support\MobileDeviceId::normalize((string) $validated['serial_number']),
            ]);
        }

        $agence = $this->resolveAgenceForUser($user);
        if (! $agence || ! $agence->actif || ! $agence->isEnrolledForPointageQr() || ! ($agence->pointage_qr_enabled ?? true)) {
            return back()->with('error', 'Aucun site de pointage valide pour votre compte.');
        }

        $enrolment = PointageEnrolment::ensureAuthorized($user, $agence);
        if (! $enrolment['ok']) {
            PointageAuditLog::record($user, 'POINTAGE_NON_ENROLE', $enrolment['message'] ?? 'Non autorisé', $agence, $request->ip(), 'alerte');

            return back()->with('error', $enrolment['message']);
        }

        if (! $qrService->verifyToken($validated['qr_token'], $agence, $user)) {
            PointageAuditLog::record($user, 'QR_CODE_EXPIRE', 'Tentative avec QR invalide', $agence, $request->ip(), 'fraude');

            return back()->with('error', 'QR Code invalide ou expiré (non lié à votre session).');
        }

        $user->profilCollaborateurAssocie();
        $profil = $user->profil;
        if (! $profil) {
            return back()->with('error', 'Profil collaborateur introuvable.');
        }

        $otpSession = isset($validated['otp_session_token']) ? trim((string) $validated['otp_session_token']) : '';
        $unlockCode = isset($validated['unlock_code']) ? trim((string) $validated['unlock_code']) : '';

        $unlockOk = false;
        if ($otpSession !== '') {
            $unlockOk = $otpService->validateOtpSession($otpSession, $user, $validated['qr_token'], $agence);
        } elseif ($unlockCode !== '') {
            $devUnlock = config('pointage.dev_unlock_code');
            if (
                is_string($devUnlock) && $devUnlock !== ''
                && config('app.debug')
                && hash_equals($devUnlock, $unlockCode)
            ) {
                $unlockOk = true;
            } elseif ($profil->validatePointageUnlockCode($unlockCode)) {
                $unlockOk = true;
            }
        }

        if (! $unlockOk) {
            PointageAuditLog::record($user, 'CODE_POINTAGE_INVALIDE', 'OTP ou code déverrouillage refusé', $agence, $request->ip(), 'fraude');

            return back()->with('error', 'Validez le code reçu par e-mail et SMS, ou saisissez votre PIN / les 4 derniers chiffres du téléphone professionnel.');
        }

        $geo = PointageGeofencing::validate(
            $agence,
            (float) $validated['latitude'],
            (float) $validated['longitude'],
        );
        if (! $geo['ok']) {
            PointageAuditLog::record(
                $user,
                'GPS_HORS_ZONE',
                'Géorepérage — '.round((float) ($geo['distance_metres'] ?? 0)).' m',
                $agence,
                $request->ip(),
                'alerte',
                ['distance' => $geo['distance_metres'] ?? null]
            );

            return back()->with('error', $geo['message'] ?? 'Géorepérage : position hors zone autorisée.');
        }

        $punch = $punchService->record(
            $user,
            $agence,
            (float) $validated['latitude'],
            (float) $validated['longitude'],
            $validated['type'] ?? null,
            true,
            (bool) ($validated['biometric_ok'] ?? false),
            $punchService->requestMeta($request),
        );

        if (! $punch['ok']) {
            return back()->with('error', $punch['message'] ?? 'Pointage refusé.');
        }

        $type = (string) $punch['type'];
        PointageAuditLog::record(
            $user,
            $type === 'arrivee' ? 'POINTAGE_ARRIVEE' : 'POINTAGE_DEPART',
            ($punch['heure_effective'] ?? '').' (réel '.($punch['heure_reelle'] ?? '').')',
            $agence,
            $request->ip(),
            'ok'
        );

        if ($otpSession !== '') {
            $otpService->revokeOtpSession($otpSession);
        }

        return redirect()->route('pointage.pointer')->with('success', $punch['message'] ?? 'Pointage enregistré.');
    }

    private function resolveAgenceForUser(\App\Models\User $user): ?Agence
    {
        $user->loadMissing(['agences', 'profil']);
        $def = $user->agences()->wherePivot('is_default', true)->first();
        if ($def) {
            return $def;
        }
        $first = $user->agences()->first();
        if ($first) {
            return $first;
        }
        if ($user->profil?->site) {
            return Agence::query()->where('nom', $user->profil->site)->first();
        }

        return null;
    }

    private function resumePointagesPeriode(Carbon $du, Carbon $au): array
    {
        $q = Pointage::query()->whereBetween('clocked_at', [$du, $au]);

        return [
            'total' => (clone $q)->count(),
            'retards' => (clone $q)->where('statut', 'retard')->count(),
            'arrivees' => (clone $q)->where('type', 'arrivee')->count(),
            'departs' => (clone $q)->where('type', 'depart')->count(),
        ];
    }

    private function renderDashboardEmploye(Request $request)
    {
        $user = Auth::user();
        abort_unless($user, 403);
        $user->profilCollaborateurAssocie();

        $now = Carbon::now();
        $monthStart = $now->copy()->startOfMonth();
        $monthEnd = $now->copy()->endOfMonth();

        $currentMinutes = $this->minutesWorkedUserBetween($user->id, $monthStart, $now);

        $prevMonthStart = $monthStart->copy()->subMonth();
        $elapsedDays = max(1, (int) $monthStart->diffInDays($now) + 1);
        $prevEnd = $prevMonthStart->copy()->addDays($elapsedDays - 1)->endOfDay();
        if ($prevEnd->greaterThan($prevMonthStart->copy()->endOfMonth())) {
            $prevEnd = $prevMonthStart->copy()->endOfMonth();
        }
        $prevMinutes = $this->minutesWorkedUserBetween($user->id, $prevMonthStart, $prevEnd);
        $deltaHeuresMois = (int) round(($currentMinutes - $prevMinutes) / 60);

        $heuresMois = (int) floor($currentMinutes / 60);

        $joursOuvresMois = $this->countWeekdaysInRange($monthStart->copy()->startOfDay(), $monthEnd->copy()->endOfDay());

        $joursPresents = (int) Pointage::query()
            ->where('user_id', $user->id)
            ->whereBetween('clocked_at', [$monthStart, $now])
            ->where('type', 'arrivee')
            ->get()
            ->pluck('clocked_at')
            ->map(fn (Carbon $c) => $c->format('Y-m-d'))
            ->unique()
            ->count();

        $retardsMois = (int) Pointage::query()
            ->where('user_id', $user->id)
            ->whereBetween('clocked_at', [$monthStart, $now])
            ->where('type', 'arrivee')
            ->where('statut', 'retard')
            ->count();

        $penaltyUnit = max(0, (int) config('pointage.employe_penalty_retard_fcfa', 2500));
        $penaltyTotal = $retardsMois * $penaltyUnit;

        $soldeConges = (int) config('pointage.employe_solde_conges_jours', 12);
        $congesAPrendre = (int) config('pointage.employe_conges_a_prendre_jours', 5);

        $journees = $this->employeDernieresJourneesTable($user->id, 12);

        $siteLabel = $user->profil?->site ?? '—';
        $activites = $this->buildEmployeActiviteRecente($user, $siteLabel);

        $pendingDeclarations = PointageDeclaration::query()
            ->where('user_id', $user->id)
            ->whereIn('statut', ['en_attente_manager', 'en_attente_rh'])
            ->count();

        $heureDepartConfig = (string) config('pointage.heure_depart', '17:00');
        try {
            $heureDepartCarbon = Carbon::createFromFormat('H:i', $heureDepartConfig);
            $heureDepartAffichee = $heureDepartCarbon->format('H\hi');
        } catch (\Throwable) {
            $heureDepartAffichee = '17h00';
        }

        return Inertia::render('pointage/DashboardEmploye', [
            'kpis' => [
                'heures_mois' => $heuresMois,
                'delta_heures_vs_mois_precedent' => $deltaHeuresMois,
                'jours_presents' => $joursPresents,
                'jours_ouvres_mois' => $joursOuvresMois,
                'retards' => $retardsMois,
                'penalty_retard_fcfa' => $penaltyTotal,
                'solde_conges_jours' => $soldeConges,
                'conges_a_prendre_jours' => $congesAPrendre,
            ],
            'journees' => $journees,
            'activites' => $activites,
            'pendingDeclarations' => $pendingDeclarations,
            'rappelDepart' => [
                'heure' => $heureDepartAffichee,
                'minutes_avant' => 15,
            ],
        ]);
    }

    private function minutesWorkedUserBetween(int $userId, Carbon $start, Carbon $end): int
    {
        $events = Pointage::query()
            ->where('user_id', $userId)
            ->whereBetween('clocked_at', [$start, $end])
            ->orderBy('clocked_at')
            ->get();

        $byDay = $events->groupBy(fn (Pointage $p) => $p->clocked_at->format('Y-m-d'));

        $total = 0;
        foreach ($byDay as $items) {
            /** @var Collection<int, Pointage> $items */
            $sorted = $items->sortBy('clocked_at')->values();
            $arrivee = $sorted->firstWhere('type', 'arrivee');
            $depart = $sorted->filter(fn (Pointage $p) => $p->type === 'depart')->sortByDesc(fn (Pointage $p) => $p->clocked_at)->first();
            if ($arrivee && $depart) {
                $total += (int) $arrivee->clocked_at->diffInMinutes($depart->clocked_at);
            }
        }

        return $total;
    }

    private function countWeekdaysInRange(Carbon $from, Carbon $to): int
    {
        $profile = $this->defaultHoraireProfile();
        $calendrier = app(PointageHorairesCalendrierService::class);
        $n = 0;
        $d = $from->copy()->startOfDay();
        while ($d->lte($to)) {
            if ($profile === null) {
                if (! $d->isWeekend()) {
                    $n++;
                }
            } elseif ($calendrier->jourCompteDansBasePresence($d, $profile)) {
                $n++;
            }
            $d->addDay();
        }

        return $n;
    }

    private function defaultHoraireProfile(): ?PointageHoraireProfile
    {
        return PointageHoraireProfile::query()
            ->where('scope_type', 'global')
            ->where('actif', true)
            ->orderBy('id')
            ->first()
            ?? PointageHoraireProfile::query()->orderBy('id')->first();
    }

    private function jourStatutSansPointage(Carbon $jour): string
    {
        $profile = $this->defaultHoraireProfile();
        if ($profile === null) {
            return $jour->isWeekend() ? 'non_ouvre' : 'absent';
        }

        $calendrier = app(PointageHorairesCalendrierService::class);
        if (! $calendrier->jourCompteDansBasePresence($jour, $profile)) {
            return $calendrier->feriePourDate($jour) !== null ? 'ferie' : 'non_ouvre';
        }

        return 'absent';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function employeDernieresJourneesTable(int $userId, int $limit): array
    {
        $events = Pointage::query()
            ->where('user_id', $userId)
            ->where('clocked_at', '>=', now()->subDays(60))
            ->orderBy('clocked_at')
            ->get();

        $byDay = $events->groupBy(fn (Pointage $p) => $p->clocked_at->format('Y-m-d'));

        $days = $byDay->keys()->sortDesc()->values()->take($limit);

        $rows = [];
        foreach ($days as $dayStr) {
            $items = $byDay[$dayStr];
            $sorted = $items->sortBy('clocked_at')->values();
            $arrivee = $sorted->firstWhere('type', 'arrivee');
            $depart = $sorted->filter(fn (Pointage $p) => $p->type === 'depart')->sortByDesc(fn (Pointage $p) => $p->clocked_at)->first();

            $statut = 'normal';
            if ($sorted->contains(fn (Pointage $p) => $p->statut === 'retard')) {
                $statut = 'retard';
            }

            $heuresLabel = '—';
            if ($arrivee && $depart) {
                $mins = (int) $arrivee->clocked_at->diffInMinutes($depart->clocked_at);
                $heuresLabel = intdiv($mins, 60).'h'.str_pad((string) ($mins % 60), 2, '0', STR_PAD_LEFT);
            } elseif ($arrivee && ! $depart) {
                $heuresLabel = 'en cours';
            }

            $rows[] = [
                'date' => $dayStr,
                'arrivee' => $arrivee ? $arrivee->clocked_at->format('H:i') : '—',
                'depart' => $depart ? $depart->clocked_at->format('H:i') : '—',
                'heures' => $heuresLabel,
                'statut' => $statut,
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildEmployeActiviteRecente(\App\Models\User $user, string $siteLabel): array
    {
        $items = [];

        $lastArrivee = Pointage::query()
            ->where('user_id', $user->id)
            ->where('type', 'arrivee')
            ->with('agence:id,nom')
            ->orderByDesc('clocked_at')
            ->first();

        if ($lastArrivee) {
            $when = $lastArrivee->clocked_at->isToday()
                ? "Aujourd'hui à ".$lastArrivee->clocked_at->format('H:i')
                : $lastArrivee->clocked_at->locale('fr')->translatedFormat('d MMM').' à '.$lastArrivee->clocked_at->format('H:i');
            $items[] = [
                'kind' => 'pointage_arrivee',
                'title' => 'Pointage arrivée enregistré',
                'subtitle' => $when,
                'lieu' => $lastArrivee->agence?->nom ?? $siteLabel,
                'has_gps' => $lastArrivee->latitude !== null && $lastArrivee->longitude !== null,
                'has_bio' => (bool) $lastArrivee->biometric_ok,
                'sort' => $lastArrivee->clocked_at->timestamp,
            ];
        }

        $declRetard = PointageDeclaration::query()
            ->where('user_id', $user->id)
            ->where('statut', 'valide')
            ->where('type', 'retard')
            ->orderByDesc('rh_decided_at')
            ->orderByDesc('manager_decided_at')
            ->with(['managerUser:id,name', 'rhUser:id,name'])
            ->first();

        if ($declRetard) {
            $decided = $declRetard->rh_decided_at ?? $declRetard->manager_decided_at;
            $validateur = $declRetard->rhUser?->name ?? $declRetard->managerUser?->name ?? 'Manager';
            $parts = preg_split('/\s+/u', trim((string) $validateur)) ?: [];
            $shortVal = count($parts) >= 2
                ? mb_strtoupper(mb_substr($parts[0], 0, 1)).'. '.$parts[count($parts) - 1]
                : $validateur;
            $items[] = [
                'kind' => 'declaration_retard',
                'title' => 'Déclaration retard validée par le manager',
                'subtitle' => $decided ? $decided->locale('fr')->translatedFormat('d MMM').' — validé par '.$shortVal : 'Validée',
                'detail' => 'Motif : '.$declRetard->motif,
                'sort' => $decided ? $decided->timestamp : 0,
            ];
        }

        $prevMonth = now()->copy()->subMonth();
        $prevLabel = $prevMonth->locale('fr')->translatedFormat('F');
        $prevMonthMinutes = $this->minutesWorkedUserBetween($user->id, $prevMonth->copy()->startOfMonth(), $prevMonth->copy()->endOfMonth());
        $prevHours = (int) floor($prevMonthMinutes / 60);
        $items[] = [
            'kind' => 'rapport_mensuel',
            'title' => 'Rapport mensuel '.mb_convert_case($prevLabel, MB_CASE_TITLE, 'UTF-8').' disponible',
            'subtitle' => '01 '.now()->locale('fr')->translatedFormat('MMM').' — '.$prevHours.'h travaillées',
            'sort' => now()->copy()->startOfMonth()->timestamp - 100,
        ];

        $items[] = [
            'kind' => 'qr_site',
            'title' => 'Nouveau QR Code activé — '.$siteLabel,
            'subtitle' => now()->subDays(3)->locale('fr')->translatedFormat('d MMM').' — rotation dynamique activée',
            'sort' => now()->subDays(3)->timestamp,
        ];

        usort($items, fn ($a, $b) => ($b['sort'] ?? 0) <=> ($a['sort'] ?? 0));

        return array_slice($items, 0, 6);
    }

    private function renderDashboardManager(Request $request)
    {
        $user = Auth::user();
        $pending = PointageDeclaration::query()
            ->where('statut', 'en_attente_manager')
            ->count();

        $today = Carbon::today()->toDateString();
        $todayCount = Pointage::query()
            ->whereDate('clocked_at', $today)
            ->distinct()
            ->count('user_id');

        return Inertia::render('pointage/DashboardManager', [
            'pendingValidations' => $pending,
            'pointagesTodayDistinctUsers' => $todayCount,
        ]);
    }


    private function agenceFilterFromRequest(Request $request): string
    {
        $value = $request->query('agence', $request->query('site', 'tous'));

        return is_string($value) && $value !== '' ? $value : 'tous';
    }

    private function renderDashboardAdmin(Request $request)
    {
        $sites = Agence::query()->count();
        $sitesActifs = Agence::query()->where('actif', true)->count();
        $fraudes = PointageAuditLog::query()->where('severity', 'fraude')->where('created_at', '>=', now()->subDays(30))->count();

        return Inertia::render('pointage/DashboardAdmin', [
            'sites' => $sites,
            'sitesActifs' => $sitesActifs,
            'fraudes' => $fraudes,
        ]);
    }
}
