<?php

namespace App\Exports;

use App\Models\Automobile;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class AutomobilesExport implements FromCollection, WithHeadings
{
    use Exportable;

    /**
     * @return Collection
     */
    public function collection(): Collection
    {
        return Automobile::all();
    }

    /**
     * @return array
     */
    public function headings(): array
    {
        // Note: dataset columns are model attributes; headings are for readability only
        return [
            'id',
            'url_hash',
            'url',
            'brand_id',
            'name',
            'body_type',
            'segment',
            'infotainment',
            'description',
            'press_release',
            'photos',
            'created_at',
            'updated_at',
        ];
    }
}
