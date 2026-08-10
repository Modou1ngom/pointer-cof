<?php

use App\Http\Controllers\AgenceController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DepartementController;
use App\Http\Controllers\FilialeController;
use App\Http\Controllers\MobilePointageScanController;
use App\Http\Controllers\MobilePointrustWebLoginController;
use App\Http\Controllers\PointageKioskController;
use App\Http\Controllers\PointageController;
use App\Http\Controllers\PointageDeclarationController;
use App\Http\Controllers\PointageHorairesRhController;
use App\Http\Controllers\PointageRapportController;
use App\Http\Controllers\PointageRhAffectationController;
use App\Http\Controllers\PointageSiteController;
use App\Http\Controllers\ProfilController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

/** Connexion PointRust pour WebView mobile (JWT via /api/login) — même backend que le web. */
Route::get('/mobile/pointrust/login', [MobilePointrustWebLoginController::class, 'show'])
    ->name('pointrust.mobile.web-login');

Route::redirect('/app/login', '/mobile/pointrust/login')->name('pointrust.mobile.web-login-short');

/** Page ouverte au scan d’un QR site (URL HTTPS → app mobile ou portail web). */
Route::get('/mobile/pointage/scan', [MobilePointageScanController::class, 'show'])
    ->name('pointage.mobile.scan');

/** Borne / tablette : affichage QR site (accès par jeton secret d’agence). */
Route::get('/pointage/kiosk/{token}', [PointageKioskController::class, 'show'])
    ->where('token', '[a-f0-9]{32,64}')
    ->name('pointage.kiosk.show');
Route::get('/pointage/kiosk/{token}/qr', [PointageKioskController::class, 'refresh'])
    ->where('token', '[a-f0-9]{32,64}')
    ->name('pointage.kiosk.qr');
Route::post('/pointage/kiosk/{token}/location', [PointageKioskController::class, 'syncLocation'])
    ->where('token', '[a-f0-9]{32,64}')
    ->name('pointage.kiosk.location');

Route::get('/', function () {
    return redirect()->route('login');
})->name('home');

Route::get('dashboard', [DashboardController::class, 'index'])->middleware(['auth', 'verified'])->name('dashboard');

// Route pour le changement de mot de passe obligatoire
Route::middleware(['auth'])->group(function () {
    Route::get('password/change', [\App\Http\Controllers\ChangePasswordController::class, 'show'])->name('password.change');
    Route::post('password/change', [\App\Http\Controllers\ChangePasswordController::class, 'update'])->name('password.change.update');
});

require __DIR__.'/settings.php';

