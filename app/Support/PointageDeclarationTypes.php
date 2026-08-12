<?php

namespace App\Support;

final class PointageDeclarationTypes
{
    public const TYPES = [
        'absence',
        'conge_annuel',
        'conge_maladie',
        'permission_exceptionnelle',
        'allaitement',
        'mission',
        'formation',
        'conge', // legacy
        'regularisation',
        'retard', // legacy (= permission exceptionnelle)
    ];

    /** Types proposés à la création (sans legacy). */
    public const TYPES_CREATION = [
        'absence',
        'conge_annuel',
        'conge_maladie',
        'permission_exceptionnelle',
        'allaitement',
        'mission',
        'formation',
        'regularisation',
    ];

    /**
     * Types qui justifient une non-présence (ne pas marquer « absent »).
     * Allaitement exclu : l’employé pointe toujours ; l’horaire est seulement ajusté.
     */
    public const TYPES_JUSTIFICATIFS_PRESENCE = [
        'absence',
        'conge_annuel',
        'conge_maladie',
        'permission_exceptionnelle',
        'mission',
        'formation',
        'conge',
        'regularisation',
        'retard', // legacy
    ];

    /** Types affichés / retirables depuis l’affectation RH (inclut allaitement). */
    public const TYPES_NOTES_RH = [
        'absence',
        'conge_annuel',
        'conge_maladie',
        'permission_exceptionnelle',
        'allaitement',
        'mission',
        'formation',
        'conge',
        'regularisation',
        'retard',
    ];

    public static function validationRule(): string
    {
        return 'required|in:'.implode(',', self::TYPES);
    }

