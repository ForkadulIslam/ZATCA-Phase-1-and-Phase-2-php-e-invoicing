<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EgsUnit extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'custom_id',
        'model',
        'crn_number',
        'vat_name',
        'vat_number',
        'branch_name',
        'branch_industry',
        'location',
        'private_key',
        'certificate',
        'secret',
        'request_id',
        'last_invoice_hash',
        'last_icv',
        'production',
        'status',
    ];

    protected $casts = [
        'location' => 'array',
        'production' => 'boolean',
        'last_icv' => 'integer',
    ];

    /**
     * Convert model attributes into the array structure expected by ZATCA\EGS
     */
    public function toEgsConfig(): array
    {
        return [
            'uuid' => $this->uuid,
            'custom_id' => $this->custom_id,
            'model' => $this->model,
            'CRN_number' => $this->crn_number,
            'VAT_name' => $this->vat_name,
            'VAT_number' => $this->vat_number,
            'branch_name' => $this->branch_name,
            'branch_industry' => $this->branch_industry,
            'location' => $this->location ?? [
                'city' => 'Riyadh',
                'city_subdivision' => 'Olaya',
                'street' => 'King Fahd Road',
                'building' => '2481',
                'plot_identification' => '0000',
                'postal_zone' => '12211',
            ],
            'cancelation' => [
                'cancelation_type' => 'INVOICE',
                'canceled_invoice_number' => '',
            ],
        ];
    }
}

