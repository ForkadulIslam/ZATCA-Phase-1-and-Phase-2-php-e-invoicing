<?php

/**
 * Standard Tax Invoice (B2B) Template
 *
 * cbc:InvoiceTypeCode name="0100000":
 *   - Starts with "01" = Standard Tax Invoice (B2B, subject to clearance).
 *   - Remaining digits: Tax(1) Simplified(0) futureUse(0) futureUse(0) futureUse(0)
 *
 * Key differences from Simplified:
 *   - name="0100000"  vs  name="0200000"
 *   - ProfileID = "reporting:1.0" (same, clearance path chosen at submission)
 *   - AccountingCustomerParty is REQUIRED (buyer VAT + address)
 *   - Submitted to /invoices/clearance/single (not /invoices/reporting/single)
 */

return /* XML */
    <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2" xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2" xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2" xmlns:ext="urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2">
    <ext:UBLExtensions>SET_UBL_EXTENSIONS_STRING
    </ext:UBLExtensions>
    <cbc:ProfileID>reporting:1.0</cbc:ProfileID>
    <cbc:ID>SET_INVOICE_SERIAL_NUMBER</cbc:ID>
    <cbc:UUID>SET_TERMINAL_UUID</cbc:UUID>
    <cbc:IssueDate>SET_ISSUE_DATE</cbc:IssueDate>
    <cbc:IssueTime>SET_ISSUE_TIME</cbc:IssueTime>
    <cbc:InvoiceTypeCode name="0100000">388</cbc:InvoiceTypeCode>
    <cbc:DocumentCurrencyCode>SAR</cbc:DocumentCurrencyCode>
    <cbc:TaxCurrencyCode>SAR</cbc:TaxCurrencyCode>SET_BILLING_REFERENCE
    <cac:AdditionalDocumentReference>
        <cbc:ID>ICV</cbc:ID>
        <cbc:UUID>SET_INVOICE_COUNTER_NUMBER</cbc:UUID>
    </cac:AdditionalDocumentReference>
    <cac:AdditionalDocumentReference>
        <cbc:ID>PIH</cbc:ID>
        <cac:Attachment>
            <cbc:EmbeddedDocumentBinaryObject mimeCode="text/plain">SET_PREVIOUS_INVOICE_HASH</cbc:EmbeddedDocumentBinaryObject>
        </cac:Attachment>
    </cac:AdditionalDocumentReference>
    <cac:AdditionalDocumentReference>
        <cbc:ID>QR</cbc:ID>
        <cac:Attachment>
            <cbc:EmbeddedDocumentBinaryObject mimeCode="text/plain">SET_QR_CODE_DATA</cbc:EmbeddedDocumentBinaryObject>
        </cac:Attachment>
    </cac:AdditionalDocumentReference>
    <cac:Signature>
      <cbc:ID>urn:oasis:names:specification:ubl:signature:Invoice</cbc:ID>
      <cbc:SignatureMethod>urn:oasis:names:specification:ubl:dsig:enveloped:xades</cbc:SignatureMethod>
    </cac:Signature>
    <cac:AccountingSupplierParty>
        <cac:Party>
            SET_PARTY_IDENTIFICATION
            <cac:PostalAddress>
                <cbc:StreetName>SET_STREET_NAME</cbc:StreetName>
                <cbc:BuildingNumber>SET_BUILDING_NUMBER</cbc:BuildingNumber>
                <cbc:PlotIdentification>SET_PLOT_IDENTIFICATION</cbc:PlotIdentification>
                <cbc:CitySubdivisionName>SET_CITY_SUBDIVISION</cbc:CitySubdivisionName>
                <cbc:CityName>SET_CITY</cbc:CityName>
                <cbc:PostalZone>SET_POSTAL_NUMBER</cbc:PostalZone>
                <cac:Country>
                    <cbc:IdentificationCode>SA</cbc:IdentificationCode>
                </cac:Country>
            </cac:PostalAddress>
            <cac:PartyTaxScheme>
                <cbc:CompanyID>SET_VAT_NUMBER</cbc:CompanyID>
                <cac:TaxScheme>
                    <cbc:ID>VAT</cbc:ID>
                </cac:TaxScheme>
            </cac:PartyTaxScheme>
            <cac:PartyLegalEntity>
                <cbc:RegistrationName>SET_VAT_NAME</cbc:RegistrationName>
            </cac:PartyLegalEntity>
        </cac:Party>
    </cac:AccountingSupplierParty>
    <cac:AccountingCustomerParty>
        <cac:Party>
            SET_BUYER_PARTY_IDENTIFICATION
            <cac:PostalAddress>
                <cbc:StreetName>SET_BUYER_STREET_NAME</cbc:StreetName>
                <cbc:BuildingNumber>SET_BUYER_BUILDING_NUMBER</cbc:BuildingNumber>
                <cbc:PlotIdentification>SET_BUYER_PLOT_IDENTIFICATION</cbc:PlotIdentification>
                <cbc:CitySubdivisionName>SET_BUYER_CITY_SUBDIVISION</cbc:CitySubdivisionName>
                <cbc:CityName>SET_BUYER_CITY</cbc:CityName>
                <cbc:PostalZone>SET_BUYER_POSTAL_NUMBER</cbc:PostalZone>
                <cac:Country>
                    <cbc:IdentificationCode>SA</cbc:IdentificationCode>
                </cac:Country>
            </cac:PostalAddress>
            <cac:PartyTaxScheme>
                <cbc:CompanyID>SET_BUYER_VAT_NUMBER</cbc:CompanyID>
                <cac:TaxScheme>
                    <cbc:ID>VAT</cbc:ID>
                </cac:TaxScheme>
            </cac:PartyTaxScheme>
            <cac:PartyLegalEntity>
                <cbc:RegistrationName>SET_BUYER_VAT_NAME</cbc:RegistrationName>
            </cac:PartyLegalEntity>
        </cac:Party>
    </cac:AccountingCustomerParty>
    <cac:Delivery>
        <cbc:ActualDeliveryDate>SET_SUPPLY_DATE</cbc:ActualDeliveryDate>
    </cac:Delivery>
    PARSE_LINE_ITEMS
</Invoice>
XML;
