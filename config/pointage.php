<?php

return [
    'heure_arrivee' => env('POINTAGE_HEURE_ARRIVEE', '08:00'),
    'heure_depart' => env('POINTAGE_HEURE_DEPART', '17:00'),

    /**
     * Heures de référence « ajustées » pour le reporting (présence / temps de travail).
     * Par défaut identiques aux heures prévues ; modifiables dans le paramétrage RH.
     */
    'heure_arrivee_ajustee' => env('POINTAGE_HEURE_ARRIVEE_AJUSTEE', '08:00'),
    'heure_depart_ajustee' => env('POINTAGE_HEURE_DEPART_AJUSTEE', '17:00'),

    /** Heures de référence « journée légale » pour base mensuelle & heures sup. (affichage employé). */
    'base_heures_jour_reference' => (float) env('POINTAGE_BASE_HEURES_JOUR', 8),
    'tolerance_minutes' => (int) env('POINTAGE_TOLERANCE_MINUTES', 10),

    /** Plages horaires pour déduire arrivée / départ au scan (heure locale serveur). */
    'plage_arrivee_debut' => env('POINTAGE_PLAGE_ARRIVEE_DEBUT', '07:00'),
    'plage_arrivee_fin' => env('POINTAGE_PLAGE_ARRIVEE_FIN', '12:00'),
    'plage_depart_debut' => env('POINTAGE_PLAGE_DEPART_DEBUT', '15:00'),
    'plage_depart_fin' => env('POINTAGE_PLAGE_DEPART_FIN', '20:00'),
    'qr_dynamic_ttl_seconds' => (int) env('POINTAGE_QR_TTL', 300),
    /** TTL des QR static / agence virtuelle (défaut 10 ans). */
    'qr_static_ttl_seconds' => (int) env('POINTAGE_QR_STATIC_TTL', 86400 * 365 * 10),

    /**
     * Jours où le QR Code est totalement désactivé (CSV de jours de la semaine,
     * 0 = dimanche, 1 = lundi … 6 = samedi). Défaut : 0 (dimanche).
     */
    'qr_inactif_jours' => env('POINTAGE_QR_INACTIF_JOURS', '0'),

    /**
     * Jours où le pointage n'est pas obligatoire : QR actif, mais seuls les staffs
     * qui pointent sont enregistrés (aucune absence comptée). Défaut : 6 (samedi).
     */
    'qr_optionnel_jours' => env('POINTAGE_QR_OPTIONNEL_JOURS', '6'),

    /** Refuser le scan / pointage si la position GPS est hors du rayon du site. */
    'geofencing_enabled' => env('POINTAGE_GEOFENCING_ENABLED') !== null
        ? filter_var(env('POINTAGE_GEOFENCING_ENABLED'), FILTER_VALIDATE_BOOLEAN)
        : true,

    /** Rayon par défaut (m) si non défini sur l’agence. */
    'default_geofencing_radius_metres' => (int) env('POINTAGE_DEFAULT_GEOFENCING_RADIUS', 50),

    /**
     * Borne / tablette QR : si la localisation est active, enregistrer automatiquement
     * la position GPS de la tablette comme coordonnées du site (référence géorepérage).
     */
    'kiosk_auto_site_gps' => env('POINTAGE_KIOSK_AUTO_SITE_GPS') !== null
        ? filter_var(env('POINTAGE_KIOSK_AUTO_SITE_GPS'), FILTER_VALIDATE_BOOLEAN)
        : true,

    /** Précision max (m) acceptée pour synchroniser le GPS site depuis la borne. */
    'kiosk_auto_site_gps_max_accuracy_metres' => (int) env('POINTAGE_KIOSK_AUTO_SITE_GPS_MAX_ACCURACY', 100),

    /**
     * QR imprimé / affiché : encoder une URL (scan → page web + deep link app).
     * false = jeton brut (ancien comportement).
     */
    'qr_encode_as_url' => env('POINTAGE_QR_ENCODE_AS_URL') !== null
        ? filter_var(env('POINTAGE_QR_ENCODE_AS_URL'), FILTER_VALIDATE_BOOLEAN)
        : true,

    /**
     * URL de la page ouverte au scan (défaut : {APP_URL}/mobile/pointage/scan).
     */
    'qr_scan_base_url' => env('POINTAGE_QR_SCAN_URL'),

    /** Deep link app mobile : cofipointe://pointage/scan?t=… */
    'mobile_app_scheme' => env('POINTAGE_MOBILE_APP_SCHEME', 'cofipointe'),

    /** Solde congés affiché sur le tableau de bord employé (indicateur — brancher sur la paie). */
    'employe_solde_conges_jours' => (int) env('POINTAGE_EMPLOYE_SOLDE_CONGES', 12),

    'employe_conges_a_prendre_jours' => (int) env('POINTAGE_EMPLOYE_CONGES_A_PRENDRE', 5),

    /** Pénalité indicative par retard validé (FCFA, affichage dashboard). */
    'employe_penalty_retard_fcfa' => (int) env('POINTAGE_EMPLOYE_PENALTY_RETARD', 2500),

    /** Seuil déclaratif heures sup. (h/jour) — affichage & exports RH. */
    'seuil_heures_supplementaires_h_jour' => (float) env('POINTAGE_SEUIL_HS_JOUR', 9),

    /** Délai max (heures) pour validation manager des déclarations. */
    'delai_validation_manager_heures' => (int) env('POINTAGE_DELAI_MANAGER_H', 48),

    /** Relances automatiques après X heures sans réponse. */
    'relances_automatiques_apres_heures' => (int) env('POINTAGE_RELANCES_H', 24),

    /** Pénalité absence non justifiée (FCFA / jour) — indicateurs RH. */
    'penalite_absence_injustifiee_fcfa_jour' => (int) env('POINTAGE_PENALITE_ABSENCE', 8000),

    /** Majoration heures supplémentaires (%). */
    'majoration_heures_sup_pct' => (int) env('POINTAGE_MAJORATION_HS_PCT', 25),

    /**
     * Mode planification export Sage : mensuel_auto_1er | mensuel_manuel | hebdomadaire.
     */
    'mode_export_sage_paie' => env('POINTAGE_MODE_EXPORT_SAGE', 'mensuel_auto_1er'),

    /**
     * Motifs de déclaration autorisés (cases à cocher RH — les types métier restent dans le code).
     *
     * @var array<string, bool>
     */
    'declaration_motifs_autorises' => [
        'maladie_certificat' => true,
        'mission_externe' => true,
        'conge_annuel' => true,
        'formation_professionnelle' => true,
        'cas_exceptionnel' => true,
        'deuil' => true,
    ],

    /** Déverrouillage pointage — uniquement si APP_DEBUG (sinon laisser vide). */
    'dev_unlock_code' => env('POINTAGE_DEV_UNLOCK_CODE'),

    /**
     * Mailer Laravel réservé à l’e-mail OTP (null = utilise mail.default).
     * Ex. POINTAGE_OTP_MAIL_MAILER=log si SMTP global est mal configuré mais vous voulez tester le flux.
     */
    'otp_mail_mailer' => env('POINTAGE_OTP_MAIL_MAILER'),

    /**
     * Si l’envoi OTP échoue, réessayer avec le mailer « log » (code tracé dans storage/logs).
     * Par défaut : activé en local uniquement (désactivez en prod sauf urgence).
     */
    'otp_email_fallback_log' => env('POINTAGE_OTP_EMAIL_FALLBACK_LOG') !== null
        ? filter_var(env('POINTAGE_OTP_EMAIL_FALLBACK_LOG'), FILTER_VALIDATE_BOOLEAN)
        : env('APP_ENV') === 'local',

    /**
     * OTP pointage (e-mail + SMS) : en local, les SMS sont journalisés (driver log).
     *
     * Drivers : log (défaut), twilio (API REST — variables TWILIO_*).
     *
     * @see \App\Services\PointageOtpService
     */
    'otp_sms_enabled' => (bool) env('POINTAGE_OTP_SMS_ENABLED', true),
    'otp_sms_driver' => env('POINTAGE_OTP_SMS_DRIVER', 'log'),

    /** Indicatif pays par défaut si le numéro profil est en format local (ex. 221 = Sénégal). */
    'otp_sms_default_calling_code' => preg_replace('/\D/', '', (string) env('POINTAGE_OTP_SMS_DEFAULT_CC', '221')) ?: '221',

    /**
     * Twilio — envoi SMS réel lorsque otp_sms_driver=twilio.
     *
     * Utiliser soit un numéro « From » Twilio, soit un Messaging Service SID (recommandé).
     */
    'otp_sms_twilio_account_sid' => env('TWILIO_ACCOUNT_SID'),
    'otp_sms_twilio_auth_token' => env('TWILIO_AUTH_TOKEN'),
    'otp_sms_twilio_from' => env('TWILIO_SMS_FROM'),
    'otp_sms_twilio_messaging_service_sid' => env('TWILIO_MESSAGING_SERVICE_SID'),

    /** Timeout HTTP envoi SMS (secondes). */
    'otp_sms_http_timeout' => (int) env('POINTAGE_OTP_SMS_HTTP_TIMEOUT', 15),

    /**
     * Vérification SSL des appels HTTPS vers api.twilio.com (Guzzle/cURL).
     * Sous Windows, erreur « cURL error 60: unable to get local issuer certificate » :
     * téléchargez https://curl.se/ca/cacert.pem et indiquez le chemin absolu ici,
     * ou corrigez php.ini (curl.cainfo / openssl.cafile).
     * Développement uniquement : false (désactive la vérification — éviter en production).
     */
    'otp_sms_http_verify' => env('POINTAGE_OTP_SMS_HTTP_VERIFY'),

    /**
     * Majoration (%) appliquée par défaut aux heures pointées un jour férié chômé,
     * si le férié n’a pas de taux explicite (0 = pas de majoration par défaut).
     */
    'ferie_presence_majoration_defaut_pct' => (float) env('POINTAGE_FERIE_PRESENCE_MAJ_PCT', 0),

    /**
     * Codes pays ISO (Nager.date) proposés dans l’UI d’import des jours fériés.
     *
     * @var list<array{code: string, label: string}>
     */
    'nager_pays_disponibles' => [
        ['code' => 'SN', 'label' => 'Sénégal'],
        ['code' => 'FR', 'label' => 'France'],
        ['code' => 'MA', 'label' => 'Maroc'],
        ['code' => 'CI', 'label' => 'Côte d’Ivoire'],
        ['code' => 'ML', 'label' => 'Mali'],
        ['code' => 'BF', 'label' => 'Burkina Faso'],
        ['code' => 'NE', 'label' => 'Niger'],
        ['code' => 'TG', 'label' => 'Togo'],
        ['code' => 'BJ', 'label' => 'Bénin'],
        ['code' => 'CM', 'label' => 'Cameroun'],
        ['code' => 'GA', 'label' => 'Gabon'],
        ['code' => 'TN', 'label' => 'Tunisie'],
        ['code' => 'DZ', 'label' => 'Algérie'],
        ['code' => 'DE', 'label' => 'Allemagne'],
        ['code' => 'BE', 'label' => 'Belgique'],
    ],
];