    /**
     * Normalise les anciens types vers le type courant.
     */
    public static function normalize(string $type): string
    {
        return match ($type) {
            'retard' => 'permission_exceptionnelle',
            'conge' => 'conge_annuel',
            default => $type,
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function optionsForApi(): array
    {
        return [
            ['value' => 'absence', 'label' => 'Absence'],
            ['value' => 'conge_annuel', 'label' => 'Congé annuel'],
            ['value' => 'conge_maladie', 'label' => 'Congé maladie'],
            ['value' => 'permission_exceptionnelle', 'label' => 'Permission exceptionnelle'],
            ['value' => 'allaitement', 'label' => 'Allaitement'],
            ['value' => 'mission', 'label' => 'Mission'],
            ['value' => 'formation', 'label' => 'Formation'],
            ['value' => 'regularisation', 'label' => 'Régularisation'],
        ];
    }

    public static function label(string $type): string
    {
        return match ($type) {
            'retard', 'permission_exceptionnelle' => 'Permission exceptionnelle',
            'absence' => 'Absence',
            'conge_annuel', 'conge' => 'Congé annuel',
            'conge_maladie' => 'Congé maladie',
            'allaitement' => 'Allaitement',
            'mission' => 'Mission',
            'formation' => 'Formation',
            'regularisation' => 'Régularisation',
            default => ucfirst($type),
        };
    }

    public static function requiresDateRange(string $type): bool
    {
        $type = self::normalize($type);

        return in_array($type, [
            'absence',
            'conge_annuel',
            'conge_maladie',
            'permission_exceptionnelle',
            'allaitement',
            'mission',
            'formation',
        ], true);
    }

    public static function requiresHeures(string $type): bool
    {
        return self::normalize($type) === 'permission_exceptionnelle';
    }

    public static function requiresAllaitementHoraire(string $type): bool
    {
        return self::normalize($type) === 'allaitement';
    }

    public static function requiresLieu(string $type): bool
    {
        return self::normalize($type) === 'mission';
    }

    /**
     * Sens allaitement dérivé des heures stockées (entrée = heure_debut seule, sortie = heure_fin seule).
     */
    public static function allaitementSens(?string $heureDebut, ?string $heureFin): ?string
    {
        $debut = is_string($heureDebut) && $heureDebut !== '' ? $heureDebut : null;
        $fin = is_string($heureFin) && $heureFin !== '' ? $heureFin : null;

        if ($debut !== null && $fin === null) {
            return 'entree';
        }
        if ($fin !== null && $debut === null) {
            return 'sortie';
        }

        return null;
    }

    public static function allaitementHeure(?string $heureDebut, ?string $heureFin): ?string
    {
        $debut = is_string($heureDebut) && $heureDebut !== '' ? $heureDebut : null;
        $fin = is_string($heureFin) && $heureFin !== '' ? $heureFin : null;

        return $debut ?? $fin;
    }

    /**
     * Mappe sens + heure vers heure_debut / heure_fin pour stockage.
     *
     * @param  array<string, mixed>  $validated
     * @return array{heure_debut: ?string, heure_fin: ?string}
     */
    public static function mapAllaitementHeures(array $validated): array
    {
        $sens = (string) ($validated['sens'] ?? '');
        $heure = $validated['heure'] ?? $validated['heure_debut'] ?? $validated['heure_fin'] ?? null;
        $heure = is_string($heure) && $heure !== '' ? $heure : null;

        if ($sens === 'sortie') {
            return ['heure_debut' => null, 'heure_fin' => $heure];
        }

        // défaut / entree
        return ['heure_debut' => $heure, 'heure_fin' => null];
    }

    /**
     * @return array<string, mixed>
     */
    public static function storeRules(string $type): array
    {
        $type = self::normalize($type);

        $rules = [
            'type' => self::validationRule(),
            'date_concernee' => ['required', 'date'],
            'motif' => ['required', 'string', 'max:512'],
            'commentaire' => ['nullable', 'string', 'max:2000'],
            'justificatif' => ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'],
            'date_fin' => ['nullable', 'date'],
            'heure_debut' => ['nullable', 'date_format:H:i'],
            'heure_fin' => ['nullable', 'date_format:H:i'],
            'lieu' => ['nullable', 'string', 'max:255'],
            'sens' => ['nullable', 'in:entree,sortie'],
            'heure' => ['nullable', 'date_format:H:i'],
        ];

        if (self::requiresDateRange($type)) {
            $rules['date_fin'] = ['required', 'date', 'after_or_equal:date_concernee'];
        }
        if (self::requiresHeures($type)) {
            $rules['heure_debut'] = ['required', 'date_format:H:i'];
            $rules['heure_fin'] = ['required', 'date_format:H:i', 'after:heure_debut'];
        }
        if (self::requiresAllaitementHoraire($type)) {
            $rules['sens'] = ['required', 'in:entree,sortie'];
            // Une seule heure : envoyée via `heure` ou `heure_debut`.
            $rules['heure_debut'] = ['nullable', 'date_format:H:i'];
            $rules['heure_fin'] = ['nullable', 'date_format:H:i'];
            $rules['heure'] = ['nullable', 'date_format:H:i'];
        }
        if (self::requiresLieu($type)) {
            $rules['lieu'] = ['required', 'string', 'max:255'];
        }

        return $rules;
    }

    /**
     * Après validate : impose une heure allaitement (heure ou heure_debut).
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public static function finalizeValidated(string $type, array $validated): array
    {
        $type = self::normalize($type);

        if (! self::requiresAllaitementHoraire($type)) {
            return $validated;
        }

        $heure = $validated['heure'] ?? $validated['heure_debut'] ?? $validated['heure_fin'] ?? null;
        if (! is_string($heure) || $heure === '') {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'heure' => 'L’heure d’entrée ou de sortie est obligatoire pour l’allaitement.',
            ]);
        }

        $mapped = self::mapAllaitementHeures([
            'sens' => $validated['sens'] ?? 'entree',
            'heure' => $heure,
        ]);

        $validated['heure_debut'] = $mapped['heure_debut'];
        $validated['heure_fin'] = $mapped['heure_fin'];

        return $validated;
    }
}
