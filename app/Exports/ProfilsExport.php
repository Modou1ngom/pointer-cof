<?php

namespace App\Exports;

use App\Models\Profil;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

class ProfilsExport implements FromCollection, WithHeadings, WithMapping, WithStyles, ShouldAutoSize
{
    protected $query;

    public function __construct($query = null)
    {
        $this->query = $query ?? Profil::query();
    }

    /**
     * @return \Illuminate\Support\Collection
     */
    public function collection()
    {
        return $this->query->with(['nPlus1', 'nPlus2', 'filiale'])->get();
    }

    /**
     * @return array
     */
    public function headings(): array
    {
        return [
            'Matricule',
            'Nom',
            'Prénom',
            'Email',
            'Téléphone',
            'Fonction',
            'Département',
            'Site',
            'Filiale',
            'Type de contrat',
            'Statut',
            'N+1 (Nom Prénom)',
            'N+1 (Matricule)',
            'N+2 (Nom Prénom)',
            'N+2 (Matricule)',
            'Date de création',
            'Date de modification',
        ];
    }

    /**
     * @param Profil $profil
     * @return array
     */
    public function map($profil): array
    {
        return [
            $profil->matricule,
            $profil->nom,
            $profil->prenom,
            $profil->email ?? '',
            $profil->telephone ?? '',
            $profil->fonction ?? '',
            $profil->departement ?? '',
            $profil->site ?? '',
            $profil->filiale?->nom ?? '',
            $profil->type_contrat ?? '',
            $profil->statut ?? '',
            $profil->nPlus1 ? ($profil->nPlus1->prenom . ' ' . $profil->nPlus1->nom) : '',
            $profil->nPlus1 ? $profil->nPlus1->matricule : '',
            $profil->nPlus2 ? ($profil->nPlus2->prenom . ' ' . $profil->nPlus2->nom) : '',
            $profil->nPlus2 ? $profil->nPlus2->matricule : '',
            $profil->created_at ? $profil->created_at->format('d/m/Y H:i') : '',
            $profil->updated_at ? $profil->updated_at->format('d/m/Y H:i') : '',
        ];
    }

    /**
     * @param Worksheet $sheet
     * @return array
     */
    public function styles(Worksheet $sheet)
    {
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'DC143C']
                ],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
            ],
        ];
    }
}

