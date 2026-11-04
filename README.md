# ZATCA (Fatoora) Phase 1 & Phase 2 E-Invoicing for Laravel

[![Laravel Version](https://img.shields.io/badge/Laravel-12.x-red.svg)](https://laravel.com)
[![PHP Version](https://img.shields.io/badge/PHP-8.2%2B-blue.svg)](https://php.net)
[![ZATCA Compliance](https://img.shields.io/badge/ZATCA-Phase%201%20%26%202%20Compliant-emerald.svg)](https://zatca.gov.sa)
[![License](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)

An end-to-end Saudi Arabia **ZATCA (Fatoora)** electronic invoicing integration built on **Laravel 12** and **React**. Supports Phase 1 QR generation, UBL 2.1 XML construction, ECDSA `secp256k1` digital signatures, 9-tag cryptographic QR codes (TLV), and automated compliance checks against the official ZATCA Developer/Simulation Portal.

---

## Table of Contents

- [Features](#features)
- [Architecture & Workflow](#architecture--workflow)
- [Tech Stack](#tech-stack)
- [Prerequisites](#prerequisites)
- [Installation & Setup](#installation--setup)
- [Windows / Laragon Environment Configuration](#windows--laragon-environment-configuration)
- [Running the Application](#running-the-application)
- [ZATCA Onboarding & Invoicing Flow](#zatca-onboarding--invoicing-flow)
- [Verifying Invoices](#verifying-invoices)
- [Project Directory Structure](#project-directory-structure)
- [API Endpoints](#api-endpoints)
- [Security Notice](#security-notice)
- [License](#license)

---

## Features

### Phase 1 (Generation Phase)
- **Tag-Length-Value (TLV) Encoding:** Compliant 5-tag TLV structure (Seller Name, VAT Number, Issue Timestamp, Invoice Total, VAT Total) converted to Base64.
- **High-Resolution QR Codes:** Rendered via `endroid/qr-code` with error correction and optional company branding/logo overlay.

### Phase 2 (Integration Phase - Simplified Tax Invoices)
- **ECDSA `secp256k1` Keypair & CSR Generation:** Programmatic generation of Elliptic Curve private keys and ZATCA-compliant Certificate Signing Requests with custom OIDs/ASN.1 attributes.
- **UBL 2.1 XML Generation:** Generates valid OASIS UBL 2.1 Simplified Tax Invoices (`0200000`, UN/CEFACT 388) conforming to Saudi business rules (`BR-KSA-*`).
- **Cryptographic Hashing & Signing:**
  - Pure canonicalized invoice SHA-256 hash calculation.
  - Digital signature using ECDSA with SHA-256 (`openssl_sign`).
  - Signed properties hashing and UBLExtensions signature injection.
- **Phase 2 Cryptographic QR Code:** 9-tag TLV structure containing seller info, totals, invoice hash, ECDSA digital signature, public key, and ZATCA certificate signature.
- **ZATCA Portal Integration:** Automated issuance of Compliance CSID certificates and real-time compliance validation via ZATCA Developer Portal endpoints.
- **React Management Dashboard:** Modern UI for composing invoices, reviewing compliance status, previewing QR codes, and downloading compliant signed XML files.

---

## Architecture & Workflow

```
┌────────────────────────────────────────────────────────┐
│                   React Dashboard                      │
│        (Seller Details, Line Items, Issue Date)        │
└──────────────────────────┬─────────────────────────────┘
                           │ POST /api/zatca/phase-2
                           ▼
┌────────────────────────────────────────────────────────┐
│             ZatcaController & EGS Engine               │
│                                                        │
│  1. OpenSSL: Generate secp256k1 Private Key & CSR      │
│  2. API.php: Request Compliance CSID from ZATCA        │
│  3. ZATCASimplifiedTaxInvoice: Build UBL 2.1 XML       │
│  4. SHA-256 Hash -> ECDSA Digital Signature            │
│  5. Inject UBLExtensions & Generate 9-Tag TLV QR       │
│  6. API.php: Check Compliance (/compliance/invoices)  │
│  7. Store record, QR PNG & Signed XML into MySQL       │
└──────────────────────────┬─────────────────────────────┘
                           │
             ┌─────────────┴─────────────┐
             ▼                           ▼
┌──────────────────────────┐┌────────────────────────────┐
│   ZATCA Cloud Portal     ││     Database & Storage     │
│ (XSD & Schematron Rules) ││ (Invoices, XML, QR Assets) │
└──────────────────────────┘└────────────────────────────┘
```

---

## Tech Stack

- **Backend:** Laravel 12, PHP 8.2+
- **Frontend:** React 19, TailwindCSS, Lucide Icons, Framer Motion
- **Cryptography & Security:** OpenSSL CLI (`secp256k1`), PHP OpenSSL Extension (`openssl_sign`, `openssl_digest`, `openssl_x509_parse`)
- **QR Code:** `endroid/qr-code`
- **Database:** MySQL

---

## Prerequisites

- **PHP 8.2 or higher** with extensions: `openssl`, `curl`, `dom`, `pdo_mysql`, `mbstring`.
- **OpenSSL CLI binary (`openssl.exe`)** available on your machine.
- **Composer** (v2+)
- **Node.js** (v18+) & **NPM**
- **MySQL Database Server** (e.g. Laragon, XAMPP, or standalone MySQL)

---

## Installation & Setup

### 1. Clone the Repository
```bash
git clone <your-repository-url>
cd zatca-phase-1-2-laravel
```

### 2. Install PHP Dependencies
```bash
composer install
```

### 3. Install Frontend Dependencies
```bash
npm install
```

### 4. Configure Environment
```bash
cp .env.example .env
php artisan key:generate
```

Edit your `.env` file and configure your database:
```ini
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=zatca_phase_two
DB_USERNAME=root
DB_PASSWORD=
```

### 5. Run Database Migrations
```bash
php artisan migrate
```

---

## Windows / Laragon Environment Configuration

If you are running the project on **Windows with Laragon**, make sure two configuration settings are in place:

### 1. OpenSSL Path in `app/ZATCA/EGS.php`
Ensure lines 29 and 68 in [`app/ZATCA/EGS.php`](app/ZATCA/EGS.php) point to your local OpenSSL binary, for example:
```php
$cmd = 'D:\\laragon\\bin\\apache\\httpd-2.4.62-240904-win64-VS17\\bin\\openssl.exe ecparam -name secp256k1 -genkey 2>&1';
```

### 2. Fix cURL SSL Certificate File (`curl.cainfo`)
To prevent `cURL error 77 (error setting certificate file)`, verify your `php.ini` has the correct absolute path to `cacert.pem`:
1. Open `D:\laragon\bin\php\php-8.x\php.ini`.
2. Locate `curl.cainfo` (around line 1939) and set:
   ```ini
   curl.cainfo = "D:\laragon\etc\ssl\cacert.pem"
   ```
3. Restart Apache / PHP.

---

## Running the Application

### 1. Build Frontend Assets
For local development with hot reload:
```bash
npm run dev
```
Or build production assets:
```bash
npm run build
```

### 2. Start Laravel Development Server
```bash
php artisan serve
```
Open your browser at `http://127.0.0.1:8000`.

---

## ZATCA Onboarding & Invoicing Flow

1. **Obtain OTP from ZATCA Portal:**
   - Log in to the [ZATCA Fatoora Portal (Sandbox / Simulation)](https://fatoora.zatca.gov.sa/).
   - Navigate to **Onboard Solution Unit** -> Click **Generate OTP** (6-digit code).
2. **Submit Invoice via Dashboard:**
   - Open the web interface at `http://127.0.0.1:8000`.
   - Ensure the VAT Number and Organization match your ZATCA portal account.
   - Click **"Sign and Transmit"**.
3. **Automated Verification:**
   - The engine generates ECDSA keys, obtains the CSID from ZATCA, signs the invoice XML, checks compliance against ZATCA, and returns the verified QR code and compliance report.

---

## Verifying Invoices

### 1. Official ZATCA Mobile App
Scan the generated QR code on the screen using the official **ZATCA App** (available on iOS and Android). The app will decode:
- Seller Name & VAT Registration Number
- Issue Timestamp
- Total Invoice Amount & VAT Breakdown
- Digital Signature & Public Key

*(Note: In the developer sandbox stage, invoices use test certificates from `TSTZATCA`, so the production app displays the data correctly while indicating the invoice is a sandbox/unregistered test invoice).*

### 2. ZATCA Official Fatoora SDK
Download the signed XML file from the dashboard and validate offline using the official ZATCA Java SDK CLI:
```cmd
fatoora -validate -invoice <invoice_filename>.xml
```

---

## Project Directory Structure

```
zatca-phase-1-2-laravel/
├── app/
│   ├── Http/Controllers/
│   │   └── ZatcaController.php         # Invoice submission & API orchestration
│   ├── Models/
│   │   └── Invoice.php                 # Eloquent model with compliance results & XML
│   └── ZATCA/
│       ├── API.php                     # cURL client for ZATCA endpoints
│       ├── EGS.php                     # Keypair/CSR generation & signing coordinator
│       ├── GenerateQrCode.php          # Phase 1 TLV to Base64 encoder
│       ├── Tag.php                     # Base TLV tag definition
│       ├── ZATCASimplifiedTaxInvoice.php # UBL 2.1 XML builder & crypto signature injector
│       ├── Tags/                       # Phase 1 individual TLV tag classes
│       └── templates/                  # Raw UBL 2.1 XML & signature templates
├── database/migrations/                # Invoices & system database tables
├── resources/
│   ├── js/
│   │   ├── Index.jsx                   # React root entry
│   │   └── MainApp.jsx                 # Interactive ZATCA invoice dashboard
│   └── views/
│       └── welcome.blade.php           # Blade shell for React SPA
├── routes/
│   └── web.php                         # Application and API routes
└── storage/app/tmp/                    # Temporary working folder for OpenSSL operations
```

---

## API Endpoints

| Method | Endpoint | Description |
| :--- | :--- | :--- |
| `GET` | `/` | Web dashboard (React SPA) |
| `GET` | `/phase-1` | Generate Phase 1 standalone TLV QR code image |
| `POST` | `/api/zatca/phase-2` | Generate, sign, and report Phase 2 invoice to ZATCA |
| `GET` | `/api/zatca/invoices` | Retrieve invoice history with compliance status |
| `GET` | `/api/zatca/invoices/{id}/download-xml` | Download compliant UBL 2.1 signed XML file |

---

## Security Notice

- Never commit `.pem` private keys or `.cnf` certificate configs to version control.
- Ensure `storage/app/tmp/*` and `.env` are maintained in `.gitignore`.
- For production environments, encrypt stored private keys and API secrets using Laravel's encryption services (`Crypt::encryptString`).

---

## License

This project is open-sourced under the [MIT License](LICENSE).
