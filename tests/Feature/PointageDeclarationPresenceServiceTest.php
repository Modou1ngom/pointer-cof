<?php

namespace Tests\Feature;

use App\Models\Pointage;
use App\Models\PointageDeclaration;
use App\Models\User;
use App\Services\Pointage\PointageDeclarationPresenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PointageDeclarationPresenceServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_validation_rh_applique_heures_effectives_8h_et_17h(): void
    {
        $user = User::factory()->create();

        $declaration = PointageDeclaration::query()->create([
            'user_id' => $user->id,
            'type' => 'absence',
            'date_concernee' => '2026-08-07',
            'date_fin' => '2026-08-07',
            'motif' => 'Absence justifiée',
            'statut' => 'valide',
        ]);

        $n = app(PointageDeclarationPresenceService::class)->appliquerApresValidationRh($declaration);

        $this->assertSame(2, $n);

        $arrivee = Pointage::query()
            ->where('user_id', $user->id)
            ->where('type', 'arrivee')
            ->whereDate('clocked_at', '2026-08-07')
            ->first();
        $depart = Pointage::query()
            ->where('user_id', $user->id)
            ->where('type', 'depart')
            ->whereDate('clocked_at', '2026-08-07')
            ->first();

        $this->assertNotNull($arrivee);
        $this->assertNotNull($depart);
        $this->assertSame('08:00', $arrivee->meta['heure_effective'] ?? null);
        $this->assertSame('17:00', $depart->meta['heure_effective'] ?? null);
        $this->assertSame('normal', $arrivee->statut);
    }

    public function test_regularisation_entree_seule(): void
    {
        $user = User::factory()->create();

        $declaration = PointageDeclaration::query()->create([
            'user_id' => $user->id,
            'type' => 'regularisation',
            'date_concernee' => '2026-08-08',
            'motif' => 'Non pointage — entrée',
            'heure_debut' => '08:00',
            'statut' => 'valide',
        ]);

        app(PointageDeclarationPresenceService::class)->appliquerApresValidationRh($declaration);

        $this->assertTrue(
            Pointage::query()->where('user_id', $user->id)->where('type', 'arrivee')->exists()
        );
        $this->assertFalse(
            Pointage::query()->where('user_id', $user->id)->where('type', 'depart')->exists()
        );
    }
}