// Routes pour les profils - Admin et RH peuvent créer/éditer/supprimer
Route::middleware(['auth'])->group(function () {
    Route::get('profils/import', [ProfilController::class, 'showImport'])->name('profils.import')->middleware('role:admin,rh');
    Route::post('profils/import', [ProfilController::class, 'import'])->name('profils.import.store')->middleware('role:admin,rh');
    Route::get('profils/export', [ProfilController::class, 'export'])->name('profils.export')->middleware('role:admin,rh');
    Route::post('profils/sync-comptes', [ProfilController::class, 'syncComptesManquants'])->name('profils.sync-comptes')->middleware('role:admin');
    Route::resource('profils', ProfilController::class)->middleware('role:admin,rh');
    Route::resource('roles', RoleController::class)->middleware('role:admin');
    Route::resource('departements', DepartementController::class)->middleware('role:admin');
    Route::resource('agences', AgenceController::class)->middleware('role:admin');
    Route::resource('filiales', FilialeController::class)->middleware('role:admin');
    Route::prefix('pointage')->name('pointage.')->group(function () {
        Route::middleware('rh.pointage')->group(function () {
            Route::get('/', [PointageController::class, 'dashboard'])->name('index');
            Route::get('/pointer', [PointageController::class, 'pointer'])->name('pointer');
            Route::get('/historique', [PointageController::class, 'historique'])->name('historique');
            Route::get('/historique/export', [PointageController::class, 'historiqueExport'])->name('historique.export');
            Route::get('/profil-pointage', [PointageController::class, 'profil'])->name('profil');
            Route::post('/otp/send', [PointageController::class, 'sendPointageOtp'])->name('otp.send');
            Route::post('/otp/verify', [PointageController::class, 'verifyPointageOtp'])->name('otp.verify');
            Route::post('/enregistrer', [PointageController::class, 'store'])->name('store');

            Route::get('/equipe', [PointageController::class, 'equipe'])->name('equipe');
            Route::get('/retards-absences', [PointageController::class, 'retards'])->name('retards');
            Route::get('/rapports-service', [PointageController::class, 'rapportsService'])->name('rapports-service');

            Route::get('/declarations', [PointageDeclarationController::class, 'index'])->name('declarations.index');
            Route::get('/declarations/create', [PointageDeclarationController::class, 'create'])->name('declarations.create');
            Route::post('/declarations', [PointageDeclarationController::class, 'store'])->name('declarations.store');
            Route::get('/demandes', [PointageDeclarationController::class, 'demande'])
                ->name('declarations.demande');
            Route::get('/declarations/regulation', function () {
                return redirect()->route('pointage.declarations.demande', request()->query());
            })->name('declarations.regulation');
            Route::put('/declarations/{declaration}', [PointageDeclarationController::class, 'update'])
                ->name('declarations.update');
            Route::delete('/declarations/{declaration}', [PointageDeclarationController::class, 'destroy'])
                ->name('declarations.destroy');
            Route::get('/declarations/validation-manager', [PointageDeclarationController::class, 'validationManager'])
                ->name('declarations.validation-manager');
            Route::post('/declarations/{declaration}/decision-manager', [PointageDeclarationController::class, 'decisionManager'])
                ->name('declarations.decision-manager');

            Route::match(['get', 'post'], '/rh/presence/jours-ouvrables', [PointageHorairesRhController::class, 'joursOuvrables'])
                ->name('rh.presence.jours-ouvrables');
            Route::match(['get', 'post'], '/rh/presence/week-ends', [PointageHorairesRhController::class, 'weekEnds'])
                ->name('rh.presence.week-ends');
            Route::match(['get', 'post'], '/rh/presence/jours-feries', [PointageHorairesRhController::class, 'joursFeries'])
                ->name('rh.presence.jours-feries');
            Route::post('/rh/presence/jours-feries/nager/preview', [PointageHorairesRhController::class, 'previewJoursFeriesNager'])
                ->name('rh.presence.jours-feries.nager.preview');
            Route::post('/rh/presence/jours-feries/nager/confirm', [PointageHorairesRhController::class, 'confirmJoursFeriesNager'])
                ->name('rh.presence.jours-feries.nager.confirm');
            Route::put('/rh/presence/jours-feries/{ferie}', [PointageHorairesRhController::class, 'updateJourFerie'])
                ->name('rh.presence.jours-feries.update');
            Route::post('/rh/presence/jours-feries/{ferie}/clone', [PointageHorairesRhController::class, 'cloneJourFerie'])
                ->name('rh.presence.jours-feries.clone');
            Route::delete('/rh/presence/jours-feries/{ferie}', [PointageHorairesRhController::class, 'destroyJourFerie'])
                ->name('rh.presence.jours-feries.destroy');
            Route::get('/rh/presence/jours-feries-calendrier', [PointageHorairesRhController::class, 'joursFeriesCalendrier'])
                ->name('rh.presence.jours-feries-calendrier');
            Route::get('/rh/presence/jours-feries-calendrier/pdf', [PointageHorairesRhController::class, 'joursFeriesCalendrierPdf'])
                ->name('rh.presence.jours-feries-calendrier.pdf');
            Route::match(['get', 'post'], '/rh/presence/pauses/dejeuner', [PointageHorairesRhController::class, 'pauseDejeuner'])
                ->name('rh.presence.pauses.dejeuner');
            Route::match(['get', 'post'], '/rh/presence/pauses/technique', [PointageHorairesRhController::class, 'pauseTechnique'])
                ->name('rh.presence.pauses.technique');
            Route::match(['get', 'post'], '/rh/presence/pauses/duree', [PointageHorairesRhController::class, 'pauseDuree'])
                ->name('rh.presence.pauses.duree');

            Route::get('/rh/employes', [PointageController::class, 'rhEmployes'])->name('rh.employes');
            Route::prefix('rh/affectations')->name('rh.affectations.')->group(function () {
                Route::post('profil', [PointageRhAffectationController::class, 'storeProfil'])->name('profil.store');
                Route::post('lookup', [PointageRhAffectationController::class, 'lookup'])->name('lookup');
                Route::post('enroll', [PointageRhAffectationController::class, 'enroll'])->name('enroll');
                Route::get('{affectation}', [PointageRhAffectationController::class, 'show'])->name('show');
                Route::patch('{affectation}/statut', [PointageRhAffectationController::class, 'toggleStatut'])->name('statut');
                Route::post('{affectation}/parametrage', [PointageRhAffectationController::class, 'saveParametrage'])->name('parametrage');
                Route::post('{affectation}/agences', [PointageRhAffectationController::class, 'attachAgence'])->name('agences.attach');
                Route::patch('{affectation}/agences/{agence}', [PointageRhAffectationController::class, 'updateAgence'])->name('agences.update');
                Route::delete('{affectation}/agences/{agence}', [PointageRhAffectationController::class, 'detachAgence'])->name('agences.detach');
                Route::post('{affectation}/agences/{agence}/principal', [PointageRhAffectationController::class, 'setAgencePrincipale'])->name('agences.principal');
            });
            Route::get('/rh/tous-pointages/export', [PointageController::class, 'rhTousPointagesExport'])->name('rh.tous-pointages.export');
            Route::get('/rh/tous-pointages', [PointageController::class, 'rhTousPointages'])->name('rh.tous-pointages');
            Route::get('/rh/presence/recuperation-pointages/export', [PointageController::class, 'rhRecuperationPointagesExport'])
                ->name('rh.presence.recuperation-pointages.export');
            Route::get('/rh/presence/recuperation-pointages', [PointageController::class, 'rhRecuperationPointages'])
                ->name('rh.presence.recuperation-pointages');
            Route::get('/rh/parametrage', [PointageController::class, 'rhParametrage'])->name('rh.parametrage');
            Route::get('/rh/parametrage/export-fiche', [PointageController::class, 'rhParametrageFicheExport'])->name('rh.parametrage.export-fiche');
            Route::post('/rh/parametrage', [PointageController::class, 'rhParametrageUpdate'])->name('rh.parametrage.update');
            Route::post('/rh/parametrage/plages', [PointageController::class, 'rhParametragePlagesUpdate'])->name('rh.parametrage.plages');

            Route::post('sites/regenerer-tous-qr', [PointageSiteController::class, 'regenererTousQr'])
                ->name('sites.regenerer-tous-qr');
            Route::get('sites/lookup-qr', [PointageSiteController::class, 'lookupQrParCode'])->name('sites.lookup-qr');
            Route::post('sites/{site}/qr-configuration', [PointageSiteController::class, 'updateQrConfiguration'])
                ->name('sites.qr-configuration');
            Route::post('sites/{site}/desactiver-qr', [PointageSiteController::class, 'desactiverQr'])->name('sites.desactiver-qr');
            Route::post('sites/update-rayons', [PointageSiteController::class, 'updateRayons'])
                ->name('sites.update-rayons');
            Route::post('sites/{site}/creer-virtuelle', [PointageSiteController::class, 'storeVirtual'])
                ->name('sites.creer-virtuelle');
            Route::post('sites/{site}/kiosk-serial', [PointageSiteController::class, 'updateKioskSerial'])
                ->name('sites.kiosk-serial');
            Route::resource('sites', PointageSiteController::class)->except(['show']);
            Route::post('sites/{site}/regenerer-qr', [PointageSiteController::class, 'regenererQr'])
                ->name('sites.regenerer-qr');
            Route::post('sites/{site}/regenerer-lien-kiosk', [PointageSiteController::class, 'regenererLienKiosk'])
                ->name('sites.regenerer-lien-kiosk');
            Route::post('sites/{site}/toggle-actif', [PointageSiteController::class, 'toggleActif'])
                ->name('sites.toggle-actif');
            Route::get('/declarations/validation-rh', [PointageDeclarationController::class, 'validationRh'])
                ->name('declarations.validation-rh');
            Route::post('/declarations/{declaration}/decision-rh', [PointageDeclarationController::class, 'decisionRh'])
                ->name('declarations.decision-rh');
            Route::get('/rapport', [PointageRapportController::class, 'index'])->name('rapport');
            Route::get('/rapport/reporting', [PointageRapportController::class, 'index'])->name('rapport.reporting');
            Route::get('/rapport/reporting/export', [PointageRapportController::class, 'export'])
                ->name('rapport.reporting.export');
            Route::get('/rapport/export-mensuel-rh', [PointageRapportController::class, 'exportMensuelRh'])
                ->name('rapport.export-mensuel-rh');
            Route::get('/rapport/export-quotidien', [PointageRapportController::class, 'exportQuotidien'])
                ->name('rapport.export-quotidien');
            Route::get('/rapport/export-journalier-rh', [PointageRapportController::class, 'exportJournalierRh'])
                ->name('rapport.export-journalier-rh');
            Route::get('/rapport/export-synthese-rh', [PointageRapportController::class, 'exportSyntheseRh'])
                ->name('rapport.export-synthese-rh');

            Route::get('/admin/qrcodes', [PointageController::class, 'adminQrcodes'])->name('admin.qrcodes');
            Route::get('/admin/logs', [PointageController::class, 'adminLogs'])->name('admin.logs');
            Route::get('/admin/securite', [PointageController::class, 'adminSecurite'])->name('admin.securite');
        });
    });

    Route::resource('users', UserController::class)->middleware('role:admin');
    Route::post('users/{user}/toggle', [UserController::class, 'toggle'])->name('users.toggle')->middleware('role:admin');

});
