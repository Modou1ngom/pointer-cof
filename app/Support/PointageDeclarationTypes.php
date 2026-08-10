<?php

namespace App\Support;

final class PointageDeclarationTypes
{
    public const TYPES = [
        'absence',
        'conge_annuel',
        'conge_maladie',
        'permission_exceptionnelle',
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
        'mission',
        'formation',
        'regularisation',
    ];

    /** Types qui justifient une non-présence (ne pas marquer « absent »). */
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
            'mission',
            'formation',
        ], true);
    }

    public static function requiresHeures(string $type): bool
    {
        return self::normalize($type) === 'permission_exceptionnelle';
    }

    public static function requiresLieu(string $type): bool
    {
        return self::normalize($type) === 'mission';
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
        ];

        if (self::requiresDateRange($type)) {
            $rules['date_fin'] = ['required', 'date', 'after_or_equal:date_concernee'];
        }
        if (self::requiresHeures($type)) {
            $rules['heure_debut'] = ['required', 'date_format:H:i'];
            $rules['heure_fin'] = ['required', 'date_format:H:i', 'after:heure_debut'];
        }
        if (self::requiresLieu($type)) {
            $rules['lieu'] = ['required', 'string', 'max:255'];
        }

        return $rules;
    }
}
