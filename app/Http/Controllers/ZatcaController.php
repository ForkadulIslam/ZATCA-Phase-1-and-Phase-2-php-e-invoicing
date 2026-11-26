<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Invoice;
use App\Models\EgsUnit;
use ZATCA\EGS;
use ZATCA\GenerateQrCode;
use ZATCA\Tags\Seller;
use ZATCA\Tags\TaxNumber;
use ZATCA\Tags\InvoiceDate;
use ZATCA\Tags\InvoiceTotalAmount;
use ZATCA\Tags\InvoiceTaxAmount;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Logo\Logo;
use Endroid\QrCode\Color\Color;
use Endroid\QrCode\Label\Label;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ZatcaController extends Controller
{
    public function index()
    {
        return view('welcome');
    }

    public function getInvoices(Request $request)
    {
        $query = \App\Models\Invoice::with(['creditNotes', 'debitNotes', 'referenceInvoice'])->latest();

        if ($request->filled('type')) {
            $query->where('invoice_type', $request->input('type'));
        }

        if ($request->filled('reference')) {
            $query->where('reference_invoice_number', $request->input('reference'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('invoice_number', 'like', "%{$search}%")
                  ->orWhere('reference_invoice_number', 'like', "%{$search}%")
                  ->orWhere('seller_name', 'like', "%{$search}%");
            });
        }

        return response()->json($query->get());
    }

    public function downloadXml(\App\Models\Invoice $invoice)
    {
        if (!$invoice->signed_xml) {
            return response()->json(['message' => 'XML not available for this invoice.'], 404);
        }

        return response($invoice->signed_xml)
            ->header('Content-Type', 'text/xml')
            ->header('Content-Disposition', 'attachment; filename="' . $invoice->invoice_number . '.xml"');
    }

    public function phase1()
    {
        // Data for QR Code
        $generatedString = GenerateQrCode::fromArray([
            new Seller('Qr'), // seller name
            new TaxNumber('323457892389823'), // seller tax number
            new InvoiceDate('2021-07-12T14:25:09Z'), // invoice date as Zulu ISO8601
            new InvoiceTotalAmount('100.00'), // invoice total amount
            new InvoiceTaxAmount('15.00') // invoice tax amount
        ])->toBase64();

        // Generate QR Code
        $qrCode = QrCode::create($generatedString)
            ->setEncoding(new Encoding('UTF-8'))
            ->setErrorCorrectionLevel(ErrorCorrectionLevel::High)
            ->setSize(300)
            ->setMargin(10)
            ->setForegroundColor(new Color(0, 0, 0))
            ->setBackgroundColor(new Color(255, 255, 255));

        // Save QR Code to file
        $writer = new PngWriter();
        // Assuming logo.png exists in public/assets/logo.png
        $logoPath = public_path('assets/logo.png');
        $logo = null;
        if (file_exists($logoPath)) {
            $logo = Logo::create($logoPath)
                ->setResizeToWidth(50)
                ->setPunchoutBackground(true);
        }

        $label = Label::create('Qr Phase-1')
            ->setTextColor(new Color(255, 0, 0));

        $result = $writer->write($qrCode, $logo, $label);
        $result->saveToFile(public_path('assets/phase-1.png'));

        return response($result->getString())->header('Content-Type', $result->getMimeType());
    }

    public function phase2(Request $request)
    {
        $data = $request->all();


        $egs_unit = [
            'uuid' => $data['seller']['uuid'],
            'custom_id' => $data['seller']['custom_id'],
            'model' => $data['seller']['model'],
            'CRN_number' => $data['seller']['crn'],
            'VAT_name' => $data['seller']['name'],
            'VAT_number' => $data['seller']['vat_number'],
            'location' => [
                'city' => $data['location']['city'],
                'city_subdivision' => $data['location']['city_subdivision'],
                'street' => $data['location']['street'],
                'plot_identification' => $data['location']['plot'] ?? '0000',
                'building' => $data['location']['building'],
                'postal_zone' => $data['location']['postal'],
            ],
            'branch_name' => $data['seller']['name'] . ' Branch',
            'branch_industry' => 'Retail',
            'cancelation' => [
                'cancelation_type' => 'INVOICE',
                'canceled_invoice_number' => '',
            ],
        ];

        $invoice = [
            'invoice_counter_number' => $data['invoice']['counter'],
            'invoice_serial_number' => $data['invoice']['number'],
            'issue_date' => $data['invoice']['date'],
            'issue_time' => $data['invoice']['time'],
            'previous_invoice_hash' => $data['invoice']['previous_hash'],
            'line_items' => array_map(function ($index, $item) {
                return [
                    'id' => (string) ($index + 1),
                    'name' => $item['name'],
                    'quantity' => $item['quantity'],
                    'tax_exclusive_price' => $item['price'],
                    'VAT_percent' => $item['vat_percent'],
                    'other_taxes' => [],
                    'discounts' => [],
                ];
            }, array_keys($data['items']), $data['items']),
        ];

        try {
            $egs = new EGS($egs_unit);
            $egs->production = false;

            // Generate private key & csr
            list($private_key, $csr) = $egs->generateNewKeysAndCSR('Qr');

            // Make an request to issue compliance certificate (Using a dummy OTP for testing)
            list($request_id, $binary_security_token, $secret) = $egs->issueComplianceCertificate('123345', $csr);

            // Sign invoice xml
            list($signed_invoice_string, $invoice_hash, $qr) = $egs->signInvoice($invoice, $egs_unit, $binary_security_token, $private_key);

            // Check compliance
            $invoiceCompliance = $egs->checkInvoiceCompliance($signed_invoice_string, $invoice_hash, $binary_security_token, $secret);

            // Generate QR Code
            $qrCode = QrCode::create($qr)
                ->setEncoding(new Encoding('UTF-8'))
                ->setErrorCorrectionLevel(ErrorCorrectionLevel::High)
                ->setSize(300)
                ->setMargin(10)
                ->setForegroundColor(new Color(0, 0, 0))
                ->setBackgroundColor(new Color(255, 255, 255));

            $writer = new PngWriter();
            $filename = 'phase-2-' . time() . '.png';
            $result = $writer->write($qrCode);
            $result->saveToFile(public_path('assets/' . $filename));

            // Calculate totals for DB
            $total_amount = 0;
            $vat_amount = 0;
            foreach ($data['items'] as $item) {
                $subtotal = $item['quantity'] * $item['price'];
                $total_amount += $subtotal * (1 + $item['vat_percent']);
                $vat_amount += $subtotal * $item['vat_percent'];
            }

            // Save to database
            $db_invoice = \App\Models\Invoice::create([
                'seller_name' => $data['seller']['name'],
                'vat_number' => $data['seller']['vat_number'],
                'invoice_number' => $data['invoice']['number'],
                'issue_date' => $data['invoice']['date'],
                'issue_time' => $data['invoice']['time'],
                'total_amount' => $total_amount,
                'vat_amount' => $vat_amount,
                'invoice_hash' => $invoice_hash,
                'qr_code' => $qr,
                'qr_url' => asset('assets/' . $filename),
                'items_data' => $data['items'],
                'compliance_results' => json_decode($invoiceCompliance, true),
                'signed_xml' => $signed_invoice_string,
            ]);

            return response()->json([
                'success' => true,
                'invoice_hash' => $invoice_hash,
                'qr_url' => asset('assets/' . $filename),
                'compliance' => json_decode($invoiceCompliance),
                'request_id' => $request_id,
                'invoice_id' => $db_invoice->id
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function issueStandardInvoice(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'unit_id'                => 'required|integer|exists:egs_units,id',
                'unit_uuid'              => 'nullable|string',
                'invoice.number'         => 'required|string|unique:invoices,invoice_number',
                'invoice.date'           => 'required|string',
                'invoice.time'           => 'required|string',
                'buyer.name'             => 'required|string',
                'buyer.vat_number'       => ['required', 'string', 'regex:/^3\d{13}3$/'],
                'buyer.street'           => 'required|string',
                'buyer.building'         => 'required|string',
                'buyer.city'             => 'required|string',
                'buyer.postal'           => 'required|string',
                'buyer.city_subdivision' => 'required|string',
                'buyer.plot'             => 'required|string',
                'items'                  => 'required|array|min:1',
                'items.*.name'           => 'required|string',
                'items.*.quantity'       => 'required|numeric|min:0.01',
                'items.*.price'          => 'required|numeric|min:0',
                'items.*.vat_percent'    => 'required|numeric',
            ], [
                'unit_id.required'                   => 'The unit_id field is required.',
                'unit_id.integer'                    => 'The unit_id must be a valid integer.',
                'unit_id.exists'                     => 'The selected unit_id does not exist in the database.',
                'unit_uuid.string'                   => 'The unit_uuid must be a valid string.',
                'invoice.number.required'            => 'The invoice number (invoice.number) is required.',
                'invoice.number.string'              => 'The invoice number must be a valid string.',
                'invoice.number.unique'              => 'The invoice number has already been taken.',
                'invoice.date.required'              => 'The invoice date (invoice.date) is required.',
                'invoice.date.string'                => 'The invoice date must be a valid string (YYYY-MM-DD).',
                'invoice.time.required'              => 'The invoice time (invoice.time) is required.',
                'invoice.time.string'                => 'The invoice time must be a valid string (HH:MM:SS).',
                'buyer.name.required'                => 'The buyer name (buyer.name) is required.',
                'buyer.name.string'                  => 'The buyer name must be a string.',
                'buyer.vat_number.required'          => 'The buyer VAT number (buyer.vat_number) is required.',
                'buyer.vat_number.regex'             => 'The buyer VAT number must be exactly 15 digits starting and ending with 3 (e.g., 3xxxxxxxxxxxx03).',
                'buyer.street.required'              => 'The buyer street (buyer.street) is required.',
                'buyer.street.string'                => 'The buyer street must be a string.',
                'buyer.building.required'            => 'The buyer building number (buyer.building) is required.',
                'buyer.building.string'              => 'The buyer building number must be a string.',
                'buyer.city.required'                => 'The buyer city (buyer.city) is required.',
                'buyer.city.string'                  => 'The buyer city must be a string.',
                'buyer.postal.required'              => 'The buyer postal zone (buyer.postal) is required.',
                'buyer.postal.string'                => 'The buyer postal zone must be a string.',
                'buyer.city_subdivision.required'    => 'The buyer city subdivision (buyer.city_subdivision) is required.',
                'buyer.city_subdivision.string'      => 'The buyer city subdivision must be a string.',
                'buyer.plot.required'                => 'The buyer plot identification (buyer.plot) is required.',
                'buyer.plot.string'                  => 'The buyer plot identification must be a string.',
                'items.required'                     => 'The items field is required and must contain at least one line item.',
                'items.array'                        => 'The items field must be an array of line items.',
                'items.min'                          => 'At least one line item is required in the items array.',
                'items.*.name.required'              => 'The name field is required for each line item.',
                'items.*.name.string'                => 'The name field for each line item must be a string.',
                'items.*.quantity.required'          => 'The quantity field is required for each line item.',
                'items.*.quantity.numeric'           => 'The quantity field for each line item must be a numeric value.',
                'items.*.quantity.min'               => 'The quantity field for each line item must be at least 0.01.',
                'items.*.price.required'             => 'The price field is required for each line item.',
                'items.*.price.numeric'              => 'The price field for each line item must be a numeric value.',
                'items.*.price.min'                  => 'The price field for each line item must be at least 0.',
                'items.*.vat_percent.required'       => 'The vat_percent field is required for each line item.',
                'items.*.vat_percent.numeric'        => 'The vat_percent field must be a numeric value (e.g., 0.15 for 15%).',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error: ' . $validator->errors()->first(),
                    'errors'  => $validator->errors(),
                ], 422);
            }

            // 1. Locate EGS unit
            $unit = EgsUnit::find($request->input('unit_id'));
            if (!$unit) {
                return response()->json([
                    'success' => false,
                    'message' => "EGS unit with ID {$request->input('unit_id')} not found in database.",
                ], 404);
            }

            if ($request->filled('unit_uuid') && $unit->uuid !== $request->input('unit_uuid')) {
                return response()->json([
                    'success' => false,
                    'message' => "Validation error: The provided unit_uuid does not match unit_id {$unit->id}.",
                    'errors'  => [
                        'unit_uuid' => ["The provided unit_uuid does not match unit_id {$unit->id}."]
                    ]
                ], 422);
            }

            if (empty($unit->certificate) || empty($unit->private_key)) {
                return response()->json(['success' => false, 'message' => 'EGS unit has no stored certificate or private key.'], 400);
            }

            // 2. Sequential chaining (ICV & PIH)
            $counter      = $unit->current_counter + 1;
            $previousHash = $unit->last_invoice_hash ?: base64_encode(hash('sha256', '0', true));
            $invoiceNumber = $request->input('invoice.number') ?? ('SINV-' . date('Ymd') . '-' . str_pad($counter, 5, '0', STR_PAD_LEFT));
            $issueDate     = $request->input('invoice.date') ?? date('Y-m-d');
            $issueTime     = $request->input('invoice.time') ?? date('H:i:s');

            // 3. Format line items
            $items     = $request->input('items', []);
            $lineItems = array_map(function ($index, $item) {
                return [
                    'id'                  => (string) ($index + 1),
                    'name'                => $item['name'],
                    'quantity'            => (float) $item['quantity'],
                    'tax_exclusive_price' => (float) $item['price'],
                    'VAT_percent'         => isset($item['vat_percent']) ? (float) $item['vat_percent'] : 0.15,
                    'other_taxes'         => $item['other_taxes'] ?? [],
                    'discounts'           => $item['discounts'] ?? [],
                ];
            }, array_keys($items), $items);

            $invoice = [
                'invoice_counter_number' => (string) $counter,
                'invoice_serial_number'  => $invoiceNumber,
                'issue_date'             => $issueDate,
                'issue_time'             => $issueTime,
                'previous_invoice_hash'  => $previousHash,
                'line_items'             => $lineItems,
            ];

            $buyer = [
                'name'             => $request->input('buyer.name'),
                'vat_number'       => $request->input('buyer.vat_number'),
                'street'           => $request->input('buyer.street'),
                'building'         => $request->input('buyer.building'),
                'city'             => $request->input('buyer.city'),
                'postal'           => $request->input('buyer.postal'),
                'city_subdivision' => $request->input('buyer.city_subdivision', ''),
                'plot'             => $request->input('buyer.plot', '0000'),
            ];

            $egs_unit_data = $unit->toEgsArray();

            $egs = new EGS($egs_unit_data);
            $egs->production = $unit->production;

            // 4. Sign using the standard invoice signer
            list($signed_invoice_string, $invoice_hash, $qr) = $egs->signStandardInvoice(
                $invoice,
                $egs_unit_data,
                $buyer,
                $unit->certificate,
                $unit->private_key
            );

            // 5. Submit to ZATCA
            $complianceResults = null;
            try {
                if ($unit->status === 'onboarded') {
                    // Standard (B2B) → clearance endpoint
                    $apiResponse = $egs->clearInvoice(
                        $signed_invoice_string,
                        $invoice_hash,
                        $unit->certificate,
                        $unit->secret
                    );
                    Log::info($apiResponse);
                } else {
                    // Compliance phase
                    $apiResponse = $egs->checkInvoiceCompliance(
                        $signed_invoice_string,
                        $invoice_hash,
                        $unit->certificate,
                        $unit->secret
                    );
                }
                $complianceResults = json_decode($apiResponse, true);
            } catch (\Exception $ce) {
                Log::info($ce->getMessage());
                $complianceResults = ['warning' => 'ZATCA API note: ' . $ce->getMessage()];
            }

            // 6. QR Code
            $qrCode = QrCode::create($qr)
                ->setEncoding(new Encoding('UTF-8'))
                ->setErrorCorrectionLevel(ErrorCorrectionLevel::High)
                ->setSize(300)
                ->setMargin(10)
                ->setForegroundColor(new Color(0, 0, 0))
                ->setBackgroundColor(new Color(255, 255, 255));

            $writer   = new PngWriter();
            $filename = 'standard-' . time() . '-' . $counter . '.png';
            $result   = $writer->write($qrCode);
            $result->saveToFile(public_path('assets/' . $filename));

            // 7. Calculate totals
            $total_amount = 0;
            $vat_amount   = 0;
            foreach ($items as $item) {
                $vatPercent    = isset($item['vat_percent']) ? (float) $item['vat_percent'] : 0.15;
                $subtotal      = $item['quantity'] * $item['price'];
                $total_amount += $subtotal * (1 + $vatPercent);
                $vat_amount   += $subtotal * $vatPercent;
            }

            // 8. Save to DB
            $db_invoice = Invoice::create([
                'seller_name'        => $unit->vat_name,
                'vat_number'         => $unit->vat_number,
                'invoice_number'     => $invoiceNumber,
                'invoice_type'       => '388',
                'issue_date'         => $issueDate,
                'issue_time'         => $issueTime,
                'total_amount'       => $total_amount,
                'vat_amount'         => $vat_amount,
                'invoice_hash'       => $invoice_hash,
                'qr_code'            => $qr,
                'qr_url'             => asset('assets/' . $filename),
                'items_data'         => $items,
                'compliance_results' => $complianceResults,
                'signed_xml'         => $signed_invoice_string,
            ]);

            // 9. Update chain state
            $unit->current_counter  = $counter;
            $unit->last_invoice_hash = $invoice_hash;
            $unit->save();

            return response()->json([
                'success'               => true,
                'message'               => 'Standard tax invoice issued, signed, and submitted for clearance successfully!',
                'invoice_id'            => $db_invoice->id,
                'invoice_number'        => $invoiceNumber,
                'invoice_counter'       => $counter,
                'previous_invoice_hash' => $previousHash,
                'invoice_hash'          => $invoice_hash,
                'qr_url'                => asset('assets/' . $filename),
                'qr_code'               => $qr,
                'compliance'            => $complianceResults,
                'unit'                  => [
                    'id'     => $unit->id,
                    'uuid'   => $unit->uuid,
                    'status' => $unit->status,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function issueInvoice(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'unit_id'             => 'required|integer|exists:egs_units,id',
                'unit_uuid'           => 'nullable|string',
                'invoice.number'      => 'required|string|unique:invoices,invoice_number',
                'invoice.date'        => 'required|string',
                'invoice.time'        => 'required|string',
                'items'               => 'required|array|min:1',
                'items.*.name'        => 'required|string',
                'items.*.quantity'    => 'required|numeric|min:0.01',
                'items.*.price'       => 'required|numeric|min:0',
                'items.*.vat_percent' => 'required|numeric',
            ], [
                'unit_id.required'            => 'The unit_id field is required.',
                'unit_id.integer'             => 'The unit_id must be a valid integer.',
                'unit_id.exists'              => 'The selected unit_id does not exist in the database.',
                'unit_uuid.string'            => 'The unit_uuid must be a valid string.',
                'invoice.number.required'     => 'The invoice number (invoice.number) is required.',
                'invoice.number.string'       => 'The invoice number must be a valid string.',
                'invoice.number.unique'       => 'The invoice number has already been taken.',
                'invoice.date.required'       => 'The invoice date (invoice.date) is required.',
                'invoice.date.string'         => 'The invoice date must be a valid string (YYYY-MM-DD).',
                'invoice.time.required'       => 'The invoice time (invoice.time) is required.',
                'invoice.time.string'         => 'The invoice time must be a valid string (HH:MM:SS).',
                'items.required'              => 'The items field is required and must contain at least one line item.',
                'items.array'                 => 'The items field must be an array of line items.',
                'items.min'                   => 'At least one line item is required in the items array.',
                'items.*.name.required'       => 'The name field is required for each line item.',
                'items.*.name.string'         => 'The name field for each line item must be a string.',
                'items.*.quantity.required'   => 'The quantity field is required for each line item.',
                'items.*.quantity.numeric'    => 'The quantity field for each line item must be a numeric value.',
                'items.*.quantity.min'        => 'The quantity field for each line item must be at least 0.01.',
                'items.*.price.required'      => 'The price field is required for each line item.',
                'items.*.price.numeric'       => 'The price field for each line item must be a numeric value.',
                'items.*.price.min'           => 'The price field for each line item must be at least 0.',
                'items.*.vat_percent.required'=> 'The vat_percent field is required for each line item.',
                'items.*.vat_percent.numeric' => 'The vat_percent field must be a numeric value (e.g., 0.15 for 15%).',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error: ' . $validator->errors()->first(),
                    'errors'  => $validator->errors(),
                ], 422);
            }

            // 1. Locate the onboarded EGS unit
            $unit = EgsUnit::find($request->input('unit_id'));
            if (!$unit) {
                return response()->json([
                    'success' => false,
                    'message' => "EGS unit with ID {$request->input('unit_id')} not found in database.",
                ], 404);
            }

            if ($request->filled('unit_uuid') && $unit->uuid !== $request->input('unit_uuid')) {
                return response()->json([
                    'success' => false,
                    'message' => "Validation error: The provided unit_uuid does not match unit_id {$unit->id}.",
                    'errors'  => [
                        'unit_uuid' => ["The provided unit_uuid does not match unit_id {$unit->id}."]
                    ]
                ], 422);
            }

            if (empty($unit->certificate) || empty($unit->private_key)) {
                return response()->json([
                    'success' => false,
                    'message' => 'The selected EGS unit does not have a valid stored certificate or private key.'
                ], 400);
            }

            // 2. Automated Sequential Chaining (ICV & PIH)
            $counter = $unit->current_counter + 1;
            $previousHash = $unit->last_invoice_hash ?: base64_encode(hash('sha256', '0', true));
            $invoiceNumber = $request->input('invoice.number') ?? ('INV-' . date('Ymd') . '-' . str_pad($counter, 5, '0', STR_PAD_LEFT));
            $issueDate = $request->input('invoice.date') ?? date('Y-m-d');
            $issueTime = $request->input('invoice.time') ?? date('H:i:s');

            // 3. Format line items
            $items = $request->input('items', []);
            $lineItems = array_map(function ($index, $item) {
                return [
                    'id'                  => (string) ($index + 1),
                    'name'                => $item['name'],
                    'quantity'            => (float) $item['quantity'],
                    'tax_exclusive_price' => (float) $item['price'],
                    'VAT_percent'         => isset($item['vat_percent']) ? (float) $item['vat_percent'] : 0.15,
                    'other_taxes'         => $item['other_taxes'] ?? [],
                    'discounts'           => $item['discounts'] ?? [],
                ];
            }, array_keys($items), $items);

            $invoice = [
                'invoice_counter_number' => (string) $counter,
                'invoice_serial_number'  => $invoiceNumber,
                'issue_date'             => $issueDate,
                'issue_time'             => $issueTime,
                'previous_invoice_hash'  => $previousHash,
                'line_items'             => $lineItems,
            ];

            $egs_unit_data = $unit->toEgsArray();

            $egs = new EGS($egs_unit_data);
            $egs->production = $unit->production;

            // 4. Sign invoice using the STORED certificate and private key
            list($signed_invoice_string, $invoice_hash, $qr) = $egs->signInvoice(
                $invoice,
                $egs_unit_data,
                $unit->certificate,
                $unit->private_key
            );

            // 5. Submit to ZATCA: compliance check or production reporting based on unit status
            $complianceResults = null;
            try {
                if ($unit->status === 'onboarded') {
                    // Production: report invoice via /invoices/reporting/single
                    $apiResponse = $egs->reportInvoice(
                        $signed_invoice_string,
                        $invoice_hash,
                        $unit->certificate,
                        $unit->secret
                    );
                    Log::info($apiResponse);
                } else {
                    // Compliance phase: validate via /compliance/invoices
                    $apiResponse = $egs->checkInvoiceCompliance(
                        $signed_invoice_string,
                        $invoice_hash,
                        $unit->certificate,
                        $unit->secret
                    );
                }
                $complianceResults = json_decode($apiResponse, true);

            } catch (\Exception $ce) {
                Log::info($ce->getMessage());
                $complianceResults = [
                    'warning' => 'ZATCA API note: ' . $ce->getMessage()
                ];
            }

            // 6. Generate and save QR Code
            $qrCode = QrCode::create($qr)
                ->setEncoding(new Encoding('UTF-8'))
                ->setErrorCorrectionLevel(ErrorCorrectionLevel::High)
                ->setSize(300)
                ->setMargin(10)
                ->setForegroundColor(new Color(0, 0, 0))
                ->setBackgroundColor(new Color(255, 255, 255));

            $writer = new PngWriter();
            $filename = 'phase-2-' . time() . '-' . $counter . '.png';
            $result = $writer->write($qrCode);
            $result->saveToFile(public_path('assets/' . $filename));

            // 7. Calculate totals
            $total_amount = 0;
            $vat_amount = 0;
            foreach ($items as $item) {
                $vatPercent = isset($item['vat_percent']) ? (float) $item['vat_percent'] : 0.15;
                $subtotal = $item['quantity'] * $item['price'];
                $total_amount += $subtotal * (1 + $vatPercent);
                $vat_amount += $subtotal * $vatPercent;
            }

            // 8. Save invoice to database
            $db_invoice = Invoice::create([
                'seller_name'        => $unit->vat_name,
                'vat_number'         => $unit->vat_number,
                'invoice_number'     => $invoiceNumber,
                'invoice_type'       => '388',
                'issue_date'         => $issueDate,
                'issue_time'         => $issueTime,
                'total_amount'       => $total_amount,
                'vat_amount'         => $vat_amount,
                'invoice_hash'       => $invoice_hash,
                'qr_code'            => $qr,
                'qr_url'             => asset('assets/' . $filename),
                'items_data'         => $items,
                'compliance_results' => $complianceResults,
                'signed_xml'         => $signed_invoice_string,
            ]);

            // 9. Update EGS unit chain state (ICV & PIH)
            $unit->current_counter = $counter;
            $unit->last_invoice_hash = $invoice_hash;
            $unit->save();

            return response()->json([
                'success'               => true,
                'message'               => 'Invoice issued, signed with stored certificate, and chained successfully!',
                'invoice_id'            => $db_invoice->id,
                'invoice_number'        => $invoiceNumber,
                'invoice_counter'       => $counter,
                'previous_invoice_hash' => $previousHash,
                'invoice_hash'          => $invoice_hash,
                'qr_url'                => asset('assets/' . $filename),
                'qr_code'               => $qr,
                'compliance'            => $complianceResults,
                'unit'                  => [
                    'id'     => $unit->id,
                    'uuid'   => $unit->uuid,
                    'status' => $unit->status,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function issueStandardCreditNote(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'unit_id'                  => 'required|integer|exists:egs_units,id',
                'unit_uuid'                => 'nullable|string',
                'reference_invoice_number' => 'required|string',
                'reason'                   => 'required|string',
                'payment_method_code'      => 'required|string',
                'credit_note.number'       => 'required|string|unique:invoices,invoice_number',
                'credit_note.date'         => 'required|string',
                'credit_note.time'         => 'required|string',
                'buyer.name'               => 'required|string',
                'buyer.vat_number'         => ['required', 'string', 'regex:/^3\d{13}3$/'],
                'buyer.street'             => 'required|string',
                'buyer.building'           => 'required|string',
                'buyer.city'               => 'required|string',
                'buyer.postal'             => 'required|string',
                'buyer.city_subdivision'   => 'required|string',
                'buyer.plot'               => 'required|string',
                'items'                    => 'required|array|min:1',
                'items.*.name'             => 'required|string',
                'items.*.quantity'         => 'required|numeric|min:0.01',
                'items.*.price'            => 'required|numeric|min:0',
                'items.*.vat_percent'      => 'required|numeric',
            ], [
                'unit_id.required'                  => 'The unit_id field is required.',
                'unit_id.integer'                   => 'The unit_id must be a valid integer.',
                'unit_id.exists'                    => 'The selected unit_id does not exist in the database.',
                'unit_uuid.string'                  => 'The unit_uuid must be a valid string.',
                'reference_invoice_number.required' => 'The reference invoice number (reference_invoice_number) is required.',
                'reference_invoice_number.string'   => 'The reference invoice number must be a valid string.',
                'reason.required'                   => 'The cancellation/amendment reason (reason) is required.',
                'reason.string'                     => 'The reason must be a valid string.',
                'payment_method_code.required'      => 'The payment method code (payment_method_code) is required.',
                'payment_method_code.string'        => 'The payment method code must be a valid string (e.g. "10" for cash, "42" for bank account).',
                'credit_note.number.required'       => 'The credit note number (credit_note.number) is required.',
                'credit_note.number.string'         => 'The credit note number must be a valid string.',
                'credit_note.number.unique'         => 'The credit note number has already been taken.',
                'credit_note.date.required'         => 'The credit note date (credit_note.date) is required.',
                'credit_note.date.string'           => 'The credit note date must be a valid string (YYYY-MM-DD).',
                'credit_note.time.required'         => 'The credit note time (credit_note.time) is required.',
                'credit_note.time.string'           => 'The credit note time must be a valid string (HH:MM:SS).',
                'buyer.name.required'               => 'The buyer name (buyer.name) is required.',
                'buyer.name.string'                 => 'The buyer name must be a string.',
                'buyer.vat_number.required'         => 'The buyer VAT number (buyer.vat_number) is required.',
                'buyer.vat_number.regex'            => 'The buyer VAT number must be exactly 15 digits starting and ending with 3 (e.g., 3xxxxxxxxxxxx03).',
                'buyer.street.required'             => 'The buyer street (buyer.street) is required.',
                'buyer.street.string'               => 'The buyer street must be a string.',
                'buyer.building.required'           => 'The buyer building number (buyer.building) is required.',
                'buyer.building.string'             => 'The buyer building number must be a string.',
                'buyer.city.required'               => 'The buyer city (buyer.city) is required.',
                'buyer.city.string'                 => 'The buyer city must be a string.',
                'buyer.postal.required'             => 'The buyer postal zone (buyer.postal) is required.',
                'buyer.postal.string'               => 'The buyer postal zone must be a string.',
                'buyer.city_subdivision.required'   => 'The buyer city subdivision (buyer.city_subdivision) is required.',
                'buyer.city_subdivision.string'     => 'The buyer city subdivision must be a string.',
                'buyer.plot.required'               => 'The buyer plot identification (buyer.plot) is required.',
                'buyer.plot.string'                 => 'The buyer plot identification must be a string.',
                'items.required'                    => 'The items field is required and must contain at least one line item.',
                'items.array'                       => 'The items field must be an array of line items.',
                'items.min'                         => 'At least one line item is required in the items array.',
                'items.*.name.required'             => 'The name field is required for each line item.',
                'items.*.name.string'               => 'The name field for each line item must be a string.',
                'items.*.quantity.required'         => 'The quantity field is required for each line item.',
                'items.*.quantity.numeric'          => 'The quantity field for each line item must be a numeric value.',
                'items.*.quantity.min'              => 'The quantity field for each line item must be at least 0.01.',
                'items.*.price.required'            => 'The price field is required for each line item.',
                'items.*.price.numeric'             => 'The price field for each line item must be a numeric value.',
                'items.*.price.min'                 => 'The price field for each line item must be at least 0.',
                'items.*.vat_percent.required'      => 'The vat_percent field is required for each line item.',
                'items.*.vat_percent.numeric'       => 'The vat_percent field must be a numeric value (e.g., 0.15 for 15%).',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error: ' . $validator->errors()->first(),
                    'errors'  => $validator->errors(),
                ], 422);
            }

            // 1. Locate the onboarded EGS unit
            $unit = EgsUnit::find($request->input('unit_id'));
            if (!$unit) {
                return response()->json([
                    'success' => false,
                    'message' => "EGS unit with ID {$request->input('unit_id')} not found in database.",
                ], 404);
            }

            if ($request->filled('unit_uuid') && $unit->uuid !== $request->input('unit_uuid')) {
                return response()->json([
                    'success' => false,
                    'message' => "Validation error: The provided unit_uuid does not match unit_id {$unit->id}.",
                    'errors'  => [
                        'unit_uuid' => ["The provided unit_uuid does not match unit_id {$unit->id}."]
                    ]
                ], 422);
            }

            if (empty($unit->certificate) || empty($unit->private_key)) {
                return response()->json([
                    'success' => false,
                    'message' => 'The selected EGS unit does not have a valid stored certificate or private key.'
                ], 400);
            }

            // 2. Automated Sequential Chaining (ICV & PIH)
            $counter = $unit->current_counter + 1;
            $previousHash = $unit->last_invoice_hash ?: base64_encode(hash('sha256', '0', true));
            $creditNoteNumber = $request->input('credit_note.number') ?? ('SCN-' . date('Ymd') . '-' . str_pad($counter, 5, '0', STR_PAD_LEFT));
            $issueDate = $request->input('credit_note.date') ?? date('Y-m-d');
            $issueTime = $request->input('credit_note.time') ?? date('H:i:s');
            $referenceInvoiceNumber = $request->input('reference_invoice_number');
            $reason = $request->input('reason');
            $paymentMethodCode = $request->input('payment_method_code', '10');

            // 3. Format line items (must be positive amounts representing credited values)
            $items = $request->input('items', []);
            $lineItems = array_map(function ($index, $item) {
                return [
                    'id'                  => (string) ($index + 1),
                    'name'                => $item['name'],
                    'quantity'            => (float) $item['quantity'],
                    'tax_exclusive_price' => (float) $item['price'],
                    'VAT_percent'         => isset($item['vat_percent']) ? (float) $item['vat_percent'] : 0.15,
                    'other_taxes'         => $item['other_taxes'] ?? [],
                    'discounts'           => $item['discounts'] ?? [],
                ];
            }, array_keys($items), $items);

            $creditNoteData = [
                'invoice_counter_number' => (string) $counter,
                'invoice_serial_number'  => $creditNoteNumber,
                'issue_date'             => $issueDate,
                'issue_time'             => $issueTime,
                'previous_invoice_hash'  => $previousHash,
                'line_items'             => $lineItems,
            ];

            $buyer = [
                'name'             => $request->input('buyer.name'),
                'vat_number'       => $request->input('buyer.vat_number'),
                'street'           => $request->input('buyer.street'),
                'building'         => $request->input('buyer.building'),
                'city'             => $request->input('buyer.city'),
                'postal'           => $request->input('buyer.postal'),
                'city_subdivision' => $request->input('buyer.city_subdivision', ''),
                'plot'             => $request->input('buyer.plot', '0000'),
            ];

            $egs_unit_data = $unit->toEgsArray('CREDIT_NOTE', $referenceInvoiceNumber);

            $egs = new EGS($egs_unit_data);
            $egs->production = $unit->production;

            // 4. Sign standard credit note using the STORED certificate and private key
            list($signed_credit_note_string, $credit_note_hash, $qr) = $egs->signStandardCreditNote(
                $creditNoteData,
                $egs_unit_data,
                $buyer,
                $unit->certificate,
                $unit->private_key,
                $referenceInvoiceNumber,
                $reason,
                $paymentMethodCode
            );

            // 5. Submit to ZATCA: clearance endpoint for standard B2B
            $complianceResults = null;
            try {
                if ($unit->status === 'onboarded') {
                    $apiResponse = $egs->clearInvoice(
                        $signed_credit_note_string,
                        $credit_note_hash,
                        $unit->certificate,
                        $unit->secret
                    );
                    Log::info($apiResponse);
                } else {
                    $apiResponse = $egs->checkInvoiceCompliance(
                        $signed_credit_note_string,
                        $credit_note_hash,
                        $unit->certificate,
                        $unit->secret
                    );
                }
                $complianceResults = json_decode($apiResponse, true);
            } catch (\Exception $ce) {
                Log::info($ce->getMessage());
                $complianceResults = [
                    'warning' => 'ZATCA API note: ' . $ce->getMessage()
                ];
            }

            // 6. Generate and save QR Code
            $qrCode = QrCode::create($qr)
                ->setEncoding(new Encoding('UTF-8'))
                ->setErrorCorrectionLevel(ErrorCorrectionLevel::High)
                ->setSize(300)
                ->setMargin(10)
                ->setForegroundColor(new Color(0, 0, 0))
                ->setBackgroundColor(new Color(255, 255, 255));

            $writer = new PngWriter();
            $filename = 'standard-credit-note-' . time() . '-' . $counter . '.png';
            $result = $writer->write($qrCode);
            $result->saveToFile(public_path('assets/' . $filename));

            // 7. Calculate totals
            $total_amount = 0;
            $vat_amount = 0;
            foreach ($items as $item) {
                $vatPercent = isset($item['vat_percent']) ? (float) $item['vat_percent'] : 0.15;
                $subtotal = $item['quantity'] * $item['price'];
                $total_amount += $subtotal * (1 + $vatPercent);
                $vat_amount += $subtotal * $vatPercent;
            }

            // 8. Save credit note to database
            $db_invoice = Invoice::create([
                'seller_name'              => $unit->vat_name,
                'vat_number'               => $unit->vat_number,
                'invoice_number'           => $creditNoteNumber,
                'invoice_type'             => '381',
                'reference_invoice_number' => $referenceInvoiceNumber,
                'reason'                   => $reason,
                'issue_date'               => $issueDate,
                'issue_time'               => $issueTime,
                'total_amount'             => $total_amount,
                'vat_amount'               => $vat_amount,
                'invoice_hash'             => $credit_note_hash,
                'qr_code'                  => $qr,
                'qr_url'                   => asset('assets/' . $filename),
                'items_data'               => $items,
                'compliance_results'       => $complianceResults,
                'signed_xml'               => $signed_credit_note_string,
            ]);

            // 9. Update EGS unit chain state (ICV & PIH)
            $unit->current_counter = $counter;
            $unit->last_invoice_hash = $credit_note_hash;
            $unit->save();

            return response()->json([
                'success'                  => true,
                'message'                  => 'Standard credit note issued, signed, and submitted for clearance successfully!',
                'credit_note_id'           => $db_invoice->id,
                'credit_note_number'       => $creditNoteNumber,
                'credit_note_counter'      => $counter,
                'reference_invoice_number' => $referenceInvoiceNumber,
                'reason'                   => $reason,
                'previous_invoice_hash'    => $previousHash,
                'credit_note_hash'         => $credit_note_hash,
                'qr_url'                   => asset('assets/' . $filename),
                'qr_code'                  => $qr,
                'compliance'               => $complianceResults,
                'unit'                     => [
                    'id'     => $unit->id,
                    'uuid'   => $unit->uuid,
                    'status' => $unit->status,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function issueCreditNote(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'unit_id'                  => 'required|integer|exists:egs_units,id',
                'unit_uuid'                => 'nullable|string',
                'reference_invoice_number' => 'required|string',
                'reason'                   => 'required|string',
                'payment_method_code'      => 'required|string',
                'credit_note.number'       => 'required|string|unique:invoices,invoice_number',
                'credit_note.date'         => 'required|string',
                'credit_note.time'         => 'required|string',
                'items'                    => 'required|array|min:1',
                'items.*.name'             => 'required|string',
                'items.*.quantity'         => 'required|numeric|min:0.01',
                'items.*.price'            => 'required|numeric|min:0',
                'items.*.vat_percent'      => 'required|numeric',
            ], [
                'unit_id.required'                  => 'The unit_id field is required.',
                'unit_id.integer'                   => 'The unit_id must be a valid integer.',
                'unit_id.exists'                    => 'The selected unit_id does not exist in the database.',
                'unit_uuid.string'                  => 'The unit_uuid must be a valid string.',
                'reference_invoice_number.required' => 'The reference invoice number (reference_invoice_number) is required.',
                'reference_invoice_number.string'   => 'The reference invoice number must be a valid string.',
                'reason.required'                   => 'The cancellation/amendment reason (reason) is required.',
                'reason.string'                     => 'The reason must be a valid string.',
                'payment_method_code.required'      => 'The payment method code (payment_method_code) is required.',
                'payment_method_code.string'        => 'The payment method code must be a valid string (e.g. "10" for cash, "42" for bank account).',
                'credit_note.number.required'       => 'The credit note number (credit_note.number) is required.',
                'credit_note.number.string'         => 'The credit note number must be a valid string.',
                'credit_note.number.unique'         => 'The credit note number has already been taken.',
                'credit_note.date.required'         => 'The credit note date (credit_note.date) is required.',
                'credit_note.date.string'           => 'The credit note date must be a valid string (YYYY-MM-DD).',
                'credit_note.time.required'         => 'The credit note time (credit_note.time) is required.',
                'credit_note.time.string'           => 'The credit note time must be a valid string (HH:MM:SS).',
                'items.required'                    => 'The items field is required and must contain at least one line item.',
                'items.array'                       => 'The items field must be an array of line items.',
                'items.min'                         => 'At least one line item is required in the items array.',
                'items.*.name.required'             => 'The name field is required for each line item.',
                'items.*.name.string'               => 'The name field for each line item must be a string.',
                'items.*.quantity.required'         => 'The quantity field is required for each line item.',
                'items.*.quantity.numeric'          => 'The quantity field for each line item must be a numeric value.',
                'items.*.quantity.min'              => 'The quantity field for each line item must be at least 0.01.',
                'items.*.price.required'            => 'The price field is required for each line item.',
                'items.*.price.numeric'             => 'The price field for each line item must be a numeric value.',
                'items.*.price.min'                 => 'The price field for each line item must be at least 0.',
                'items.*.vat_percent.required'      => 'The vat_percent field is required for each line item.',
                'items.*.vat_percent.numeric'       => 'The vat_percent field must be a numeric value (e.g., 0.15 for 15%).',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error: ' . $validator->errors()->first(),
                    'errors'  => $validator->errors(),
                ], 422);
            }

            // 1. Locate the onboarded EGS unit
            $unit = EgsUnit::find($request->input('unit_id'));
            if (!$unit) {
                return response()->json([
                    'success' => false,
                    'message' => "EGS unit with ID {$request->input('unit_id')} not found in database.",
                ], 404);
            }

            if ($request->filled('unit_uuid') && $unit->uuid !== $request->input('unit_uuid')) {
                return response()->json([
                    'success' => false,
                    'message' => "Validation error: The provided unit_uuid does not match unit_id {$unit->id}.",
                    'errors'  => [
                        'unit_uuid' => ["The provided unit_uuid does not match unit_id {$unit->id}."]
                    ]
                ], 422);
            }

            if (empty($unit->certificate) || empty($unit->private_key)) {
                return response()->json([
                    'success' => false,
                    'message' => 'The selected EGS unit does not have a valid stored certificate or private key.'
                ], 400);
            }

        // 2. Automated Sequential Chaining (ICV & PIH)
        $counter = $unit->current_counter + 1;
        $previousHash = $unit->last_invoice_hash ?: base64_encode(hash('sha256', '0', true));
        $creditNoteNumber = $request->input('credit_note.number') ?? ('CN-' . date('Ymd') . '-' . str_pad($counter, 5, '0', STR_PAD_LEFT));
        $issueDate = $request->input('credit_note.date') ?? date('Y-m-d');
        $issueTime = $request->input('credit_note.time') ?? date('H:i:s');
        $referenceInvoiceNumber = $request->input('reference_invoice_number');
        $reason = $request->input('reason');
        $paymentMethodCode = $request->input('payment_method_code', '10');

        // 3. Format line items (must be positive amounts representing credited values)
        $items = $request->input('items', []);
        $lineItems = array_map(function ($index, $item) {
            return [
                'id' => (string) ($index + 1),
                'name' => $item['name'],
                'quantity' => (float) $item['quantity'],
                'tax_exclusive_price' => (float) $item['price'],
                'VAT_percent' => isset($item['vat_percent']) ? (float) $item['vat_percent'] : 0.15,
                'other_taxes' => $item['other_taxes'] ?? [],
                'discounts' => $item['discounts'] ?? [],
            ];
        }, array_keys($items), $items);

        $creditNoteData = [
            'invoice_counter_number' => (string) $counter,
            'invoice_serial_number' => $creditNoteNumber,
            'issue_date' => $issueDate,
            'issue_time' => $issueTime,
            'previous_invoice_hash' => $previousHash,
            'line_items' => $lineItems,
        ];

        $egs_unit_data = $unit->toEgsArray('CREDIT_NOTE', $referenceInvoiceNumber);

        $egs = new EGS($egs_unit_data);
        $egs->production = $unit->production;

            // 4. Sign credit note using the STORED certificate and private key
            list($signed_credit_note_string, $credit_note_hash, $qr) = $egs->signCreditNote(
                $creditNoteData,
                $egs_unit_data,
                $unit->certificate,
                $unit->private_key,
                $referenceInvoiceNumber,
                $reason,
                $paymentMethodCode
            );

            // 5. Submit to ZATCA: compliance check or production reporting based on unit status
            $complianceResults = null;
            try {
                if ($unit->status === 'onboarded') {
                    $apiResponse = $egs->reportInvoice(
                        $signed_credit_note_string,
                        $credit_note_hash,
                        $unit->certificate,
                        $unit->secret
                    );
                } else {
                    $apiResponse = $egs->checkInvoiceCompliance(
                        $signed_credit_note_string,
                        $credit_note_hash,
                        $unit->certificate,
                        $unit->secret
                    );
                }
                $complianceResults = json_decode($apiResponse, true);
            } catch (\Exception $ce) {
                $complianceResults = [
                    'warning' => 'ZATCA API note: ' . $ce->getMessage()
                ];
            }
            // 6. Generate and save QR Code
            $qrCode = QrCode::create($qr)
                ->setEncoding(new Encoding('UTF-8'))
                ->setErrorCorrectionLevel(ErrorCorrectionLevel::High)
                ->setSize(300)
                ->setMargin(10)
                ->setForegroundColor(new Color(0, 0, 0))
                ->setBackgroundColor(new Color(255, 255, 255));

            $writer = new PngWriter();
            $filename = 'credit-note-' . time() . '-' . $counter . '.png';
            $result = $writer->write($qrCode);
            $result->saveToFile(public_path('assets/' . $filename));

            // 7. Calculate totals
            $total_amount = 0;
            $vat_amount = 0;
            foreach ($items as $item) {
                $vatPercent = isset($item['vat_percent']) ? (float) $item['vat_percent'] : 0.15;
                $subtotal = $item['quantity'] * $item['price'];
                $total_amount += $subtotal * (1 + $vatPercent);
                $vat_amount += $subtotal * $vatPercent;
            }

            // 8. Save credit note to database
            $db_invoice = Invoice::create([
                'seller_name' => $unit->vat_name,
                'vat_number' => $unit->vat_number,
                'invoice_number' => $creditNoteNumber,
                'invoice_type' => '381',
                'reference_invoice_number' => $referenceInvoiceNumber,
                'reason' => $reason,
                'issue_date' => $issueDate,
                'issue_time' => $issueTime,
                'total_amount' => $total_amount,
                'vat_amount' => $vat_amount,
                'invoice_hash' => $credit_note_hash,
                'qr_code' => $qr,
                'qr_url' => asset('assets/' . $filename),
                'items_data' => $items,
                'compliance_results' => $complianceResults,
                'signed_xml' => $signed_credit_note_string,
            ]);

            // 9. Update EGS unit chain state (ICV & PIH)
            $unit->current_counter = $counter;
            $unit->last_invoice_hash = $credit_note_hash;
            $unit->save();

            return response()->json([
                'success' => true,
                'message' => 'Credit note issued, signed with stored certificate, and chained successfully!',
                'credit_note_id' => $db_invoice->id,
                'credit_note_number' => $creditNoteNumber,
                'credit_note_counter' => $counter,
                'reference_invoice_number' => $referenceInvoiceNumber,
                'reason' => $reason,
                'previous_invoice_hash' => $previousHash,
                'credit_note_hash' => $credit_note_hash,
                'qr_url' => asset('assets/' . $filename),
                'qr_code' => $qr,
                'compliance' => $complianceResults,
                'unit' => [
                    'id' => $unit->id,
                    'uuid' => $unit->uuid,
                    'status' => $unit->status,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function issueStandardDebitNote(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'unit_id'                  => 'required|integer|exists:egs_units,id',
                'unit_uuid'                => 'nullable|string',
                'reference_invoice_number' => 'required|string',
                'reason'                   => 'required|string',
                'payment_method_code'      => 'required|string',
                'debit_note.number'        => 'required|string|unique:invoices,invoice_number',
                'debit_note.date'          => 'required|string',
                'debit_note.time'          => 'required|string',
                'buyer.name'               => 'required|string',
                'buyer.vat_number'         => ['required', 'string', 'regex:/^3\d{13}3$/'],
                'buyer.street'             => 'required|string',
                'buyer.building'           => 'required|string',
                'buyer.city'               => 'required|string',
                'buyer.postal'             => 'required|string',
                'buyer.city_subdivision'   => 'required|string',
                'buyer.plot'               => 'required|string',
                'items'                    => 'required|array|min:1',
                'items.*.name'             => 'required|string',
                'items.*.quantity'         => 'required|numeric|min:0.01',
                'items.*.price'            => 'required|numeric|min:0',
                'items.*.vat_percent'      => 'required|numeric',
            ], [
                'unit_id.required'                  => 'The unit_id field is required.',
                'unit_id.integer'                   => 'The unit_id must be a valid integer.',
                'unit_id.exists'                    => 'The selected unit_id does not exist in the database.',
                'unit_uuid.string'                  => 'The unit_uuid must be a valid string.',
                'reference_invoice_number.required' => 'The reference invoice number (reference_invoice_number) is required.',
                'reference_invoice_number.string'   => 'The reference invoice number must be a valid string.',
                'reason.required'                   => 'The cancellation/amendment reason (reason) is required.',
                'reason.string'                     => 'The reason must be a valid string.',
                'payment_method_code.required'      => 'The payment method code (payment_method_code) is required.',
                'payment_method_code.string'        => 'The payment method code must be a valid string (e.g. "10" for cash, "42" for bank account).',
                'debit_note.number.required'        => 'The debit note number (debit_note.number) is required.',
                'debit_note.number.string'          => 'The debit note number must be a valid string.',
                'debit_note.number.unique'          => 'The debit note number has already been taken.',
                'debit_note.date.required'          => 'The debit note date (debit_note.date) is required.',
                'debit_note.date.string'            => 'The debit note date must be a valid string (YYYY-MM-DD).',
                'debit_note.time.required'          => 'The debit note time (debit_note.time) is required.',
                'debit_note.time.string'            => 'The debit note time must be a valid string (HH:MM:SS).',
                'buyer.name.required'               => 'The buyer name (buyer.name) is required.',
                'buyer.name.string'                 => 'The buyer name must be a string.',
                'buyer.vat_number.required'         => 'The buyer VAT number (buyer.vat_number) is required.',
                'buyer.vat_number.regex'            => 'The buyer VAT number must be exactly 15 digits starting and ending with 3 (e.g., 3xxxxxxxxxxxx03).',
                'buyer.street.required'             => 'The buyer street (buyer.street) is required.',
                'buyer.street.string'               => 'The buyer street must be a string.',
                'buyer.building.required'           => 'The buyer building number (buyer.building) is required.',
                'buyer.building.string'             => 'The buyer building number must be a string.',
                'buyer.city.required'               => 'The buyer city (buyer.city) is required.',
                'buyer.city.string'                 => 'The buyer city must be a string.',
                'buyer.postal.required'             => 'The buyer postal zone (buyer.postal) is required.',
                'buyer.postal.string'               => 'The buyer postal zone must be a string.',
                'buyer.city_subdivision.required'   => 'The buyer city subdivision (buyer.city_subdivision) is required.',
                'buyer.city_subdivision.string'     => 'The buyer city subdivision must be a string.',
                'buyer.plot.required'               => 'The buyer plot identification (buyer.plot) is required.',
                'buyer.plot.string'                 => 'The buyer plot identification must be a string.',
                'items.required'                    => 'The items field is required and must contain at least one line item.',
                'items.array'                       => 'The items field must be an array of line items.',
                'items.min'                         => 'At least one line item is required in the items array.',
                'items.*.name.required'             => 'The name field is required for each line item.',
                'items.*.name.string'               => 'The name field for each line item must be a string.',
                'items.*.quantity.required'         => 'The quantity field is required for each line item.',
                'items.*.quantity.numeric'          => 'The quantity field for each line item must be a numeric value.',
                'items.*.quantity.min'              => 'The quantity field for each line item must be at least 0.01.',
                'items.*.price.required'            => 'The price field is required for each line item.',
                'items.*.price.numeric'             => 'The price field for each line item must be a numeric value.',
                'items.*.price.min'                 => 'The price field for each line item must be at least 0.',
                'items.*.vat_percent.required'      => 'The vat_percent field is required for each line item.',
                'items.*.vat_percent.numeric'       => 'The vat_percent field must be a numeric value (e.g., 0.15 for 15%).',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error: ' . $validator->errors()->first(),
                    'errors'  => $validator->errors(),
                ], 422);
            }

            // 1. Locate the onboarded EGS unit
            $unit = EgsUnit::find($request->input('unit_id'));
            if (!$unit) {
                return response()->json([
                    'success' => false,
                    'message' => "EGS unit with ID {$request->input('unit_id')} not found in database.",
                ], 404);
            }

            if ($request->filled('unit_uuid') && $unit->uuid !== $request->input('unit_uuid')) {
                return response()->json([
                    'success' => false,
                    'message' => "Validation error: The provided unit_uuid does not match unit_id {$unit->id}.",
                    'errors'  => [
                        'unit_uuid' => ["The provided unit_uuid does not match unit_id {$unit->id}."]
                    ]
                ], 422);
            }

            if (empty($unit->certificate) || empty($unit->private_key)) {
                return response()->json([
                    'success' => false,
                    'message' => 'The selected EGS unit does not have a valid stored certificate or private key.'
                ], 400);
            }

            // 2. Automated Sequential Chaining (ICV & PIH)
            $counter = $unit->current_counter + 1;
            $previousHash = $unit->last_invoice_hash ?: base64_encode(hash('sha256', '0', true));
            $debitNoteNumber = $request->input('debit_note.number') ?? ('SDN-' . date('Ymd') . '-' . str_pad($counter, 5, '0', STR_PAD_LEFT));
            $issueDate = $request->input('debit_note.date') ?? date('Y-m-d');
            $issueTime = $request->input('debit_note.time') ?? date('H:i:s');
            $referenceInvoiceNumber = $request->input('reference_invoice_number');
            $reason = $request->input('reason');
            $paymentMethodCode = $request->input('payment_method_code', '10');

            // 3. Format line items (must be positive amounts representing debited/additional charges)
            $items = $request->input('items', []);
            $lineItems = array_map(function ($index, $item) {
                return [
                    'id'                  => (string) ($index + 1),
                    'name'                => $item['name'],
                    'quantity'            => (float) $item['quantity'],
                    'tax_exclusive_price' => (float) $item['price'],
                    'VAT_percent'         => isset($item['vat_percent']) ? (float) $item['vat_percent'] : 0.15,
                    'other_taxes'         => $item['other_taxes'] ?? [],
                    'discounts'           => $item['discounts'] ?? [],
                ];
            }, array_keys($items), $items);

            $debitNoteData = [
                'invoice_counter_number' => (string) $counter,
                'invoice_serial_number'  => $debitNoteNumber,
                'issue_date'             => $issueDate,
                'issue_time'             => $issueTime,
                'previous_invoice_hash'  => $previousHash,
                'line_items'             => $lineItems,
            ];

            $buyer = [
                'name'             => $request->input('buyer.name'),
                'vat_number'       => $request->input('buyer.vat_number'),
                'street'           => $request->input('buyer.street'),
                'building'         => $request->input('buyer.building'),
                'city'             => $request->input('buyer.city'),
                'postal'           => $request->input('buyer.postal'),
                'city_subdivision' => $request->input('buyer.city_subdivision', ''),
                'plot'             => $request->input('buyer.plot', '0000'),
            ];

            $egs_unit_data = $unit->toEgsArray('DEBIT_NOTE', $referenceInvoiceNumber);

            $egs = new EGS($egs_unit_data);
            $egs->production = $unit->production;

            // 4. Sign standard debit note using the STORED certificate and private key
            list($signed_debit_note_string, $debit_note_hash, $qr) = $egs->signStandardDebitNote(
                $debitNoteData,
                $egs_unit_data,
                $buyer,
                $unit->certificate,
                $unit->private_key,
                $referenceInvoiceNumber,
                $reason,
                $paymentMethodCode
            );

            // 5. Submit to ZATCA: clearance endpoint for standard B2B
            $complianceResults = null;
            try {
                if ($unit->status === 'onboarded') {
                    $apiResponse = $egs->clearInvoice(
                        $signed_debit_note_string,
                        $debit_note_hash,
                        $unit->certificate,
                        $unit->secret
                    );
                    Log::info($apiResponse);
                } else {
                    $apiResponse = $egs->checkInvoiceCompliance(
                        $signed_debit_note_string,
                        $debit_note_hash,
                        $unit->certificate,
                        $unit->secret
                    );
                }
                $complianceResults = json_decode($apiResponse, true);
            } catch (\Exception $ce) {
                Log::info($ce->getMessage());
                $complianceResults = [
                    'warning' => 'ZATCA API note: ' . $ce->getMessage()
                ];
            }

            // 6. Generate and save QR Code
            $qrCode = QrCode::create($qr)
                ->setEncoding(new Encoding('UTF-8'))
                ->setErrorCorrectionLevel(ErrorCorrectionLevel::High)
                ->setSize(300)
                ->setMargin(10)
                ->setForegroundColor(new Color(0, 0, 0))
                ->setBackgroundColor(new Color(255, 255, 255));

            $writer = new PngWriter();
            $filename = 'standard-debit-note-' . time() . '-' . $counter . '.png';
            $result = $writer->write($qrCode);
            $result->saveToFile(public_path('assets/' . $filename));

            // 7. Calculate totals
            $total_amount = 0;
            $vat_amount = 0;
            foreach ($items as $item) {
                $vatPercent = isset($item['vat_percent']) ? (float) $item['vat_percent'] : 0.15;
                $subtotal = $item['quantity'] * $item['price'];
                $total_amount += $subtotal * (1 + $vatPercent);
                $vat_amount += $subtotal * $vatPercent;
            }

            // 8. Save debit note to database
            $db_invoice = Invoice::create([
                'seller_name'              => $unit->vat_name,
                'vat_number'               => $unit->vat_number,
                'invoice_number'           => $debitNoteNumber,
                'invoice_type'             => '383',
                'reference_invoice_number' => $referenceInvoiceNumber,
                'reason'                   => $reason,
                'issue_date'               => $issueDate,
                'issue_time'               => $issueTime,
                'total_amount'             => $total_amount,
                'vat_amount'               => $vat_amount,
                'invoice_hash'             => $debit_note_hash,
                'qr_code'                  => $qr,
                'qr_url'                   => asset('assets/' . $filename),
                'items_data'               => $items,
                'compliance_results'       => $complianceResults,
                'signed_xml'               => $signed_debit_note_string,
            ]);

            // 9. Update EGS unit chain state (ICV & PIH)
            $unit->current_counter = $counter;
            $unit->last_invoice_hash = $debit_note_hash;
            $unit->save();

            return response()->json([
                'success'                  => true,
                'message'                  => 'Standard debit note issued, signed, and submitted for clearance successfully!',
                'debit_note_id'            => $db_invoice->id,
                'debit_note_number'        => $debitNoteNumber,
                'debit_note_counter'       => $counter,
                'reference_invoice_number' => $referenceInvoiceNumber,
                'reason'                   => $reason,
                'previous_invoice_hash'    => $previousHash,
                'debit_note_hash'          => $debit_note_hash,
                'qr_url'                   => asset('assets/' . $filename),
                'qr_code'                  => $qr,
                'compliance'               => $complianceResults,
                'unit'                     => [
                    'id'     => $unit->id,
                    'uuid'   => $unit->uuid,
                    'status' => $unit->status,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function issueDebitNote(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'unit_id'                  => 'required|integer|exists:egs_units,id',
                'unit_uuid'                => 'nullable|string',
                'reference_invoice_number' => 'required|string',
                'reason'                   => 'required|string',
                'payment_method_code'      => 'required|string',
                'debit_note.number'        => 'required|string|unique:invoices,invoice_number',
                'debit_note.date'          => 'required|string',
                'debit_note.time'          => 'required|string',
                'items'                    => 'required|array|min:1',
                'items.*.name'             => 'required|string',
                'items.*.quantity'         => 'required|numeric|min:0.01',
                'items.*.price'            => 'required|numeric|min:0',
                'items.*.vat_percent'      => 'required|numeric',
            ], [
                'unit_id.required'                  => 'The unit_id field is required.',
                'unit_id.integer'                   => 'The unit_id must be a valid integer.',
                'unit_id.exists'                    => 'The selected unit_id does not exist in the database.',
                'unit_uuid.string'                  => 'The unit_uuid must be a valid string.',
                'reference_invoice_number.required' => 'The reference invoice number (reference_invoice_number) is required.',
                'reference_invoice_number.string'   => 'The reference invoice number must be a valid string.',
                'reason.required'                   => 'The cancellation/amendment reason (reason) is required.',
                'reason.string'                     => 'The reason must be a valid string.',
                'payment_method_code.required'      => 'The payment method code (payment_method_code) is required.',
                'payment_method_code.string'        => 'The payment method code must be a valid string (e.g. "10" for cash, "42" for bank account).',
                'debit_note.number.required'        => 'The debit note number (debit_note.number) is required.',
                'debit_note.number.string'          => 'The debit note number must be a valid string.',
                'debit_note.number.unique'          => 'The debit note number has already been taken.',
                'debit_note.date.required'          => 'The debit note date (debit_note.date) is required.',
                'debit_note.date.string'            => 'The debit note date must be a valid string (YYYY-MM-DD).',
                'debit_note.time.required'          => 'The debit note time (debit_note.time) is required.',
                'debit_note.time.string'            => 'The debit note time must be a valid string (HH:MM:SS).',
                'items.required'                    => 'The items field is required and must contain at least one line item.',
                'items.array'                       => 'The items field must be an array of line items.',
                'items.min'                         => 'At least one line item is required in the items array.',
                'items.*.name.required'             => 'The name field is required for each line item.',
                'items.*.name.string'               => 'The name field for each line item must be a string.',
                'items.*.quantity.required'         => 'The quantity field is required for each line item.',
                'items.*.quantity.numeric'          => 'The quantity field for each line item must be a numeric value.',
                'items.*.quantity.min'              => 'The quantity field for each line item must be at least 0.01.',
                'items.*.price.required'            => 'The price field is required for each line item.',
                'items.*.price.numeric'             => 'The price field for each line item must be a numeric value.',
                'items.*.price.min'                 => 'The price field for each line item must be at least 0.',
                'items.*.vat_percent.required'      => 'The vat_percent field is required for each line item.',
                'items.*.vat_percent.numeric'       => 'The vat_percent field must be a numeric value (e.g., 0.15 for 15%).',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error: ' . $validator->errors()->first(),
                    'errors'  => $validator->errors(),
                ], 422);
            }

            // 1. Locate the onboarded EGS unit
            $unit = EgsUnit::find($request->input('unit_id'));
            if (!$unit) {
                return response()->json([
                    'success' => false,
                    'message' => "EGS unit with ID {$request->input('unit_id')} not found in database.",
                ], 404);
            }

            if ($request->filled('unit_uuid') && $unit->uuid !== $request->input('unit_uuid')) {
                return response()->json([
                    'success' => false,
                    'message' => "Validation error: The provided unit_uuid does not match unit_id {$unit->id}.",
                    'errors'  => [
                        'unit_uuid' => ["The provided unit_uuid does not match unit_id {$unit->id}."]
                    ]
                ], 422);
            }

            if (empty($unit->certificate) || empty($unit->private_key)) {
                return response()->json([
                    'success' => false,
                    'message' => 'The selected EGS unit does not have a valid stored certificate or private key.'
                ], 400);
            }

            // 2. Automated Sequential Chaining (ICV & PIH)
            $counter = $unit->current_counter + 1;
            $previousHash = $unit->last_invoice_hash ?: base64_encode(hash('sha256', '0', true));
            $debitNoteNumber = $request->input('debit_note.number') ?? ('DN-' . date('Ymd') . '-' . str_pad($counter, 5, '0', STR_PAD_LEFT));
            $issueDate = $request->input('debit_note.date') ?? date('Y-m-d');
            $issueTime = $request->input('debit_note.time') ?? date('H:i:s');
            $referenceInvoiceNumber = $request->input('reference_invoice_number');
            $reason = $request->input('reason');
            $paymentMethodCode = $request->input('payment_method_code', '10');

            // 3. Format line items (must be positive amounts representing debited/additional charges)
            $items = $request->input('items', []);
            $lineItems = array_map(function ($index, $item) {
                return [
                    'id' => (string) ($index + 1),
                    'name' => $item['name'],
                    'quantity' => (float) $item['quantity'],
                    'tax_exclusive_price' => (float) $item['price'],
                    'VAT_percent' => isset($item['vat_percent']) ? (float) $item['vat_percent'] : 0.15,
                    'other_taxes' => $item['other_taxes'] ?? [],
                    'discounts' => $item['discounts'] ?? [],
                ];
            }, array_keys($items), $items);

            $debitNoteData = [
                'invoice_counter_number' => (string) $counter,
                'invoice_serial_number' => $debitNoteNumber,
                'issue_date' => $issueDate,
                'issue_time' => $issueTime,
                'previous_invoice_hash' => $previousHash,
                'line_items' => $lineItems,
            ];

            $egs_unit_data = $unit->toEgsArray('DEBIT_NOTE', $referenceInvoiceNumber);

            $egs = new EGS($egs_unit_data);
            $egs->production = $unit->production;

            // 4. Sign debit note using the STORED certificate and private key
            list($signed_debit_note_string, $debit_note_hash, $qr) = $egs->signDebitNote(
                $debitNoteData,
                $egs_unit_data,
                $unit->certificate,
                $unit->private_key,
                $referenceInvoiceNumber,
                $reason,
                $paymentMethodCode
            );

            // 5. Submit to ZATCA: compliance check or production reporting based on unit status
            $complianceResults = null;
            try {
                if ($unit->status === 'onboarded') {
                    $apiResponse = $egs->reportInvoice(
                        $signed_debit_note_string,
                        $debit_note_hash,
                        $unit->certificate,
                        $unit->secret
                    );
                } else {
                    $apiResponse = $egs->checkInvoiceCompliance(
                        $signed_debit_note_string,
                        $debit_note_hash,
                        $unit->certificate,
                        $unit->secret
                    );
                }
                $complianceResults = json_decode($apiResponse, true);
            } catch (\Exception $ce) {
                $complianceResults = [
                    'warning' => 'ZATCA API note: ' . $ce->getMessage()
                ];
            }

            // 6. Generate and save QR Code
            $qrCode = QrCode::create($qr)
                ->setEncoding(new Encoding('UTF-8'))
                ->setErrorCorrectionLevel(ErrorCorrectionLevel::High)
                ->setSize(300)
                ->setMargin(10)
                ->setForegroundColor(new Color(0, 0, 0))
                ->setBackgroundColor(new Color(255, 255, 255));

            $writer = new PngWriter();
            $filename = 'debit-note-' . time() . '-' . $counter . '.png';
            $result = $writer->write($qrCode);
            $result->saveToFile(public_path('assets/' . $filename));

            // 7. Calculate totals
            $total_amount = 0;
            $vat_amount = 0;
            foreach ($items as $item) {
                $vatPercent = isset($item['vat_percent']) ? (float) $item['vat_percent'] : 0.15;
                $subtotal = $item['quantity'] * $item['price'];
                $total_amount += $subtotal * (1 + $vatPercent);
                $vat_amount += $subtotal * $vatPercent;
            }

            // 8. Save debit note to database
            $db_invoice = Invoice::create([
                'seller_name' => $unit->vat_name,
                'vat_number' => $unit->vat_number,
                'invoice_number' => $debitNoteNumber,
                'invoice_type' => '383',
                'reference_invoice_number' => $referenceInvoiceNumber,
                'reason' => $reason,
                'issue_date' => $issueDate,
                'issue_time' => $issueTime,
                'total_amount' => $total_amount,
                'vat_amount' => $vat_amount,
                'invoice_hash' => $debit_note_hash,
                'qr_code' => $qr,
                'qr_url' => asset('assets/' . $filename),
                'items_data' => $items,
                'compliance_results' => $complianceResults,
                'signed_xml' => $signed_debit_note_string,
            ]);

            // 9. Update EGS unit chain state (ICV & PIH)
            $unit->current_counter = $counter;
            $unit->last_invoice_hash = $debit_note_hash;
            $unit->save();

            return response()->json([
                'success' => true,
                'message' => 'Debit note issued, signed with stored certificate, and chained successfully!',
                'debit_note_id' => $db_invoice->id,
                'debit_note_number' => $debitNoteNumber,
                'debit_note_counter' => $counter,
                'reference_invoice_number' => $referenceInvoiceNumber,
                'reason' => $reason,
                'previous_invoice_hash' => $previousHash,
                'debit_note_hash' => $debit_note_hash,
                'qr_url' => asset('assets/' . $filename),
                'qr_code' => $qr,
                'compliance' => $complianceResults,
                'unit' => [
                    'id' => $unit->id,
                    'uuid' => $unit->uuid,
                    'status' => $unit->status,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function requestPcsid(Request $request)
    {
        try {
            $validated = $request->validate([
                'unit_id'   => 'nullable|integer',
                'unit_uuid' => 'nullable|string',
            ]);

            // 1. Locate the EGS unit
            $unit = null;
            if ($request->filled('unit_id')) {
                $unit = EgsUnit::find($request->input('unit_id'));
            } elseif ($request->filled('unit_uuid')) {
                $unit = EgsUnit::where('uuid', $request->input('unit_uuid'))->first();
            } else {
                $unit = EgsUnit::latest()->first();
            }

            if (!$unit) {
                return response()->json([
                    'success' => false,
                    'message' => 'No EGS unit found. Please onboard a unit first via POST /api/zatca/onboard.',
                ], 404);
            }

            // 2. Guard: already fully onboarded?
            if ($unit->status === 'onboarded') {
                return response()->json([
                    'success' => true,
                    'message' => 'This EGS unit is already onboarded with a Production CSID (PCSID).',
                    'status'  => $unit->status,
                    'unit_id' => $unit->id,
                    'uuid'    => $unit->uuid,
                ]);
            }

            // 3. Guard: must have compliance credentials to exchange
            if (empty($unit->compliance_certificate) || empty($unit->compliance_secret)) {
                return response()->json([
                    'success' => false,
                    'message' => 'EGS unit is missing compliance certificate or secret. Re-onboard the unit to generate new compliance credentials.',
                ], 400);
            }

            // 4. Guard: must have a compliance request_id
            if (empty($unit->request_id)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No compliance request_id stored for this unit. Re-onboard the unit first.',
                ], 400);
            }

            // 5. Build EGS instance and request PCSID
            $egs_unit_data = $unit->toEgsArray();
            $egs = new EGS($egs_unit_data);
            $egs->production = $unit->production;

            list($prodRequestId, $productionToken, $productionSecret) = $egs->issueProductionCertificate(
                (string) $unit->request_id,
                $unit->compliance_certificate,
                $unit->compliance_secret
            );

            // 6. Upgrade unit credentials and status
            $unit->certificate  = $productionToken;
            $unit->secret       = $productionSecret;
            $unit->request_id   = $prodRequestId;
            $unit->status       = 'onboarded';
            $unit->save();

            return response()->json([
                'success' => true,
                'message' => 'Production CSID (PCSID) issued successfully! EGS unit is now fully onboarded.',
                'status'  => $unit->status,
                'unit_id' => $unit->id,
                'uuid'    => $unit->uuid,
                'request_id' => $prodRequestId,
            ]);

        } catch (\Illuminate\Validation\ValidationException $ve) {
            return response()->json([
                'success' => false,
                'errors'  => $ve->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::info($e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'hint'    => 'Common reasons: (1) You have not submitted the required 6 compliance invoices yet. (2) The compliance OTP/session has expired — re-onboard the unit. (3) Wrong ZATCA_ENV in .env.',
            ], 500);
        }
    }

    public function onboard(Request $request)
    {
        try {
            $validated = $request->validate([
                'otp' => 'required|string',
                'seller.name' => 'required|string',
                'seller.vat_number' => ['required', 'string', 'regex:/^3\d{13}3$/'],
                'seller.crn' => 'nullable|string',
                'seller.custom_id' => 'nullable|string',
                'seller.model' => 'nullable|string',
                'location.city' => 'required|string',
                'location.city_subdivision' => 'nullable|string',
                'location.street' => 'required|string',
                'location.building' => 'required|string',
                'location.postal' => 'required|string',
                'location.plot' => 'nullable|string',
            ]);

            $uuid = $request->input('seller.uuid') ?? EGS::uuid();
            $customId = $request->input('seller.custom_id') ?? ('EGS-' . substr($uuid, 0, 8));
            $solutionName = config('app.name', 'Smart Invoicing');

            $egs_unit_data = [
                'uuid' => $uuid,
                'custom_id' => $customId,
                'model' => $request->input('seller.model', 'SmartDevice-V1'),
                'CRN_number' => $request->input('seller.crn', ''),
                'VAT_name' => $request->input('seller.name'),
                'VAT_number' => $request->input('seller.vat_number'),
                'location' => [
                    'city' => $request->input('location.city'),
                    'city_subdivision' => $request->input('location.city_subdivision', ''),
                    'street' => $request->input('location.street'),
                    'plot_identification' => $request->input('location.plot', '0000'),
                    'building' => $request->input('location.building'),
                    'postal_zone' => $request->input('location.postal'),
                ],
                'branch_name' => $request->input('seller.name') . ' Branch',
                'branch_industry' => $request->input('seller.industry', 'Retail'),
                'cancelation' => [
                    'cancelation_type' => 'INVOICE',
                    'canceled_invoice_number' => '',
                ],
            ];
            $isProduction = config('zatca.environment') === 'core' || config('zatca.zatca_is_production', false);

            $egs = new EGS($egs_unit_data);
            $egs->production = $isProduction;

            // 1. Generate EC Private Key (secp256k1) & CSR
            list($private_key, $csr) = $egs->generateNewKeysAndCSR($solutionName);

            // 2. Request Compliance Certificate from ZATCA using portal OTP
            list($complianceRequestId, $complianceToken, $complianceSecret) = $egs->issueComplianceCertificate(
                $request->input('otp'),
                $csr
            );

            // 3. Initialize invoice chaining: initial PIH is base64(sha256("0"))
            $initialPIH = base64_encode(hash('sha256', '0', true));

            // 4. Store unit permanently matched by UUID
            // Note: certificate/secret are set to compliance credentials here.
            // Call POST /api/zatca/request-pcsid after submitting 6 compliance invoices
            // to exchange for the Production CSID (PCSID) and set status = 'onboarded'.
            $unit = EgsUnit::updateOrCreate(
                ['uuid' => $uuid],
                [
                    'custom_id'              => $customId,
                    'model'                  => $egs_unit_data['model'],
                    'crn_number'             => $egs_unit_data['CRN_number'],
                    'vat_name'               => $egs_unit_data['VAT_name'],
                    'vat_number'             => $egs_unit_data['VAT_number'],
                    'branch_name'            => $egs_unit_data['branch_name'],
                    'branch_industry'        => $egs_unit_data['branch_industry'],
                    'location'               => $egs_unit_data['location'],
                    'private_key'            => $private_key,
                    'compliance_certificate' => $complianceToken,
                    'compliance_secret'      => $complianceSecret,
                    'certificate'            => $complianceToken,
                    'secret'                 => $complianceSecret,
                    'request_id'             => $complianceRequestId,
                    'status'                 => 'compliance_issued',
                    'production'             => $isProduction,
                    'current_counter'        => 0,
                    'last_invoice_hash'      => $initialPIH,
                ]
            );

            return response()->json([
                'success'    => true,
                'message'    => 'EGS unit registered and Compliance CSID issued. Submit 6 compliance invoices, then call POST /api/zatca/request-pcsid to obtain the Production CSID.',
                'status'     => 'compliance_issued',
                'unit_id'    => $unit->id,
                'uuid'       => $unit->uuid,
                'request_id' => $unit->request_id,
                'next_steps' => [
                    '1' => 'Submit 6 compliance invoices via /api/zatca/issue-invoice, /api/zatca/issue-credit-note, /api/zatca/issue-debit-note',
                    '2' => 'Call POST /api/zatca/request-pcsid to exchange compliance CSID for Production CSID',
                    '3' => 'Unit status will change to "onboarded" and all future invoices will use the production certificate',
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function matchBranchProductKey(Request $request, $branch_product_key = null)
    {
        try {
            $key = $request->input('branch_product_key') ?? $branch_product_key;

            if (empty($key) || !is_string($key)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error: The branch_product_key field is required.',
                    'errors'  => [
                        'branch_product_key' => ['The branch_product_key field is required and must be a valid string.']
                    ],
                ], 422);
            }

            $unit = EgsUnit::where('uuid', trim($key))->first();

            if (!$unit) {
                return response()->json([
                    'success' => false,
                    'message' => 'No matching EGS unit found for the provided branch_product_key.',
                ], 404);
            }

            return response()->json([
                'success'     => true,
                'message'     => 'Branch product key matched successfully.',
                'id'          => $unit->id,
                'custom_id'   => $unit->custom_id,
                'branch_name' => $unit->branch_name,
                'vat_number'  => $unit->vat_number,
                'data'        => [
                    'id'          => $unit->id,
                    'custom_id'   => $unit->custom_id,
                    'branch_name' => $unit->branch_name,
                    'vat_number'  => $unit->vat_number,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
