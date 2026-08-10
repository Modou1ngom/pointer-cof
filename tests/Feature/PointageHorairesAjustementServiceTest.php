<?php

use App\Services\Pointage\PointageHorairesAjustementService;
use Carbon\Carbon;

beforeEach(function () {
    config([
        'pointage.heure_arrivee' => '08:00',
        'pointage.heure_depart' => '17:00',
        'pointage.heure_arrivee_ajustee' => '08:00',
        'pointage.heure_depart_ajustee' => '17:00',
        'pointage.tolerance_minutes' => 10,
    ]);
});

test('arrivee avant 08h : effective ajustee a 08h, reelle = pointe', function () {
    $svc = new PointageHorairesAjustementService;
    $at = Carbon::today()->setTime(7, 45);
    $r = $svc->computeEffectivePunch($at, 'arrivee');

    expect($r['heure_reelle'])->toBe('07:45')
        ->and($r['heure_effective'])->toBe('8')
        ->and($r['ajustement_applique'])->toBeTrue()
        ->and($r['statut'])->toBe('normal');
});

test('arrivee apres tolerance : effective = reelle (retard)', function () {
    $svc = new PointageHorairesAjustementService;
    $at = Carbon::today()->setTime(8, 25);
    $r = $svc->computeEffectivePunch($at, 'arrivee');

    expect($r['heure_reelle'])->toBe('08:25')
        ->and($r['heure_effective'])->toBe('08:25')
        ->and($r['ajustement_applique'])->toBeFalse()
        ->and($r['statut'])->toBe('retard');
});

test('depart avant 17h : effective = reelle', function () {
    $svc = new PointageHorairesAjustementService;
    $at = Carbon::today()->setTime(16, 30);
    $r = $svc->computeEffectivePunch($at, 'depart');

    expect($r['heure_reelle'])->toBe('16:30')
        ->and($r['heure_effective'])->toBe('16:30')
        ->and($r['ajustement_applique'])->toBeFalse();
});

test('depart apres 17h : effective ajustee a 17h, reelle = pointe', function () {
    $svc = new PointageHorairesAjustementService;
    $at = Carbon::today()->setTime(18, 10);
    $r = $svc->computeEffectivePunch($at, 'depart');

    expect($r['heure_reelle'])->toBe('18:10')
        ->and($r['heure_effective'])->toBe('17')
        ->and($r['ajustement_applique'])->toBeTrue();
});

test('depart pile a 17h : effective ajustee a 17h', function () {
    $svc = new PointageHorairesAjustementService;
    $at = Carbon::today()->setTime(17, 0);
    $r = $svc->computeEffectivePunch($at, 'depart');

    expect($r['heure_reelle'])->toBe('17:00')
        ->and($r['heure_effective'])->toBe('17')
        ->and($r['ajustement_applique'])->toBeTrue();
});
