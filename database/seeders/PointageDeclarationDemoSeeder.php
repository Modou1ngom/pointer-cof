<?php

namespace Database\Seeders;

use App\Models\Filiale;
use App\Models\PointageDeclaration;
use App\Models\Profil;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * Déclarations de démo pour visualiser le parcours Demande
 * (onglets En attente / Historique / Toutes + filtres par type).
 *
 * Prérequis : PointageDemoSeeder (comptes RH / employés).
 * Mot de passe comptes créés ici : password
 *
 * php artisan db:seed --class=PointageDeclarationDemoSeeder
 */
class PointageDeclarationDemoSeeder extends Seeder
{
    private const DEMO_MOTIF_TAG = '[DEMO]';

    public function run(): void
    {
        $pwd = Hash::make('password');
        $roleMetier = Role::query()->where('slug', 'metier')->first();
        $filiale = Filiale::query()->where('nom', 'Cofina Démo')->first()
            ?? Filiale::query()->first();

        if (! $roleMetier || ! $filiale) {
            $this->command?->warn('PointageDeclarationDemoSeeder : lancez d’abord RoleSeeder + PointageDemoSeeder.');

            return;
        }

        $managerProfil = Profil::query()->where('email', 'manager.demo@cofina.sn')->first();
        $managerUser = User::query()->updateOrCreate(
            ['email' => 'manager.demo@cofina.sn'],
            [
                'name' => 'Fatou Sarr',
                'password' => $pwd,
                'email_verified_at' => now(),
                'is_active' => true,
                'must_change_password' => false,
            ]
        );
        $managerUser->roles()->syncWithoutDetaching([$roleMetier->id]);
        $managerUser->filiales()->syncWithoutDetaching([$filiale->id]);

        $rhUser = User::query()->where('email', 'rh.demo@cofina.sn')->first()
            ?? User::query()->where('email', 'superadmin.demo@cofina.sn')->first();

        $employes = [
            [
                'email' => 'haby.sow.demo@cofina.sn',
                'name' => 'Haby Sow',
                'prenom' => 'Haby',
                'nom' => 'Sow',
                'matricule' => 'EMP-DEMO-HS',
                'fonction' => 'CHARGE DATA ANALYTICS',
            ],
            [
                'email' => 'cheikhouna.ba.demo@cofina.sn',
                'name' => 'Cheikhouna Ba',
                'prenom' => 'Cheikhouna',
                'nom' => 'Ba',
                'matricule' => 'EMP-DEMO-CB',
                'fonction' => 'ANALYSTE CREDIT',
            ],
            [
                'email' => 'awa.fall.demo@cofina.sn',
                'name' => 'Awa Fall',
                'prenom' => 'Awa',
                'nom' => 'Fall',
                'matricule' => 'EMP-DEMO-AF',
                'fonction' => 'ASSISTANTE RH',
            ],
            [
                'email' => 'ndick.faye.demo@cofina.sn',
                'name' => 'Ndick Faye',
                'prenom' => 'Ndick',
                'nom' => 'Faye',
                'matricule' => 'EMP-DEMO-NF',
                'fonction' => 'CHARGE CLIENT',
            ],
        ];

        $userIds = [];
        foreach ($employes as $row) {
            Profil::query()->updateOrCreate(
                ['email' => $row['email']],
                [
                    'matricule' => $row['matricule'],
                    'prenom' => $row['prenom'],
                    'nom' => $row['nom'],
                    'fonction' => $row['fonction'],
                    'departement' => 'Finance',
                    'telephone' => '+22177'.substr((string) crc32($row['email']), 0, 7),
                    'site' => 'Dakar Plateau',
                    'statut' => 'actif',
                    'filiale_id' => $filiale->id,
                    'n_plus_1_id' => $managerProfil?->id,
                    'n_plus_2_id' => null,
                ]
            );

            $user = User::query()->updateOrCreate(
                ['email' => $row['email']],
                [
                    'name' => $row['name'],
                    'password' => $pwd,
                    'email_verified_at' => now(),
                    'is_active' => true,
                    'must_change_password' => false,
                ]
            );
            $user->roles()->syncWithoutDetaching([$roleMetier->id]);
            $user->filiales()->syncWithoutDetaching([$filiale->id]);
            $userIds[$row['email']] = $user->id;
        }

        $amadou = User::query()->where('email', 'yacin36juz@gmail.com')->first()
            ?? User::query()->where('email', 'employe.demo@cofina.sn')->first();
        if ($amadou) {
            $userIds[$amadou->email] = $amadou->id;
        }

        // Nettoyage des anciennes démos pour rejouer le seeder sans doublons.
        PointageDeclaration::query()
            ->where('motif', 'like', self::DEMO_MOTIF_TAG.'%')
            ->delete();

        $today = Carbon::today();
        $hasExtra = Schema::hasColumn('pointage_declarations', 'date_fin');

        $samples = [
            // —— En attente (badge) ——
            [
                'email' => 'haby.sow.demo@cofina.sn',
                'type' => 'absence',
                'date_concernee' => $today->copy()->day(11)->toDateString(),
                'date_fin' => $today->copy()->day(11)->toDateString(),
                'motif' => self::DEMO_MOTIF_TAG.' Absence ponctuelle — rendez-vous administratif',
                'statut' => 'en_attente_rh',
                'manager_user_id' => $managerUser->id,
                'manager_decided_at' => now()->subHours(2),
                'manager_comment' => 'OK pour transmission RH',
            ],
            [
                'email' => 'cheikhouna.ba.demo@cofina.sn',
                'type' => 'conge_maladie',
                'date_concernee' => $today->copy()->day(12)->toDateString(),
                'date_fin' => $today->copy()->day(14)->toDateString(),
                'motif' => self::DEMO_MOTIF_TAG.' Congé maladie — certificat médical joint',
                'statut' => 'en_attente_rh',
                'manager_user_id' => $managerUser->id,
                'manager_decided_at' => now()->subDay(),
                'manager_comment' => 'Justificatif reçu',
            ],
            [
                'email' => 'awa.fall.demo@cofina.sn',
                'type' => 'permission_exceptionnelle',
                'date_concernee' => $today->copy()->day(10)->toDateString(),
                'date_fin' => $today->copy()->day(10)->toDateString(),
                'heure_debut' => '09:00',
                'heure_fin' => '12:00',
                'motif' => self::DEMO_MOTIF_TAG.' Permission exceptionnelle — demi-journée',
                'statut' => 'en_attente_rh',
                'manager_user_id' => $managerUser->id,
                'manager_decided_at' => now()->subHours(5),
            ],
            [
                'email' => $amadou?->email ?? 'haby.sow.demo@cofina.sn',
                'type' => 'conge_annuel',
                'date_concernee' => $today->copy()->day(18)->toDateString(),
                'date_fin' => $today->copy()->day(22)->toDateString(),
                'motif' => self::DEMO_MOTIF_TAG.' Congé annuel — en attente N+1',
                'statut' => 'en_attente_manager',
            ],
            [
                'email' => 'ndick.faye.demo@cofina.sn',
                'type' => 'mission',
                'date_concernee' => $today->copy()->day(15)->toDateString(),
                'date_fin' => $today->copy()->day(16)->toDateString(),
                'lieu' => 'Agence Thiès',
                'motif' => self::DEMO_MOTIF_TAG.' Mission client — Thiès',
                'statut' => 'en_attente_manager',
            ],
            // —— Historique (validé / rejeté) ——
            [
                'email' => 'haby.sow.demo@cofina.sn',
                'type' => 'formation',
                'date_concernee' => $today->copy()->day(4)->toDateString(),
                'date_fin' => $today->copy()->day(5)->toDateString(),
                'lieu' => 'Centre de formation Dakar',
                'motif' => self::DEMO_MOTIF_TAG.' Formation Excel avancé',
                'statut' => 'valide',
                'manager_user_id' => $managerUser->id,
                'manager_decided_at' => $today->copy()->day(2)->setTime(10, 0),
                'rh_user_id' => $rhUser?->id,
                'rh_decided_at' => $today->copy()->day(3)->setTime(9, 30),
                'rh_comment' => 'Validé RH',
            ],
            [
                'email' => 'cheikhouna.ba.demo@cofina.sn',
                'type' => 'conge_annuel',
                'date_concernee' => $today->copy()->day(1)->toDateString(),
                'date_fin' => $today->copy()->day(3)->toDateString(),
                'motif' => self::DEMO_MOTIF_TAG.' Congé annuel — validé',
                'statut' => 'valide',
                'manager_user_id' => $managerUser->id,
                'manager_decided_at' => $today->copy()->subDays(8)->setTime(11, 0),
                'rh_user_id' => $rhUser?->id,
                'rh_decided_at' => $today->copy()->subDays(7)->setTime(14, 0),
            ],
            [
                'email' => 'awa.fall.demo@cofina.sn',
                'type' => 'absence',
                'date_concernee' => $today->copy()->day(6)->toDateString(),
                'date_fin' => $today->copy()->day(6)->toDateString(),
                'motif' => self::DEMO_MOTIF_TAG.' Absence rejetée — motif insuffisant',
                'statut' => 'rejete',
                'manager_user_id' => $managerUser->id,
                'manager_decided_at' => $today->copy()->day(5)->setTime(16, 0),
                'manager_comment' => 'Transmis RH',
                'rh_user_id' => $rhUser?->id,
                'rh_decided_at' => $today->copy()->day(6)->setTime(9, 0),
                'rh_comment' => 'Rejeté : justificatif manquant',
            ],
            [
                'email' => 'ndick.faye.demo@cofina.sn',
                'type' => 'permission_exceptionnelle',
                'date_concernee' => $today->copy()->day(8)->toDateString(),
                'date_fin' => $today->copy()->day(8)->toDateString(),
                'heure_debut' => '14:00',
                'heure_fin' => '17:00',
                'motif' => self::DEMO_MOTIF_TAG.' Permission validée — après-midi',
                'statut' => 'valide',
                'manager_user_id' => $managerUser->id,
                'manager_decided_at' => $today->copy()->day(7)->setTime(10, 0),
                'rh_user_id' => $rhUser?->id,
                'rh_decided_at' => $today->copy()->day(7)->setTime(15, 0),
            ],
        ];

        $created = 0;
        foreach ($samples as $sample) {
            $uid = $userIds[$sample['email']] ?? null;
            if (! $uid) {
                continue;
            }

            $payload = [
                'user_id' => $uid,
                'type' => $sample['type'],
                'date_concernee' => $sample['date_concernee'],
                'motif' => $sample['motif'],
                'commentaire' => 'Donnée de démonstration pour le parcours Demande.',
                'statut' => $sample['statut'],
                'manager_user_id' => $sample['manager_user_id'] ?? null,
                'manager_decided_at' => $sample['manager_decided_at'] ?? null,
                'manager_comment' => $sample['manager_comment'] ?? null,
                'rh_user_id' => $sample['rh_user_id'] ?? null,
                'rh_decided_at' => $sample['rh_decided_at'] ?? null,
                'rh_comment' => $sample['rh_comment'] ?? null,
            ];

            if ($hasExtra) {
                $payload['date_fin'] = $sample['date_fin'] ?? null;
                $payload['heure_debut'] = $sample['heure_debut'] ?? null;
                $payload['heure_fin'] = $sample['heure_fin'] ?? null;
                $payload['lieu'] = $sample['lieu'] ?? null;
            }

            PointageDeclaration::query()->create($payload);
            $created++;
        }

        $this->command?->info("PointageDeclarationDemoSeeder : {$created} déclarations [DEMO] créées pour {$today->format('Y-m')}.");
        $this->command?->info('Connectez-vous en RH (rh.demo@cofina.sn / password) → Pointage → Demande.');
        $this->command?->table(
            ['Onglet', 'Ce que vous verrez'],
            [
                ['En attente de Validation', 'Badge + absences / congés / permissions / missions en attente'],
                ['Historique', 'Demandes validées et rejetées'],
                ['Toutes les demandes', 'Tout le mois + filtres colonnes (Absence, Congé annuel…)'],
            ]
        );
    }
}
