<?php

use App\Models\PointageDeclaration;
use App\Models\User;
use App\Services\Pointage\PointageHorairesAjustementService;
use Carbon\Carbon;

beforeEach(function () {
    config([
        'pointage.heure_arrivee' => '08:00',
        'pointage.heure_depart' => '17:00',
        'pointage.heure_arrivee_ajustee' => '08:00',
        'pointage.heure_depart_ajustee' => '17:00',
        'pointage.tolerance_minutes' => 15,
    ]);
});

test('allaitement entree 09h : pas de retard a 09h10', function () {
    $user = User::factory()->create();
    PointageDeclaration::query()->create([
        'user_id' => $user->id,
        'type' => 'allaitement',
        'date_concernee' => Carbon::today()->toDateString(),
        'date_fin' => Carbon::today()->toDateString(),
        'heure_debut' => '09:00',
        'heure_fin' => null,
        'motif' => 'Allaitement',
        'statut' => 'valide',
    ]);

    $svc = new PointageHorairesAjustementService;
    $at = Carbon::today()->setTime(9, 10);
    $r = $svc->computeEffectivePunch($at, 'arrivee', null, $user);

    expect($r['statut'])->toBe('normal')
        ->and($r['ajustement_applique'])->toBeTrue()
        ->and($r['heure_effective'])->toBe('9')
        ->and($r['allaitement']['sens'])->toBe('entree');
});

test('allaitement entree 09h : retard apres 09h15', function () {
    $user = User::factory()->create();
    PointageDeclaration::query()->create([
        'user_id' => $user->id,
        'type' => 'allaitement',
        'date_concernee' => Carbon::today()->toDateString(),
        'date_fin' => Carbon::today()->toDateString(),
        'heure_debut' => '09:00',
        'heure_fin' => null,
        'motif' => 'Allaitement',
        'statut' => 'valide',
    ]);

    $svc = new PointageHorairesAjustementService;
    $at = Carbon::today()->setTime(9, 16);
    $r = $svc->computeEffectivePunch($at, 'arrivee', null, $user);

    expect($r['statut'])->toBe('retard')
        ->and($r['heure_effective'])->toBe('09:16')
        ->and($r['ajustement_applique'])->toBeFalse();
});

test('allaitement sortie 16h : pointage 16h ramene a 17h', function () {
    $user = User::factory()->create();
    PointageDeclaration::query()->create([
        'user_id' => $user->id,
        'type' => 'allaitement',
        'date_concernee' => Carbon::today()->toDateString(),
        'date_fin' => Carbon::today()->addDays(7)->toDateString(),
        'heure_debut' => null,
        'heure_fin' => '16:00',
        'motif' => 'Allaitement',
        'statut' => 'valide',
    ]);

    $svc = new PointageHorairesAjustementService;
    $at = Carbon::today()->setTime(16, 0);
    $r = $svc->computeEffectivePunch($at, 'depart', null, $user);

    expect($r['heure_reelle'])->toBe('16:00')
        ->and($r['heure_effective'])->toBe('17')
        ->and($r['ajustement_applique'])->toBeTrue()
        ->and($r['allaitement']['sens'])->toBe('sortie');
});

test('allaitement non valide : horaires standards', function () {
    $user = User::factory()->create();
    PointageDeclaration::query()->create([
        'user_id' => $user->id,
        'type' => 'allaitement',
        'date_concernee' => Carbon::today()->toDateString(),
        'date_fin' => Carbon::today()->toDateString(),
        'heure_debut' => '09:00',
        'heure_fin' => null,
        'motif' => 'Allaitement',
        'statut' => 'en_attente_rh',
    ]);

    $svc = new PointageHorairesAjustementService;
    $at = Carbon::today()->setTime(8, 5);
    $r = $svc->computeEffectivePunch($at, 'arrivee', null, $user);

    expect($r['statut'])->toBe('normal')
        ->and($r['heure_effective'])->toBe('8')
        ->and($r['allaitement'])->toBeNull();
});
