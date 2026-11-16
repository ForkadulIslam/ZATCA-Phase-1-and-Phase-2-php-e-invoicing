<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    /** @use HasFactory<\Database\Factories\InvoiceFactory> */
    use HasFactory;

    protected $fillable = [
        'seller_name',
        'vat_number',
        'invoice_number',
        'invoice_type',
        'reference_invoice_number',
        'reason',
        'issue_date',
        'issue_time',
        'total_amount',
        'vat_amount',
        'invoice_hash',
        'qr_code',
        'qr_url',
        'items_data',
        'compliance_results',
        'signed_xml',
    ];

    protected $casts = [
        'items_data' => 'array',
        'compliance_results' => 'array',
        'total_amount' => 'decimal:2',
        'vat_amount' => 'decimal:2',
    ];

    /**
     * Get the original invoice referenced by this credit/debit note.
     */
    public function referenceInvoice()
    {
        return $this->belongsTo(Invoice::class, 'reference_invoice_number', 'invoice_number');
    }

    /**
     * Get all credit notes issued against this invoice.
     */
    public function creditNotes()
    {
        return $this->hasMany(Invoice::class, 'reference_invoice_number', 'invoice_number')
                    ->where('invoice_type', '381');
    }

    /**
     * Get all debit notes issued against this invoice.
     */
    public function debitNotes()
    {
        return $this->hasMany(Invoice::class, 'reference_invoice_number', 'invoice_number')
                    ->where('invoice_type', '383');
    }
}
