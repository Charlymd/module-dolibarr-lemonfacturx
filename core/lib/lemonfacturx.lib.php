<?php
/*
 * Copyright (C) 2026 SASU LEMON <https://hellolemon.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Générateur XML CrossIndustryInvoice EN16931 pour Factur-X
 * Conforme aux règles BR-FR (XP Z12-012 V1.2.0)
 */

/**
 * Liste des codes ISO des pays de l'Union européenne (utilisée pour qualifier
 * la catégorie de TVA EN16931 sur les opérations B2B intracommunautaires).
 *
 * Mentions légales BR-FR par défaut (BG-3 IncludedNote) : surchargeable via
 * les constantes Dolibarr LEMONFACTURX_NOTE_*.
 *
 * define() (au lieu de const) pour rester tolérant si la lib est incluse depuis
 * deux chemins distincts (custom + dol_buildpath) sur certains setups.
 */
if (!defined('LEMONFACTURX_EU_COUNTRIES')) {
	define('LEMONFACTURX_EU_COUNTRIES', [
		'AT','BE','BG','CY','CZ','DE','DK','EE','ES','FI','FR','GR','HR','HU',
		'IE','IT','LT','LU','LV','MT','NL','PL','PT','RO','SE','SI','SK',
	]);
	define('LEMONFACTURX_DEFAULT_NOTE_PMD', 'En cas de retard de paiement, une pénalité égale à 3 fois le taux d\'intérêt légal sera exigible (article L.441-10 du Code de commerce).');
	define('LEMONFACTURX_DEFAULT_NOTE_PMT', 'Une indemnité forfaitaire de 40 euros sera exigible pour frais de recouvrement en cas de retard de paiement.');
	define('LEMONFACTURX_DEFAULT_NOTE_AAB', 'Pas d\'escompte pour paiement anticipé.');
}

/**
 * Traduit une clé via $langs si l'environnement Dolibarr est chargé,
 * sinon renvoie la clé brute (contexte tests unitaires standalone).
 */
function lemonfacturx_trans($key, ...$args)
{
	global $langs;
	if (is_object($langs) && method_exists($langs, 'trans')) {
		$langs->load('lemonfacturx@lemonfacturx');
		return $langs->trans($key, ...$args);
	}
	return $args ? $key.' ('.implode(', ', array_map('strval', $args)).')' : $key;
}

/**
 * Vérifie si la facture est dans le périmètre supporté par le générateur.
 * Renvoie un message d'erreur bloquant, ou null si la facture est traitable.
 *
 * Multidevise : Dolibarr ne stocke la ventilation TVA qu'en devise société.
 * Émettre un XML en devise société alors que le PDF visible est en devise
 * étrangère violerait le principe hybride Factur-X (lisible = structuré).
 * Le support BT-5/BT-6 complet nécessiterait les montants multicurrency_total_*
 * par ligne + le double TaxTotalAmount — non implémenté à ce stade (documenté).
 *
 * @param Facture $invoice
 * @return string|null
 */
function lemonfacturx_check_supported($invoice)
{
	global $conf;

	$companyCurrency = !empty($conf->currency) ? $conf->currency : 'EUR';
	if (!empty($invoice->multicurrency_code) && $invoice->multicurrency_code !== $companyCurrency) {
		return lemonfacturx_trans('LemonFacturXErrMulticurrency', $invoice->multicurrency_code, $companyCurrency);
	}

	// Taxes locales (localtax1/2, RE/IRPF...) : non représentables dans le XML
	// EN16931 (TVA uniquement). Le total XML divergerait du TTC visible sur le
	// PDF — même principe de refus que le multidevise.
	if (abs((float) ($invoice->total_localtax1 ?? 0)) > 0.005 || abs((float) ($invoice->total_localtax2 ?? 0)) > 0.005) {
		return lemonfacturx_trans('LemonFacturXErrLocalTax');
	}

	if (empty($invoice->date)) {
		return lemonfacturx_trans('LemonFacturXErrNoDate');
	}

	return null;
}

/**
 * Génère le XML Factur-X EN16931 à partir d'une facture Dolibarr.
 *
 * Convention avoirs (TypeCode 381) : Dolibarr stocke des totaux négatifs,
 * EN16931 exige des montants positifs (BR-27 : prix net ligne >= 0). Tous les
 * montants sont donc multipliés par -1 pour un avoir, et DuePayableAmount
 * respecte BR-CO-16 (BT-115 = BT-112 - BT-113, sans écrêtage à zéro).
 *
 * @param Facture $invoice        Objet facture Dolibarr (avec lines chargées)
 * @param Societe $mysoc          Société émettrice (vendeur)
 * @param array   $buildWarnings  (sortie) Avertissements non bloquants détectés pendant la génération
 * @return string                 XML CrossIndustryInvoice
 */
function lemonfacturx_build_xml($invoice, $mysoc, &$buildWarnings = [], $options = [])
{
	global $conf;

	// Profil de génération. 'pdp' (défaut) = norme EN16931/PDP (SIREN en BT-30).
	// 'choruspro' = profil B2G Chorus Pro (SIRET en BT-30 + champs BT-10/12/13).
	$profile = (isset($options['profile']) && $options['profile'] === 'choruspro') ? 'choruspro' : 'pdp';
	$legalIdMode = ($profile === 'choruspro') ? 'siret' : 'siren';

	$typeCode = lemonfacturx_resolve_document_type($invoice, $buildWarnings);
	$isCreditNote = ($typeCode === '381');
	$sign = $isCreditNote ? -1.0 : 1.0;

	$issueDate = date('Ymd', $invoice->date);
	$dueDate = !empty($invoice->date_lim_reglement) ? date('Ymd', $invoice->date_lim_reglement) : $issueDate;
	$currency = !empty($conf->currency) ? $conf->currency : 'EUR';

	$buyer = $invoice->thirdparty;
	$bank = lemonfacturx_get_bank_account($invoice->db);
	$paymentMeans = getDolGlobalString('LEMONFACTURX_PAYMENT_MEANS', '30');

	// Lignes utiles : on filtre une seule fois les lignes sans montant
	// (descriptions, titres, sous-totaux) pour les réutiliser ci-dessous.
	$billableLines = lemonfacturx_filter_billable_lines($invoice->lines);

	// Sépare lignes facturables et remises pied de facture (BG-21) : une ligne
	// à total négatif (remise fixe Dolibarr) devient une SpecifiedTradeAllowanceCharge
	// document pour respecter BR-27 (prix net de ligne jamais négatif).
	$prepared = lemonfacturx_prepare_lines($billableLines, $sign, $invoice, $buyer, $mysoc, $buildWarnings);

	// Ventilation TVA par (catégorie, taux), réconciliée avec les totaux facture
	$breakdown = lemonfacturx_get_tax_breakdown($prepared, $invoice, $sign, $buildWarnings);

	// BR-61 : un moyen de paiement 30/58 (virement) exige un IBAN. Sans compte
	// bancaire configuré, on omet le bloc PaymentMeans plutôt que d'émettre un
	// XML rejeté par les validateurs Schematron.
	$emitPaymentMeans = true;
	if (in_array($paymentMeans, ['30', '58'], true) && empty($bank['iban'])) {
		$emitPaymentMeans = false;
		$buildWarnings[] = lemonfacturx_trans('LemonFacturXWarnPaymentMeansOmitted', $paymentMeans);
	}

	// Prélèvement SEPA (59) : ICS créancier (BT-90), RUM mandat (BT-89), IBAN débiteur (BT-91)
	$directDebit = ($paymentMeans === '59') ? lemonfacturx_get_direct_debit_info($invoice->db, $buyer, $buildWarnings) : null;

	$xml  = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
	$xml .= '<rsm:CrossIndustryInvoice xmlns:rsm="urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100"';
	$xml .= ' xmlns:ram="urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100"';
	$xml .= ' xmlns:udt="urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100"';
	$xml .= ' xmlns:qdt="urn:un:unece:uncefact:data:standard:QualifiedDataType:100">'."\n";

	// === ExchangedDocumentContext ===
	$xml .= '<rsm:ExchangedDocumentContext>'."\n";
	// BT-23 : cadre de facturation (process métier) — requis par les
	// spécifications externes PPF/PDP pour qualifier le cas d'usage (B1, S1...)
	// et par Chorus Pro B2G (A1...). Omis si non configuré.
	$bt23 = trim(getDolGlobalString('LEMONFACTURX_BT23_PROCESS', ''));
	// Profil Chorus Pro : le cadre de facturation (BT-23) est choisi PAR FACTURE
	// parmi les 24 valeurs AIFE (A1..A25), et il est obligatoire pour le dépôt B2G.
	// A1 (dépôt fournisseur) par défaut si non renseigné.
	if ($profile === 'choruspro') {
		$bt23 = !empty($options['cadre']) ? $options['cadre'] : 'A1';
	}
	if ($bt23 !== '') {
		$xml .= '  <ram:BusinessProcessSpecifiedDocumentContextParameter>'."\n";
		$xml .= '    <ram:ID>'.lemonfacturx_xml_encode($bt23).'</ram:ID>'."\n";
		$xml .= '  </ram:BusinessProcessSpecifiedDocumentContextParameter>'."\n";
	}
	$xml .= '  <ram:GuidelineSpecifiedDocumentContextParameter>'."\n";
	$xml .= '    <ram:ID>urn:cen.eu:en16931:2017</ram:ID>'."\n";
	$xml .= '  </ram:GuidelineSpecifiedDocumentContextParameter>'."\n";
	$xml .= '</rsm:ExchangedDocumentContext>'."\n";

	// === ExchangedDocument ===
	$xml .= '<rsm:ExchangedDocument>'."\n";
	$xml .= '  <ram:ID>'.lemonfacturx_xml_encode($invoice->ref).'</ram:ID>'."\n";
	$xml .= '  <ram:TypeCode>'.lemonfacturx_xml_encode($typeCode).'</ram:TypeCode>'."\n";
	$xml .= '  <ram:IssueDateTime>'."\n";
	$xml .= '    <udt:DateTimeString format="102">'.lemonfacturx_xml_encode($issueDate).'</udt:DateTimeString>'."\n";
	$xml .= '  </ram:IssueDateTime>'."\n";
	$xml .= lemonfacturx_build_legal_notes_xml();
	$xml .= '</rsm:ExchangedDocument>'."\n";

	// === SupplyChainTradeTransaction ===
	$xml .= '<rsm:SupplyChainTradeTransaction>'."\n";

	$xmlLineNum = 0;
	foreach ($prepared['lines'] as $pl) {
		$xmlLineNum++;
		$xml .= lemonfacturx_build_line_xml($pl, $xmlLineNum);
	}

	$xml .= '  <ram:ApplicableHeaderTradeAgreement>'."\n";
	// BT-10 : référence acheteur / code service. En profil Chorus, le code service
	// exécutant explicite (options['service_code']) prime sur ref_client.
	$buyerRef = ($profile === 'choruspro' && !empty($options['service_code']))
		? $options['service_code']
		: (!empty($invoice->ref_client) ? $invoice->ref_client : '');
	if ($buyerRef !== '') {
		$xml .= '    <ram:BuyerReference>'.lemonfacturx_xml_encode($buyerRef).'</ram:BuyerReference>'."\n";
	}
	$xml .= lemonfacturx_build_trade_party_xml('Seller', $mysoc, $mysoc->email ?? '', $legalIdMode);
	$xml .= lemonfacturx_build_trade_party_xml('Buyer', $buyer, lemonfacturx_get_buyer_email($buyer, $invoice->db), $legalIdMode);
	// BT-13 : n° d'engagement juridique (Chorus) sinon référence de la commande liée.
	$orderRef = ($profile === 'choruspro' && !empty($options['engagement']))
		? $options['engagement']
		: lemonfacturx_get_linked_order_ref($invoice);
	if ($orderRef !== '') {
		$xml .= '    <ram:BuyerOrderReferencedDocument>'."\n";
		$xml .= '      <ram:IssuerAssignedID>'.lemonfacturx_xml_encode($orderRef).'</ram:IssuerAssignedID>'."\n";
		$xml .= '    </ram:BuyerOrderReferencedDocument>'."\n";
	}
	// BT-12 : référence du marché (Chorus Pro). Séquence XSD : après BuyerOrder.
	if ($profile === 'choruspro' && !empty($options['marche'])) {
		$xml .= '    <ram:ContractReferencedDocument>'."\n";
		$xml .= '      <ram:IssuerAssignedID>'.lemonfacturx_xml_encode($options['marche']).'</ram:IssuerAssignedID>'."\n";
		$xml .= '    </ram:ContractReferencedDocument>'."\n";
	}
	$xml .= '  </ram:ApplicableHeaderTradeAgreement>'."\n";

	// === Delivery (BT-70..BT-80) ===
	// BT-72 : date de livraison réelle si renseignée sur la facture. Pour les
	// livraisons intracommunautaires (catégorie K), BR-IC-11 exige une date de
	// livraison (repli : date d'émission) et BR-IC-12 un pays de livraison
	// (BT-80, repli : pays de l'acheteur via ShipToTradeParty).
	$hasK = false;
	$hasIntracom = false;
	foreach ($breakdown as $b) {
		if ($b['categoryCode'] === 'K') {
			$hasK = true;
		}
		if ($b['categoryCode'] === 'K' || $b['categoryCode'] === 'AE') {
			$hasIntracom = true;
		}
	}
	// Date de livraison (BT-72) : la vraie date si renseignée, sinon la date
	// d'émission. On la met TOUJOURS : ça satisfait BR-IC-11 pour l'intracom, et
	// surtout ça garantit un ApplicableHeaderTradeDelivery non vide (sinon warning
	// PEPPOL-EN16931-R008 « Document MUST not contain empty elements »).
	$deliveryDateTs = !empty($invoice->delivery_date) ? $invoice->delivery_date : ($invoice->date_livraison ?? null);
	$deliveryDate = !empty($deliveryDateTs) ? date('Ymd', $deliveryDateTs) : $issueDate;

	$xml .= '  <ram:ApplicableHeaderTradeDelivery>'."\n";
	if ($hasK) {
		$shipToCountry = !empty($buyer->country_code) ? $buyer->country_code : 'FR';
		$xml .= '    <ram:ShipToTradeParty>'."\n";
		$xml .= '      <ram:PostalTradeAddress>'."\n";
		$xml .= '        <ram:CountryID>'.lemonfacturx_xml_encode($shipToCountry).'</ram:CountryID>'."\n";
		$xml .= '      </ram:PostalTradeAddress>'."\n";
		$xml .= '    </ram:ShipToTradeParty>'."\n";
	}
	if ($deliveryDate !== null) {
		$xml .= '    <ram:ActualDeliverySupplyChainEvent>'."\n";
		$xml .= '      <ram:OccurrenceDateTime>'."\n";
		$xml .= '        <udt:DateTimeString format="102">'.lemonfacturx_xml_encode($deliveryDate).'</udt:DateTimeString>'."\n";
		$xml .= '      </ram:OccurrenceDateTime>'."\n";
		$xml .= '    </ram:ActualDeliverySupplyChainEvent>'."\n";
	}
	$xml .= '  </ram:ApplicableHeaderTradeDelivery>'."\n";

	// === Settlement ===
	$xml .= '  <ram:ApplicableHeaderTradeSettlement>'."\n";
	// BT-90 : identifiant créancier SEPA (ICS) pour le prélèvement
	if ($directDebit !== null && $directDebit['ics'] !== '') {
		$xml .= '    <ram:CreditorReferenceID>'.lemonfacturx_xml_encode($directDebit['ics']).'</ram:CreditorReferenceID>'."\n";
	}
	$xml .= '    <ram:InvoiceCurrencyCode>'.lemonfacturx_xml_encode($currency).'</ram:InvoiceCurrencyCode>'."\n";

	if ($emitPaymentMeans) {
		$xml .= '    <ram:SpecifiedTradeSettlementPaymentMeans>'."\n";
		$xml .= '      <ram:TypeCode>'.lemonfacturx_xml_encode($paymentMeans).'</ram:TypeCode>'."\n";
		// BT-91 : compte débité (prélèvement)
		if ($directDebit !== null && $directDebit['debtor_iban'] !== '') {
			$xml .= '      <ram:PayerPartyDebtorFinancialAccount>'."\n";
			$xml .= '        <ram:IBANID>'.lemonfacturx_xml_encode($directDebit['debtor_iban']).'</ram:IBANID>'."\n";
			$xml .= '      </ram:PayerPartyDebtorFinancialAccount>'."\n";
		}
		if (!empty($bank['iban'])) {
			$xml .= '      <ram:PayeePartyCreditorFinancialAccount>'."\n";
			$xml .= '        <ram:IBANID>'.lemonfacturx_xml_encode($bank['iban']).'</ram:IBANID>'."\n";
			$xml .= '      </ram:PayeePartyCreditorFinancialAccount>'."\n";
			if (!empty($bank['bic'])) {
				$xml .= '      <ram:PayeeSpecifiedCreditorFinancialInstitution>'."\n";
				$xml .= '        <ram:BICID>'.lemonfacturx_xml_encode($bank['bic']).'</ram:BICID>'."\n";
				$xml .= '      </ram:PayeeSpecifiedCreditorFinancialInstitution>'."\n";
			}
		}
		$xml .= '    </ram:SpecifiedTradeSettlementPaymentMeans>'."\n";
	}

	// BT-8 : option TVA sur les débits (5) ou les encaissements (72), émise sur
	// les catégories taxées uniquement. Mention obligatoire FR (décret 2022-1299)
	// et donnée du socle de la réforme. Configurable, omise par défaut.
	$dueDateTypeCode = trim(getDolGlobalString('LEMONFACTURX_VAT_DUE_DATE_TYPE', ''));

	foreach ($breakdown as $amounts) {
		$xml .= '    <ram:ApplicableTradeTax>'."\n";
		$xml .= '      <ram:CalculatedAmount>'.lemonfacturx_format_amount($amounts['tax']).'</ram:CalculatedAmount>'."\n";
		$xml .= '      <ram:TypeCode>VAT</ram:TypeCode>'."\n";
		// ExemptionReason émis pour toute catégorie non-standard quand un motif est disponible
		if (!empty($amounts['exemption']) && in_array($amounts['categoryCode'], ['E', 'K', 'G', 'O', 'Z', 'AE'], true)) {
			$xml .= '      <ram:ExemptionReason>'.lemonfacturx_xml_encode($amounts['exemption']).'</ram:ExemptionReason>'."\n";
		}
		$xml .= '      <ram:BasisAmount>'.lemonfacturx_format_amount($amounts['base']).'</ram:BasisAmount>'."\n";
		$xml .= '      <ram:CategoryCode>'.lemonfacturx_xml_encode($amounts['categoryCode']).'</ram:CategoryCode>'."\n";
		// BT-121 : code d'exonération VATEX (attendu par la réforme FR)
		if (!empty($amounts['vatex'])) {
			$xml .= '      <ram:ExemptionReasonCode>'.lemonfacturx_xml_encode($amounts['vatex']).'</ram:ExemptionReasonCode>'."\n";
		}
		if ($dueDateTypeCode !== '' && $amounts['categoryCode'] === 'S') {
			$xml .= '      <ram:DueDateTypeCode>'.lemonfacturx_xml_encode($dueDateTypeCode).'</ram:DueDateTypeCode>'."\n";
		}
		// BR-O-05 : pas de RateApplicablePercent pour CategoryCode='O' (services hors champ)
		if ($amounts['categoryCode'] !== 'O') {
			$xml .= '      <ram:RateApplicablePercent>'.lemonfacturx_format_amount($amounts['rate']).'</ram:RateApplicablePercent>'."\n";
		}
		$xml .= '    </ram:ApplicableTradeTax>'."\n";
	}

	// BG-14 : période de facturation (dates de service des lignes Dolibarr)
	$xml .= lemonfacturx_build_billing_period_xml($billableLines);

	// BG-21 : remises pied de facture (issues des lignes à montant négatif)
	foreach ($prepared['allowances'] as $al) {
		$xml .= '    <ram:SpecifiedTradeAllowanceCharge>'."\n";
		$xml .= '      <ram:ChargeIndicator><udt:Indicator>false</udt:Indicator></ram:ChargeIndicator>'."\n";
		$xml .= '      <ram:ActualAmount>'.lemonfacturx_format_amount($al['amount']).'</ram:ActualAmount>'."\n";
		$xml .= '      <ram:Reason>'.lemonfacturx_xml_encode($al['reason']).'</ram:Reason>'."\n";
		$xml .= '      <ram:CategoryTradeTax>'."\n";
		$xml .= '        <ram:TypeCode>VAT</ram:TypeCode>'."\n";
		$xml .= '        <ram:CategoryCode>'.lemonfacturx_xml_encode($al['categoryCode']).'</ram:CategoryCode>'."\n";
		if ($al['categoryCode'] !== 'O') {
			$xml .= '        <ram:RateApplicablePercent>'.lemonfacturx_format_amount($al['rate']).'</ram:RateApplicablePercent>'."\n";
		}
		$xml .= '      </ram:CategoryTradeTax>'."\n";
		$xml .= '    </ram:SpecifiedTradeAllowanceCharge>'."\n";
	}

	$xml .= '    <ram:SpecifiedTradePaymentTerms>'."\n";
	$xml .= '      <ram:DueDateDateTime>'."\n";
	$xml .= '        <udt:DateTimeString format="102">'.lemonfacturx_xml_encode($dueDate).'</udt:DateTimeString>'."\n";
	$xml .= '      </ram:DueDateDateTime>'."\n";
	// BT-89 : référence unique de mandat (RUM) pour le prélèvement SEPA
	if ($directDebit !== null && $directDebit['rum'] !== '') {
		$xml .= '      <ram:DirectDebitMandateID>'.lemonfacturx_xml_encode($directDebit['rum']).'</ram:DirectDebitMandateID>'."\n";
	}
	$xml .= '    </ram:SpecifiedTradePaymentTerms>'."\n";

	$xml .= lemonfacturx_build_monetary_summation_xml($invoice, $currency, $sign, $prepared, $breakdown, $buildWarnings);

	// BG-3 : références aux factures antérieures (facture d'origine d'un avoir /
	// d'une rectificative, factures d'acompte imputées sur une facture finale).
	foreach (lemonfacturx_get_preceding_invoices($invoice) as $prev) {
		$xml .= '    <ram:InvoiceReferencedDocument>'."\n";
		$xml .= '      <ram:IssuerAssignedID>'.lemonfacturx_xml_encode($prev['ref']).'</ram:IssuerAssignedID>'."\n";
		if (!empty($prev['date'])) {
			$xml .= '      <ram:FormattedIssueDateTime>'."\n";
			$xml .= '        <qdt:DateTimeString format="102">'.lemonfacturx_xml_encode($prev['date']).'</qdt:DateTimeString>'."\n";
			$xml .= '      </ram:FormattedIssueDateTime>'."\n";
		}
		$xml .= '    </ram:InvoiceReferencedDocument>'."\n";
	}

	$xml .= '  </ram:ApplicableHeaderTradeSettlement>'."\n";

	$xml .= '</rsm:SupplyChainTradeTransaction>'."\n";
	$xml .= '</rsm:CrossIndustryInvoice>';

	// Avoir sans facture d'origine : mention FR obligatoire impossible à émettre
	if ($isCreditNote && empty($invoice->fk_facture_source)) {
		$buildWarnings[] = lemonfacturx_trans('LemonFacturXWarnCreditNoteNoSource');
	}

	return $xml;
}

/**
 * Renvoie les seules lignes facturables (qty != 0 OU total_ht != 0).
 * Filtre les descriptions, titres et sous-totaux qui ne portent ni quantité
 * ni montant et ne doivent pas apparaître dans le XML EN16931.
 *
 * @param array $lines Lignes Dolibarr
 * @return array
 */
function lemonfacturx_filter_billable_lines($lines)
{
	$out = [];
	foreach ((array) $lines as $line) {
		if ((float) $line->qty == 0 && (float) $line->total_ht == 0) {
			continue;
		}
		$out[] = $line;
	}
	return $out;
}

/**
 * Prépare les lignes pour le XML : normalise le signe (avoirs), arrondit les
 * totaux, et requalifie les lignes à montant négatif (remises fixes Dolibarr)
 * en remises document BG-21 pour respecter BR-27.
 *
 * Si AUCUNE ligne positive ne subsiste (facture 380 entièrement négative,
 * cas hors convention), les lignes sont conservées telles quelles : le XML
 * violera BR-27 mais le contrôle interne des règles métier le signalera.
 *
 * @return array ['lines' => array, 'allowances' => array]
 */
function lemonfacturx_prepare_lines($billableLines, $sign, $invoice, $thirdparty, $mysoc, &$buildWarnings)
{
	// Constructeur unique de ligne préparée : utilisé par le chemin nominal ET
	// le fallback "tout négatif" pour que les deux émettent le même mapping.
	$buildLine = function ($line) use ($sign, $invoice, $thirdparty, $mysoc) {
		$desc = trim(strip_tags($line->desc ?: ($line->description ?? $line->label ?? '')));
		if ($desc === '') {
			$desc = 'Article';
		}
		$lineTotal = round($sign * (float) $line->total_ht, 2);
		$qty = abs((float) $line->qty);
		return [
			'desc'      => $desc,
			'qty'       => ($qty != 0.0) ? $qty : 1.0,
			'unitPrice' => ($qty != 0.0) ? $lineTotal / $qty : $lineTotal,
			'lineTotal' => $lineTotal,
			'lineTax'   => $sign * (float) $line->total_tva,
			'vatRate'   => (float) $line->tva_tx,
			'taxCat'    => lemonfacturx_resolve_tax_category($line, $invoice, $thirdparty, $mysoc),
			'unitCode'  => lemonfacturx_map_unit_code($line),
		];
	};

	$lines = [];
	$allowances = [];
	foreach ($billableLines as $line) {
		$pl = $buildLine($line);
		if ($pl['lineTotal'] < 0) {
			$allowances[] = [
				'amount'       => abs($pl['lineTotal']),
				'tax'          => $pl['lineTax'],
				'reason'       => $pl['desc'],
				'rate'         => $pl['vatRate'],
				'categoryCode' => $pl['taxCat']['code'],
				'taxCat'       => $pl['taxCat'],
			];
			continue;
		}
		$lines[] = $pl;
	}

	if (empty($lines) && !empty($allowances)) {
		// Facture intégralement négative hors avoir : pas de conversion BG-21
		// possible (il faut au moins une ligne). On ré-émet les lignes brutes.
		$buildWarnings[] = lemonfacturx_trans('LemonFacturXWarnAllNegativeLines');
		$lines = array_map($buildLine, $billableLines);
		$allowances = [];
	}

	return ['lines' => $lines, 'allowances' => $allowances];
}

/**
 * Récupère IBAN/BIC depuis le compte bancaire configuré dans le module.
 *
 * @param object $db Handle DB Dolibarr
 * @return array ['iban' => string, 'bic' => string] (chaînes vides si non configuré)
 */
function lemonfacturx_get_bank_account($db)
{
	$bankAccountId = getDolGlobalInt('LEMONFACTURX_BANK_ACCOUNT');
	if ($bankAccountId <= 0) {
		return ['iban' => '', 'bic' => ''];
	}
	require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
	$bankAccount = new Account($db);
	if ($bankAccount->fetch($bankAccountId) <= 0) {
		return ['iban' => '', 'bic' => ''];
	}
	return [
		'iban' => str_replace(' ', '', (string) $bankAccount->iban),
		'bic'  => str_replace(' ', '', (string) $bankAccount->bic),
	];
}

/**
 * Informations de prélèvement SEPA (moyen de paiement 59) :
 * - ICS créancier (BT-90) depuis la constante Dolibarr PRELEVEMENT_ICS
 * - RUM du mandat (BT-89) et IBAN débiteur (BT-91) depuis le RIB par défaut du tiers
 *
 * @return array ['ics' => string, 'rum' => string, 'debtor_iban' => string]
 */
function lemonfacturx_get_direct_debit_info($db, $buyer, &$buildWarnings)
{
	$info = [
		'ics'         => trim(getDolGlobalString('PRELEVEMENT_ICS', '')),
		'rum'         => '',
		'debtor_iban' => '',
	];

	if (!empty($buyer->id)) {
		// type = 'ban' : llx_societe_rib stocke aussi les modes de paiement
		// carte (Stripe...) avec default_rib = 1 — il ne faut pas les lire.
		$sql = "SELECT rum, iban_prefix FROM ".MAIN_DB_PREFIX."societe_rib"
			." WHERE fk_soc = ".((int) $buyer->id)." AND default_rib = 1"
			." AND (type = 'ban' OR type IS NULL OR type = '')"
			." ORDER BY rowid ASC LIMIT 1";
		$res = $db->query($sql);
		if ($res) {
			$obj = $db->fetch_object($res);
			if ($obj) {
				$info['rum'] = trim((string) $obj->rum);
				$info['debtor_iban'] = str_replace(' ', '', (string) $obj->iban_prefix);
			}
		}
	}

	if ($info['ics'] === '') {
		$buildWarnings[] = lemonfacturx_trans('LemonFacturXWarnDirectDebitNoICS');
	}
	if ($info['rum'] === '') {
		$buildWarnings[] = lemonfacturx_trans('LemonFacturXWarnDirectDebitNoRUM');
	}

	return $info;
}

/**
 * Retourne la valeur d'une constante Dolibarr, ou le défaut fourni si elle est
 * absente OU vide. getDolGlobalString() ne retombe sur le défaut que lorsque la
 * clé n'existe pas ; or nos constantes de configuration sont créées vides à
 * l'activation du module, donc le défaut ne s'appliquait jamais sans ce garde-fou.
 */
function lemonfacturx_conf_or_default($key, $default)
{
	$val = trim(getDolGlobalString($key, ''));
	return $val !== '' ? $val : $default;
}

/**
 * Génère les 3 IncludedNote BR-FR-05 (PMD, PMT, AAB) avec les valeurs
 * surchargeables via constantes Dolibarr (défaut appliqué si la constante est vide).
 */
function lemonfacturx_build_legal_notes_xml()
{
	$notes = [
		'PMD' => lemonfacturx_conf_or_default('LEMONFACTURX_NOTE_PMD', LEMONFACTURX_DEFAULT_NOTE_PMD),
		'PMT' => lemonfacturx_conf_or_default('LEMONFACTURX_NOTE_PMT', LEMONFACTURX_DEFAULT_NOTE_PMT),
		'AAB' => lemonfacturx_conf_or_default('LEMONFACTURX_NOTE_AAB', LEMONFACTURX_DEFAULT_NOTE_AAB),
	];
	$xml = '';
	foreach ($notes as $code => $content) {
		$xml .= '  <ram:IncludedNote>'."\n";
		$xml .= '    <ram:Content>'.lemonfacturx_xml_encode($content).'</ram:Content>'."\n";
		$xml .= '    <ram:SubjectCode>'.$code.'</ram:SubjectCode>'."\n";
		$xml .= '  </ram:IncludedNote>'."\n";
	}
	return $xml;
}

/**
 * BG-14 : période de facturation déduite des dates de service des lignes
 * (min des date_start / max des date_end). Bloc omis si aucune ligne datée.
 */
function lemonfacturx_build_billing_period_xml($billableLines)
{
	$start = null;
	$end = null;
	foreach ($billableLines as $line) {
		if (!empty($line->date_start) && ($start === null || $line->date_start < $start)) {
			$start = $line->date_start;
		}
		if (!empty($line->date_end) && ($end === null || $line->date_end > $end)) {
			$end = $line->date_end;
		}
	}
	if ($start === null && $end === null) {
		return '';
	}

	$xml = '    <ram:BillingSpecifiedPeriod>'."\n";
	if ($start !== null) {
		$xml .= '      <ram:StartDateTime>'."\n";
		$xml .= '        <udt:DateTimeString format="102">'.date('Ymd', $start).'</udt:DateTimeString>'."\n";
		$xml .= '      </ram:StartDateTime>'."\n";
	}
	if ($end !== null) {
		$xml .= '      <ram:EndDateTime>'."\n";
		$xml .= '        <udt:DateTimeString format="102">'.date('Ymd', $end).'</udt:DateTimeString>'."\n";
		$xml .= '      </ram:EndDateTime>'."\n";
	}
	$xml .= '    </ram:BillingSpecifiedPeriod>'."\n";

	return $xml;
}

/**
 * Génère le XML d'une ligne préparée (cf. lemonfacturx_prepare_lines).
 *
 * @param array  $pl       Ligne préparée (desc, qty, unitPrice, lineTotal, vatRate, taxCat, unitCode)
 * @param int    $lineNum  Numéro de ligne séquentiel
 * @return string XML
 */
function lemonfacturx_build_line_xml($pl, $lineNum)
{
	$xml  = '  <ram:IncludedSupplyChainTradeLineItem>'."\n";
	$xml .= '    <ram:AssociatedDocumentLineDocument>'."\n";
	$xml .= '      <ram:LineID>'.lemonfacturx_xml_encode((string) $lineNum).'</ram:LineID>'."\n";
	$xml .= '    </ram:AssociatedDocumentLineDocument>'."\n";
	$xml .= '    <ram:SpecifiedTradeProduct>'."\n";
	$xml .= '      <ram:Name>'.lemonfacturx_xml_encode($pl['desc']).'</ram:Name>'."\n";
	$xml .= '    </ram:SpecifiedTradeProduct>'."\n";
	$xml .= '    <ram:SpecifiedLineTradeAgreement>'."\n";
	$xml .= '      <ram:NetPriceProductTradePrice>'."\n";
	// BT-146 : 4 décimales pour limiter l'écart qty x prix vs total de ligne
	$xml .= '        <ram:ChargeAmount>'.lemonfacturx_format_unit_price($pl['unitPrice']).'</ram:ChargeAmount>'."\n";
	$xml .= '      </ram:NetPriceProductTradePrice>'."\n";
	$xml .= '    </ram:SpecifiedLineTradeAgreement>'."\n";
	$xml .= '    <ram:SpecifiedLineTradeDelivery>'."\n";
	$xml .= '      <ram:BilledQuantity unitCode="'.lemonfacturx_xml_encode($pl['unitCode']).'">'.lemonfacturx_format_qty($pl['qty']).'</ram:BilledQuantity>'."\n";
	$xml .= '    </ram:SpecifiedLineTradeDelivery>'."\n";
	$xml .= '    <ram:SpecifiedLineTradeSettlement>'."\n";
	$xml .= '      <ram:ApplicableTradeTax>'."\n";
	$xml .= '        <ram:TypeCode>VAT</ram:TypeCode>'."\n";
	$xml .= '        <ram:CategoryCode>'.lemonfacturx_xml_encode($pl['taxCat']['code']).'</ram:CategoryCode>'."\n";
	// BR-O-04 : pas de RateApplicablePercent pour CategoryCode='O' (services hors champ)
	if ($pl['taxCat']['code'] !== 'O') {
		$xml .= '        <ram:RateApplicablePercent>'.lemonfacturx_format_amount($pl['vatRate']).'</ram:RateApplicablePercent>'."\n";
	}
	$xml .= '      </ram:ApplicableTradeTax>'."\n";
	$xml .= '      <ram:SpecifiedTradeSettlementLineMonetarySummation>'."\n";
	$xml .= '        <ram:LineTotalAmount>'.lemonfacturx_format_amount($pl['lineTotal']).'</ram:LineTotalAmount>'."\n";
	$xml .= '      </ram:SpecifiedTradeSettlementLineMonetarySummation>'."\n";
	$xml .= '    </ram:SpecifiedLineTradeSettlement>'."\n";
	$xml .= '  </ram:IncludedSupplyChainTradeLineItem>'."\n";

	return $xml;
}

/**
 * Calcule la ventilation TVA par (catégorie, taux) — deux catégories distinctes
 * au même taux (ex. K et AE à 0 %) produisent deux blocs ApplicableTradeTax.
 *
 * Consomme les lignes/remises préparées par lemonfacturx_prepare_lines() (mêmes
 * catégories, mêmes arrondis) pour garantir la cohérence BR-S-08 : base par
 * (catégorie, taux) = somme des lignes - remises de la catégorie.
 *
 * Réconciliation des taxes, dans l'ordre de priorité :
 *  1. sum(tax) == total_tva facture (écart d'arrondi imputé au plus gros groupe)
 *  2. BR-CO-17 : chaque groupe doit rester à +/-0.01 de base x taux ; sinon la
 *     taxe du groupe est recalculée sur la base et un avertissement est émis
 *     (l'écart résiduel avec le TTC Dolibarr est signalé par build_xml).
 *
 * @param array  $prepared       ['lines' => [...], 'allowances' => [...]] (cf. prepare_lines)
 * @param object $invoice        Facture Dolibarr
 * @param float  $sign           1.0 ou -1.0 (avoir)
 * @param array  $buildWarnings  (sortie) avertissements
 * @return array<string,array>   Indexé par "categorie|taux", avec base/tax/rate/categoryCode/exemption/vatex
 */
function lemonfacturx_get_tax_breakdown($prepared, $invoice, $sign = 1.0, &$buildWarnings = [])
{
	$breakdown = [];
	$accumulate = function ($taxCat, $rate, $base, $tax) use (&$breakdown) {
		$key = $taxCat['code'].'|'.(float) $rate;
		if (!isset($breakdown[$key])) {
			$breakdown[$key] = [
				'base'         => 0.0,
				'tax'          => 0.0,
				'rate'         => (float) $rate,
				'categoryCode' => $taxCat['code'],
				'exemption'    => $taxCat['exemption'],
				'vatex'        => $taxCat['vatex'],
			];
		}
		$breakdown[$key]['base'] += $base;
		$breakdown[$key]['tax'] += $tax;
	};

	foreach ($prepared['lines'] as $pl) {
		$accumulate($pl['taxCat'], $pl['vatRate'], $pl['lineTotal'], $pl['lineTax']);
	}
	foreach ($prepared['allowances'] as $al) {
		$accumulate($al['taxCat'], $al['rate'], -$al['amount'], $al['tax']);
	}

	// Arrondi des taxes par groupe puis réconciliation avec le total facture
	$sumTax = 0.0;
	foreach ($breakdown as $key => $b) {
		$breakdown[$key]['base'] = round($b['base'], 2);
		$breakdown[$key]['tax'] = round($b['tax'], 2);
		$sumTax += $breakdown[$key]['tax'];
	}
	$invoiceTax = round($sign * (float) $invoice->total_tva, 2);
	$delta = round($invoiceTax - $sumTax, 2);
	if (abs($delta) >= 0.01) {
		// Imputer l'écart d'arrondi sur le groupe taxé à la plus grande base
		$targetKey = null;
		$targetBase = -1.0;
		foreach ($breakdown as $key => $b) {
			if ($b['rate'] > 0 && abs($b['base']) > $targetBase) {
				$targetBase = abs($b['base']);
				$targetKey = $key;
			}
		}
		if ($targetKey !== null) {
			$breakdown[$targetKey]['tax'] = round($breakdown[$targetKey]['tax'] + $delta, 2);
			if (abs($delta) > 0.01) {
				$buildWarnings[] = lemonfacturx_trans('LemonFacturXWarnRoundingAdjusted', lemonfacturx_format_amount($delta));
			}
		} else {
			$buildWarnings[] = lemonfacturx_trans('LemonFacturXWarnTaxMismatch', lemonfacturx_format_amount($delta));
		}
	}

	// Garde-fou BR-CO-17 : la taxe d'un groupe ne doit pas s'écarter de plus
	// d'un centime de base x taux (rejet Schematron sinon). Si l'imputation
	// ci-dessus (ou des arrondis ligne à ligne cumulés) dépasse la tolérance,
	// on recale la taxe du groupe sur sa base — la priorité va à la validité
	// EN16931 du XML, l'écart avec les totaux Dolibarr est signalé en aval.
	foreach ($breakdown as $key => $b) {
		if ($b['rate'] <= 0) {
			continue;
		}
		$expected = round($b['base'] * $b['rate'] / 100, 2);
		if (abs($b['tax'] - $expected) > 0.01) {
			$buildWarnings[] = lemonfacturx_trans('LemonFacturXWarnTaxRecomputed', $b['categoryCode'].' '.$b['rate'].'%', lemonfacturx_format_amount($expected), lemonfacturx_format_amount($b['tax']));
			$breakdown[$key]['tax'] = $expected;
		}
	}

	return $breakdown;
}

/**
 * Vrai si le tiers est hors champ de l'e-invoicing B2B (réforme 2026-2027) :
 * particulier (typent TE_PRIVATE) OU non assujetti à la TVA (champ « Assujetti
 * à la TVA » de la fiche tiers). Ces tiers relèvent de l'e-reporting, pas de
 * la facturation électronique entre assujettis.
 *
 * Le typent seul ne suffit pas (une association non assujettie n'est pas un
 * « Particulier ») et le tva_assuj seul non plus (souvent laissé par défaut) :
 * on combine les deux. Même critère que lemonsuperpdp_is_non_assujetti(),
 * dupliqué ici pour ne pas dépendre de LemonSuperPDP.
 *
 * @param Societe|null $thirdparty  Tiers Dolibarr (fetch déjà fait)
 * @return bool
 */
function lemonfacturx_is_non_assujetti($thirdparty)
{
	if (empty($thirdparty)) {
		return false;
	}
	if (($thirdparty->typent_code ?? '') === 'TE_PRIVATE' || (int) ($thirdparty->typent_id ?? 0) === 8) {
		return true;
	}
	return isset($thirdparty->tva_assuj) && (int) $thirdparty->tva_assuj === 0;
}

/**
 * Vérifie les infos obligatoires pour Factur-X EN16931 + BR-FR
 * Retourne un tableau de warnings (vide si tout est OK)
 *
 * @param Facture $invoice   Objet facture Dolibarr
 * @param Societe $mysoc     Société émettrice
 * @return array             Liste de messages d'avertissement
 */
function lemonfacturx_check_mandatory($invoice, $mysoc)
{
	$warnings = [];

	$sellerChecks = [
		'name'      => 'LemonFacturXWarnSellerName',
		'address'   => 'LemonFacturXWarnSellerAddress',
		'zip'       => 'LemonFacturXWarnSellerZip',
		'town'      => 'LemonFacturXWarnSellerTown',
		'tva_intra' => 'LemonFacturXWarnSellerVAT',
		'idprof2'   => 'LemonFacturXWarnSellerSIRET',
	];
	$isFranchise = isset($mysoc->tva_assuj) && (int) $mysoc->tva_assuj === 0;
	foreach ($sellerChecks as $field => $transKey) {
		// Franchise en base (293 B CGI) : pas de TVA intra → ne pas warn
		if ($field === 'tva_intra' && $isFranchise) {
			continue;
		}
		if (empty($mysoc->$field)) {
			$warnings[] = lemonfacturx_trans($transKey);
		}
	}

	// SIREN/SIRET : identifiants français — mêmes règles de pays que le générateur
	$sellerIsFR = strtoupper(!empty($mysoc->country_code) ? $mysoc->country_code : 'FR') === 'FR';

	// BT-34 (adresse électronique vendeur) : satisfaite par le SIREN (endpoint 0225)
	// OU l'email. On n'avertit que si aucune des deux n'est disponible.
	$sellerSiren = $sellerIsFR ? lemonfacturx_party_siren($mysoc) : '';
	if ($sellerSiren === '' && empty($mysoc->email)) {
		$warnings[] = lemonfacturx_trans('LemonFacturXWarnSellerEndpoint');
	}

	// BT-29 (ram:ID) : si un SIRET est renseigné (idprof2), il doit faire 14
	// chiffres. Il identifie l'établissement ; le SIREN (BT-30) vient d'idprof1.
	$sellerSiret = $sellerIsFR ? preg_replace('/[^0-9]/', '', $mysoc->idprof2 ?? '') : '';
	if ($sellerSiret !== '' && strlen($sellerSiret) !== 14) {
		$warnings[] = lemonfacturx_trans('LemonFacturXWarnSellerSIRETLen', strlen($sellerSiret));
	}

	$buyer = $invoice->thirdparty;
	$buyerChecks = [
		'name'    => 'LemonFacturXWarnBuyerName',
		'address' => 'LemonFacturXWarnBuyerAddress',
		'zip'     => 'LemonFacturXWarnBuyerZip',
		'town'    => 'LemonFacturXWarnBuyerTown',
	];
	foreach ($buyerChecks as $field => $transKey) {
		if (empty($buyer->$field)) {
			$warnings[] = lemonfacturx_trans($transKey);
		}
	}

	// BT-49 (adresse électronique acheteur) : satisfaite par le SIREN (endpoint 0225)
	// OU l'email. Pour un acheteur FR, l'absence de SIREN empêche le routage PA/PDP,
	// même si un email est présent (un email n'est pas routable sur le réseau).
	$buyerIsFR  = strtoupper(!empty($buyer->country_code) ? $buyer->country_code : 'FR') === 'FR';
	$buyerSiren = $buyerIsFR ? lemonfacturx_party_siren($buyer) : '';
	$buyerEmail = lemonfacturx_get_buyer_email($buyer, $invoice->db);
	if ($buyerSiren === '' && $buyerEmail === '') {
		$warnings[] = lemonfacturx_trans('LemonFacturXWarnBuyerEndpoint');
	} elseif ($buyerSiren === '' && $buyerIsFR && !lemonfacturx_is_non_assujetti($buyer)) {
		// Non assujetti (particulier OU « Assujetti à la TVA » à Non, ex. asso) :
		// pas de SIREN, hors champ e-invoicing (relève du e-reporting)
		// → pas d'avertissement de routage
		$warnings[] = lemonfacturx_trans('LemonFacturXWarnBuyerSIRENRouting');
	}

	// BT-46 acheteur (ram:ID) : si un SIRET est renseigné, il doit faire 14 chiffres.
	$buyerSiret = $buyerIsFR ? preg_replace('/[^0-9]/', '', $buyer->idprof2 ?? '') : '';
	if ($buyerSiret !== '' && strlen($buyerSiret) !== 14) {
		$warnings[] = lemonfacturx_trans('LemonFacturXWarnBuyerSIRETLen', strlen($buyerSiret));
	}

	if (getDolGlobalInt('LEMONFACTURX_BANK_ACCOUNT') <= 0) {
		$warnings[] = lemonfacturx_trans('LemonFacturXWarnNoBank');
	}

	// PDF/A-3 : sans police embarquée forcée, le PDF TCPDF utilise les polices
	// base-14 non embarquées et échoue à la validation veraPDF.
	if (function_exists('getDolGlobalString') && getDolGlobalString('MAIN_PDF_FORCE_FONT', '') === '') {
		$warnings[] = lemonfacturx_trans('LemonFacturXWarnNoForceFont');
	}

	return $warnings;
}

/**
 * Génère le bloc XML d'un TradeParty (vendeur ou acheteur).
 *
 * @param string $role   'Seller' ou 'Buyer'
 * @param object $party  Société émettrice (mysoc) ou Societe acheteur
 * @param string $email  Email à publier dans le bloc URI (BT-49 / BT-34)
 */
function lemonfacturx_build_trade_party_xml($role, $party, $email, $legalIdMode = 'siren')
{
	$tag = ($role === 'Seller') ? 'SellerTradeParty' : 'BuyerTradeParty';
	$country = !empty($party->country_code) ? $party->country_code : 'FR';
	$vat     = $party->tva_intra ?? '';
	// SIREN/SIRET : identifiants français uniquement. Pour un tiers étranger,
	// idprof2 contient un identifiant local (HRB allemand, CRN...) qui ne doit
	// surtout pas être publié sous un scheme SIREN/SIRET (0002/0009/0225) —
	// l'endpoint retombe alors sur l'email (EM).
	// EN16931 CII : deux champs DISTINCTS, chacun son identifiant (ISO 6523).
	//  - BT-29/BT-46  ram:ID                        = SIRET (14 chiffres), schemeID 0009  → l'établissement
	//  - BT-30/BT-47  SpecifiedLegalOrganization/ID = SIREN (9 chiffres),  schemeID 0002  → l'entité légale
	// L'ordre suit la séquence XSD du TradePartyType : ID, Name, SpecifiedLegalOrganization.
	// Source SIREN = idprof1 (à défaut dérivé du SIRET) ; source SIRET = idprof2.
	$isFR = (strtoupper($country) === 'FR');
	$siren = $isFR ? lemonfacturx_party_siren($party) : '';
	$siretDigits = $isFR ? preg_replace('/[^0-9]/', '', $party->idprof2 ?? '') : '';
	$siret14 = (strlen($siretDigits) === 14) ? $siretDigits : '';

	// Deux profils selon la destination (cf. réforme FR : deux réseaux distincts).
	//  - 'siren' (défaut, PDP/B2B) : SIRET en BT-29 (ram:ID/0009) + SIREN en BT-30
	//    (SpecifiedLegalOrganization/0002). Conforme norme EN16931 / règle BR-FR-10.
	//  - 'siret' (Chorus Pro/B2G) : SIRET-14 en BT-30 (SpecifiedLegalOrganization/0009),
	//    qui est la clé de routage de l'annuaire Chorus Pro (il ne lit QUE ce champ).
	$chorus = ($legalIdMode === 'siret');

	$xml  = '    <ram:'.$tag.'>'."\n";
	if (!$chorus && !empty($siret14)) {
		$xml .= '      <ram:ID schemeID="0009">'.lemonfacturx_xml_encode($siret14).'</ram:ID>'."\n";
	}
	$xml .= '      <ram:Name>'.lemonfacturx_xml_encode($party->name ?? '').'</ram:Name>'."\n";
	if ($chorus && !empty($siret14)) {
		$xml .= '      <ram:SpecifiedLegalOrganization>'."\n";
		$xml .= '        <ram:ID schemeID="0009">'.lemonfacturx_xml_encode($siret14).'</ram:ID>'."\n";
		$xml .= '      </ram:SpecifiedLegalOrganization>'."\n";
	} elseif (!empty($siren)) {
		$xml .= '      <ram:SpecifiedLegalOrganization>'."\n";
		$xml .= '        <ram:ID schemeID="0002">'.lemonfacturx_xml_encode($siren).'</ram:ID>'."\n";
		$xml .= '      </ram:SpecifiedLegalOrganization>'."\n";
	}
	$xml .= '      <ram:PostalTradeAddress>'."\n";
	$xml .= '        <ram:PostcodeCode>'.lemonfacturx_xml_encode($party->zip ?? '').'</ram:PostcodeCode>'."\n";
	$xml .= '        <ram:LineOne>'.lemonfacturx_xml_encode($party->address ?? '').'</ram:LineOne>'."\n";
	$xml .= '        <ram:CityName>'.lemonfacturx_xml_encode($party->town ?? '').'</ram:CityName>'."\n";
	$xml .= '        <ram:CountryID>'.lemonfacturx_xml_encode($country).'</ram:CountryID>'."\n";
	$xml .= '      </ram:PostalTradeAddress>'."\n";
	// BT-34 (vendeur) / BT-49 (acheteur) : adresse électronique de routage.
	// Le réseau des Plateformes Agréées (réforme FR) route par SIREN ; l'endpoint
	// porte donc le SIREN avec schemeID="0225" (annuaire PPF), pas l'email.
	$xml .= lemonfacturx_build_endpoint_uri($siren, $email);
	if (!empty($vat)) {
		$xml .= '      <ram:SpecifiedTaxRegistration>'."\n";
		$xml .= '        <ram:ID schemeID="VA">'.lemonfacturx_xml_encode($vat).'</ram:ID>'."\n";
		$xml .= '      </ram:SpecifiedTaxRegistration>'."\n";
	} elseif ($role === 'Seller' && !empty($siren)) {
		// BR-CO-26 / BR-E-09 : le Seller doit publier un identifiant fiscal
		// (BT-31 TVA intra OU BT-32 identifiant fiscal). En l'absence de TVA
		// intra (franchise en base 293 B CGI typiquement), on émet le SIREN
		// comme tax registration schemeID="FC" (Tax registration identifier
		// France) pour satisfaire la règle.
		$xml .= '      <ram:SpecifiedTaxRegistration>'."\n";
		$xml .= '        <ram:ID schemeID="FC">'.lemonfacturx_xml_encode($siren).'</ram:ID>'."\n";
		$xml .= '      </ram:SpecifiedTaxRegistration>'."\n";
	}
	$xml .= '    </ram:'.$tag.'>'."\n";

	return $xml;
}

/**
 * Construit l'endpoint d'adressage électronique (BT-34 vendeur / BT-49 acheteur).
 *
 * Le réseau des Plateformes Agréées (réforme française) route les factures par
 * SIREN : l'adresse doit porter le SIREN avec schemeID="0225" (annuaire PPF,
 * XP Z12-012). Surchargeable via LEMONFACTURX_ENDPOINT_SCHEME pour une PA qui
 * attendrait un autre code ISO 6523 (0002 SIREN / 0009 SIRET). Sans SIREN (tiers
 * étranger hors périmètre), repli sur l'email (schemeID="EM"). Renvoie une chaîne
 * vide si aucune adresse n'est disponible, pour ne pas émettre de bloc vide.
 *
 * @param string $siren SIREN 9 chiffres (vide si absent)
 * @param string $email Email de repli
 * @return string Bloc <ram:URIUniversalCommunication> ou chaîne vide
 */
function lemonfacturx_build_endpoint_uri($siren, $email)
{
	if (!empty($siren)) {
		$scheme = getDolGlobalString('LEMONFACTURX_ENDPOINT_SCHEME', '0225');
		$value  = $siren;
	} elseif (!empty($email)) {
		$scheme = 'EM';
		$value  = $email;
	} else {
		return '';
	}

	$xml  = '      <ram:URIUniversalCommunication>'."\n";
	$xml .= '        <ram:URIID schemeID="'.lemonfacturx_xml_encode($scheme).'">'.lemonfacturx_xml_encode($value).'</ram:URIID>'."\n";
	$xml .= '      </ram:URIUniversalCommunication>'."\n";

	return $xml;
}

/**
 * Mappe l'unité Dolibarr d'une ligne vers le code UN/ECE Rec 20 attendu par Factur-X.
 * Cherche via $line->fk_unit dans llx_c_units, fallback C62 (pièce / one) si absent.
 *
 * @param object $line Ligne de facture Dolibarr
 * @return string Code UN/ECE (ex: HUR, DAY, MTR, KGM, C62...)
 */
function lemonfacturx_map_unit_code($line)
{
	static $unitCache = [];
	static $shortLabelMap = [
		'h'      => 'HUR', // heure
		'min'    => 'MIN', // minute
		'd'      => 'DAY', // jour
		'week'   => 'WEE', // semaine
		'wk'     => 'WEE',
		'month'  => 'MON', // mois
		'm'      => 'MTR', // mètre (conflit avec min/month résolu par unit_type)
		'cm'     => 'CMT',
		'mm'     => 'MMT',
		'km'     => 'KMT',
		'm2'     => 'MTK', // mètre carré
		'm3'     => 'MTQ', // mètre cube
		'kg'     => 'KGM',
		'g'      => 'GRM',
		't'      => 'TNE', // tonne métrique
		'l'      => 'LTR',
		'cl'     => 'CLT',
		'ml'     => 'MLT',
		'p'      => 'C62', // pièce
		'pc'     => 'C62',
		'pcs'    => 'C62',
		'piece'  => 'C62',
		'u'      => 'C62', // unité
	];

	$fkUnit = !empty($line->fk_unit) ? (int) $line->fk_unit : 0;
	if ($fkUnit <= 0) {
		return 'C62';
	}
	if (isset($unitCache[$fkUnit])) {
		return $unitCache[$fkUnit];
	}

	global $db;
	if (!is_object($db)) {
		return 'C62';
	}
	$sql = "SELECT short_label, unit_type FROM ".MAIN_DB_PREFIX."c_units WHERE rowid = ".$fkUnit;
	$res = $db->query($sql);
	if (!$res) {
		return $unitCache[$fkUnit] = 'C62';
	}
	$obj = $db->fetch_object($res);
	if (!$obj || empty($obj->short_label)) {
		return $unitCache[$fkUnit] = 'C62';
	}

	$code = strtolower(trim($obj->short_label));
	// Désambiguïser 'm' : time=minute (MIN), size=mètre (MTR)
	if ($code === 'm') {
		return $unitCache[$fkUnit] = ($obj->unit_type === 'time') ? 'MIN' : 'MTR';
	}
	return $unitCache[$fkUnit] = ($shortLabelMap[$code] ?? 'C62');
}

/**
 * Résout la catégorie TVA EN16931 (CategoryCode + code VATEX) selon le contexte métier.
 *
 * Intracommunautaire B2B (acheteur UE hors FR avec TVA intra, taux 0) :
 *  - biens (product_type 0)    → K  (livraison intracommunautaire, art. 138 dir. 2006/112/CE)
 *  - services (product_type 1) → AE (autoliquidation, art. 196 dir. 2006/112/CE)
 *
 * @param object $line        Ligne de facture
 * @param object $invoice     Facture Dolibarr
 * @param object $thirdparty  Tiers acheteur
 * @param object $mysoc       Société émettrice
 * @return array ['code' => 'S|K|AE|G|O|E|Z', 'exemption' => string|null, 'vatex' => string|null]
 */
function lemonfacturx_resolve_tax_category($line, $invoice, $thirdparty, $mysoc)
{
	// Société émettrice non assujettie (franchise en base 293 B CGI, micro-entreprise) :
	// catégorie E (Exempt from tax). Le code 'O' (Services hors champ) déclencherait
	// BR-O-04/05 sur le taux 0 et n'est sémantiquement pas le bon (293 B = exonération
	// française, pas une opération hors champ EU). BR-E-09 demande un identifiant
	// fiscal vendeur : assuré par SpecifiedTaxRegistration schemeID="FC" (SIREN) dans
	// lemonfacturx_build_trade_party_xml() quand tva_intra est vide.
	// TVA > 0 : standard — prioritaire sur le statut franchise, car une ligne
	// qui porte réellement de la TVA (ex. ancienne facture régénérée après un
	// passage en franchise) en catégorie E violerait BR-E-05 (taux non nul).
	if ((float) $line->tva_tx > 0) {
		return ['code' => 'S', 'exemption' => null, 'vatex' => null];
	}

	if (isset($mysoc->tva_assuj) && (int) $mysoc->tva_assuj === 0) {
		return ['code' => 'E', 'exemption' => 'TVA non applicable, art. 293 B du CGI', 'vatex' => 'VATEX-FR-FRANCHISE'];
	}

	// TVA = 0 : qualifier selon le contexte
	$buyerCountry = strtoupper(!empty($thirdparty->country_code) ? $thirdparty->country_code : 'FR');
	$buyerVat     = !empty($thirdparty->tva_intra) ? $thirdparty->tva_intra : '';

	// Export hors UE : G
	if (!in_array($buyerCountry, LEMONFACTURX_EU_COUNTRIES, true)) {
		return ['code' => 'G', 'exemption' => 'Export hors Union européenne (art. 262 I du CGI)', 'vatex' => 'VATEX-EU-G'];
	}

	// UE hors FR avec TVA intra : K (biens) ou AE (services, art. 196)
	if ($buyerCountry !== 'FR' && !empty($buyerVat)) {
		if ((int) ($line->product_type ?? 0) === 1) {
			return ['code' => 'AE', 'exemption' => 'Autoliquidation — TVA due par le preneur (art. 196, directive 2006/112/CE)', 'vatex' => 'VATEX-EU-AE'];
		}
		return ['code' => 'K', 'exemption' => 'Livraison intracommunautaire exonérée — TVA due par le preneur (art. 138, directive 2006/112/CE)', 'vatex' => 'VATEX-EU-IC'];
	}

	// FR ou UE sans TVA intra et TVA=0 : exonération par défaut.
	// Pas de code VATEX émis : impossible de deviner la base légale (296ter,
	// 261-4 formation, etc.) — renseigner un motif explicite via la description.
	return ['code' => 'E', 'exemption' => 'Exonéré de TVA', 'vatex' => null];
}

/**
 * Résout le TypeCode documentaire EN16931 selon le type de facture Dolibarr.
 *
 * Types Dolibarr non couverts par un TypeCode dédié :
 *  - TYPE_SITUATION (5) : émis en 380 avec un avertissement — le mapping des
 *    lignes de situation (cumuls, retenues de garantie) n'est pas garanti.
 *  - TYPE_PROFORMA (4) : une proforma n'est pas une facture au sens EN16931.
 *
 * @param object $invoice        Facture Dolibarr
 * @param array  $buildWarnings  (sortie) avertissements
 * @return string '380' (standard), '381' (avoir), '384' (rectificative), '386' (acompte)
 */
function lemonfacturx_resolve_document_type($invoice, &$buildWarnings = [])
{
	$type = (int) $invoice->type;

	switch ($type) {
		case 1: // Facture::TYPE_REPLACEMENT
			return '384'; // Corrected invoice + BG-3 vers la facture remplacée
		case 2: // Facture::TYPE_CREDIT_NOTE
			return '381';
		case 3: // Facture::TYPE_DEPOSIT
			return '386'; // EN16931 : prepayment / advance invoice
		case 5: // Facture::TYPE_SITUATION
			$buildWarnings[] = lemonfacturx_trans('LemonFacturXWarnSituationInvoice');
			return '380';
		default:
			return '380';
	}
}

/**
 * Retourne le montant total déjà prépayé via acomptes imputés sur la facture finale.
 *
 * @param object $invoice Facture Dolibarr
 * @return float Montant prépayé ≥ 0
 */
function lemonfacturx_get_prepaid_amount($invoice)
{
	if (!method_exists($invoice, 'getSumDepositsUsed')) {
		return 0.0;
	}
	return max(0.0, (float) $invoice->getSumDepositsUsed());
}

/**
 * Liste les factures antérieures à référencer en BG-3 :
 *  - facture d'origine d'un avoir ou d'une rectificative (fk_facture_source)
 *  - factures d'acompte imputées sur la facture (llx_societe_remise_except)
 *
 * @param object $invoice Facture Dolibarr
 * @return array Liste de ['ref' => string, 'date' => 'YYYYMMDD'|'']
 */
function lemonfacturx_get_preceding_invoices($invoice)
{
	$out = [];
	$seen = [];
	$db = $invoice->db ?? null;
	if (!is_object($db)) {
		return $out;
	}

	$sourceIds = [];
	if (!empty($invoice->fk_facture_source)) {
		$sourceIds[] = (int) $invoice->fk_facture_source;
	}

	if (!empty($invoice->id)) {
		// Acomptes/avoirs consommés sur cette facture via remises exceptionnelles
		$sql = "SELECT DISTINCT fk_facture_source FROM ".MAIN_DB_PREFIX."societe_remise_except"
			." WHERE fk_facture = ".((int) $invoice->id)." AND fk_facture_source IS NOT NULL";
		$res = $db->query($sql);
		if ($res) {
			while ($obj = $db->fetch_object($res)) {
				$sourceIds[] = (int) $obj->fk_facture_source;
			}
		}
	}

	foreach (array_unique(array_filter($sourceIds)) as $fkSource) {
		if (isset($seen[$fkSource])) {
			continue;
		}
		$seen[$fkSource] = true;
		$sql = "SELECT ref, datef FROM ".MAIN_DB_PREFIX."facture WHERE rowid = ".((int) $fkSource);
		$res = $db->query($sql);
		if (!$res) {
			continue;
		}
		$obj = $db->fetch_object($res);
		if ($obj && !empty($obj->ref)) {
			$out[] = [
				'ref'  => $obj->ref,
				'date' => !empty($obj->datef) ? str_replace('-', '', substr($obj->datef, 0, 10)) : '',
			];
		}
	}

	return $out;
}

/**
 * Référence de la première commande client liée à la facture (BT-13),
 * via llx_element_element (sans charger les objets liés).
 *
 * @param object $invoice Facture Dolibarr
 * @return string Réf de commande ou chaîne vide
 */
function lemonfacturx_get_linked_order_ref($invoice)
{
	$db = $invoice->db ?? null;
	if (!is_object($db) || empty($invoice->id)) {
		return '';
	}
	$sql = "SELECT c.ref FROM ".MAIN_DB_PREFIX."element_element ee"
		." INNER JOIN ".MAIN_DB_PREFIX."commande c ON c.rowid = ee.fk_source"
		." WHERE ee.sourcetype = 'commande' AND ee.targettype = 'facture'"
		." AND ee.fk_target = ".((int) $invoice->id)
		." ORDER BY ee.rowid ASC LIMIT 1";
	$res = $db->query($sql);
	if (!$res) {
		return '';
	}
	$obj = $db->fetch_object($res);
	return ($obj && !empty($obj->ref)) ? (string) $obj->ref : '';
}

/**
 * Génère le bloc SpecifiedTradeSettlementHeaderMonetarySummation.
 *
 * Tous les montants sont calculés de bas en haut à partir des valeurs émises
 * (lignes arrondies, remises, ventilation TVA réconciliée) pour garantir les
 * règles de calcul BR-CO-10/11/13/14/15/16. Un écart avec le total_ttc Dolibarr
 * (taxes locales par ex.) est signalé en avertissement.
 */
function lemonfacturx_build_monetary_summation_xml($invoice, $currency, $sign, $prepared, $breakdown, &$buildWarnings)
{
	$lineTotal = 0.0;
	foreach ($prepared['lines'] as $pl) {
		$lineTotal += $pl['lineTotal'];
	}
	$lineTotal = round($lineTotal, 2);

	$allowanceTotal = 0.0;
	foreach ($prepared['allowances'] as $al) {
		$allowanceTotal += $al['amount'];
	}
	$allowanceTotal = round($allowanceTotal, 2);

	$taxBasisTotal = round($lineTotal - $allowanceTotal, 2); // BR-CO-13

	$taxTotal = 0.0;
	foreach ($breakdown as $b) {
		$taxTotal += $b['tax'];
	}
	$taxTotal = round($taxTotal, 2); // BR-CO-14

	$grandTotal   = round($taxBasisTotal + $taxTotal, 2); // BR-CO-15
	$totalPrepaid = lemonfacturx_get_prepaid_amount($invoice);
	$duePayable   = round($grandTotal - $totalPrepaid, 2); // BR-CO-16, sans écrêtage

	// Cohérence avec les totaux Dolibarr (PDF visible) : un écart signale des
	// données hors périmètre (taxes locales, incohérence de lignes).
	$invoiceTtc = round($sign * (float) $invoice->total_ttc, 2);
	if (abs($grandTotal - $invoiceTtc) > 0.005) {
		$buildWarnings[] = lemonfacturx_trans('LemonFacturXWarnTotalsMismatch', lemonfacturx_format_amount($grandTotal), lemonfacturx_format_amount($invoiceTtc));
	}

	$xml  = '    <ram:SpecifiedTradeSettlementHeaderMonetarySummation>'."\n";
	$xml .= '      <ram:LineTotalAmount>'.lemonfacturx_format_amount($lineTotal).'</ram:LineTotalAmount>'."\n";
	if ($allowanceTotal > 0) {
		$xml .= '      <ram:AllowanceTotalAmount>'.lemonfacturx_format_amount($allowanceTotal).'</ram:AllowanceTotalAmount>'."\n";
	}
	$xml .= '      <ram:TaxBasisTotalAmount>'.lemonfacturx_format_amount($taxBasisTotal).'</ram:TaxBasisTotalAmount>'."\n";
	$xml .= '      <ram:TaxTotalAmount currencyID="'.lemonfacturx_xml_encode($currency).'">'.lemonfacturx_format_amount($taxTotal).'</ram:TaxTotalAmount>'."\n";
	$xml .= '      <ram:GrandTotalAmount>'.lemonfacturx_format_amount($grandTotal).'</ram:GrandTotalAmount>'."\n";
	if ($totalPrepaid > 0) {
		$xml .= '      <ram:TotalPrepaidAmount>'.lemonfacturx_format_amount($totalPrepaid).'</ram:TotalPrepaidAmount>'."\n";
	}
	$xml .= '      <ram:DuePayableAmount>'.lemonfacturx_format_amount($duePayable).'</ram:DuePayableAmount>'."\n";
	$xml .= '    </ram:SpecifiedTradeSettlementHeaderMonetarySummation>'."\n";

	return $xml;
}

/**
 * Extrait le SIREN (9 premiers chiffres) d'un SIRET
 */
function lemonfacturx_extract_siren($siret)
{
	if (empty($siret)) {
		return '';
	}
	return substr(preg_replace('/[^0-9]/', '', $siret), 0, 9);
}

/**
 * Détermine si une facture relève du circuit Chorus Pro (B2G, secteur public),
 * auquel cas un PDF Factur-X au profil Chorus doit être généré EN PLUS du PDF
 * EN16931 standard. Trois signaux (du plus explicite au filet de sécurité) :
 *   1. case à cocher « Facture Chorus Pro » sur la facture (extrafield lfxchorus) ;
 *   2. présence d'un champ Chorus rempli (code service / engagement / marché) ;
 *   3. acheteur = État central (SIRET commençant par 110002011).
 *
 * @param Facture $invoice  Facture Dolibarr (thirdparty et array_options chargés)
 * @return bool
 */
function lemonfacturx_is_chorus_invoice($invoice)
{
	$ao = (is_object($invoice) && !empty($invoice->array_options)) ? $invoice->array_options : [];

	if (!empty($ao['options_lfxchorus'])) {
		return true;
	}
	foreach (['options_lfxservicecode', 'options_lfxengagement', 'options_lfxmarche'] as $k) {
		if (!empty($ao[$k])) {
			return true;
		}
	}
	// Filet auto : acheteur = secteur public détecté par le SIRET.
	return lemonfacturx_is_public_sector_siret($invoice);
}

/**
 * Détecte si l'acheteur est une entité publique d'après son SIRET (État central :
 * SIRET commençant par 110002011). Sert au filet de détection automatique ET au
 * message d'information affiché quand les fonctionnalités Chorus sont désactivées.
 * NB : les collectivités/hôpitaux ont leur propre SIREN — non détectables ainsi,
 * d'où la case à cocher manuelle dans l'onglet Chorus.
 *
 * @param Facture $invoice
 * @return bool
 */
function lemonfacturx_is_public_sector_siret($invoice)
{
	$buyer = (is_object($invoice) && !empty($invoice->thirdparty)) ? $invoice->thirdparty : null;
	if (is_object($buyer) && !empty($buyer->idprof2)) {
		$siret = preg_replace('/[^0-9]/', '', $buyer->idprof2);
		if (strpos($siret, '110002011') === 0) {
			return true;
		}
	}
	return false;
}

/**
 * Construit le tableau d'options pour générer le XML au profil Chorus Pro à
 * partir des extrafields de la facture (cadre de facturation BT-23, code service
 * BT-10, n° engagement BT-13, n° marché BT-12).
 *
 * @param Facture $invoice
 * @return array  options pour lemonfacturx_build_xml()
 */
function lemonfacturx_chorus_options($invoice)
{
	$ao = (is_object($invoice) && !empty($invoice->array_options)) ? $invoice->array_options : [];
	return [
		'profile'      => 'choruspro',
		'cadre'        => trim((string) ($ao['options_lfxcadre'] ?? '')),
		'service_code' => trim((string) ($ao['options_lfxservicecode'] ?? '')),
		'engagement'   => trim((string) ($ao['options_lfxengagement'] ?? '')),
		'marche'       => trim((string) ($ao['options_lfxmarche'] ?? '')),
	];
}

/**
 * Liste officielle des cadres de facturation Chorus Pro (BT-23), source AIFE
 * « Annexe Processus de facturation » V4.00 §2.1. A1 = dépôt fournisseur (défaut
 * B2G). Pas de A11 dans la nomenclature AIFE.
 *
 * @return array  code => libellé
 */
function lemonfacturx_chorus_frameworks()
{
	return [
		'A1'  => 'A1 — Dépôt d\'une facture par un fournisseur',
		'A2'  => 'A2 — Facture déjà payée (carte d\'achat)',
		'A3'  => 'A3 — Mémoire de frais de justice',
		'A4'  => 'A4 — Projet de décompte mensuel (travaux)',
		'A5'  => 'A5 — État d\'acompte (travaux)',
		'A6'  => 'A6 — Pièce de facturation travaux au service financier',
		'A7'  => 'A7 — Projet de décompte final (travaux)',
		'A8'  => 'A8 — Décompte général signé (travaux)',
		'A9'  => 'A9 — Sous-traitant : demande de paiement',
		'A10' => 'A10 — Sous-traitant : demande de paiement (marché de travaux)',
		'A12' => 'A12 — Cotraitant : facture',
		'A13' => 'A13 — Cotraitant : projet de décompte mensuel',
		'A14' => 'A14 — Cotraitant : projet de décompte final',
		'A15' => 'A15 — MOE : état d\'acompte',
		'A16' => 'A16 — MOE : état d\'acompte validé',
		'A17' => 'A17 — MOE : projet de décompte général',
		'A18' => 'A18 — MOE : décompte général',
		'A19' => 'A19 — MOA : état d\'acompte validé',
		'A20' => 'A20 — MOA : décompte général',
		'A21' => 'A21 — Demande de remboursement TIC',
		'A22' => 'A22 — Fournisseur : projet de décompte général tacite',
		'A23' => 'A23 — Fournisseur : décompte général définitif tacite',
		'A24' => 'A24 — MOE : décompte général définitif tacite',
		'A25' => 'A25 — MOA : décompte général définitif tacite',
	];
}

/**
 * SIREN (9 chiffres) d'un tiers, pour BT-30/BT-47 (SpecifiedLegalOrganization).
 * Source canonique : champ idprof1 (SIREN) de Dolibarr ; à défaut, dérivé des
 * 9 premiers chiffres du SIRET (idprof2). Renvoie '' si rien d'exploitable ou
 * si la valeur ne se normalise pas à exactement 9 chiffres (BR-FR-10).
 *
 * @param object $party  mysoc ou Societe (champs idprof1 / idprof2)
 * @return string        SIREN à 9 chiffres, ou ''
 */
function lemonfacturx_party_siren($party)
{
	$siren = lemonfacturx_extract_siren($party->idprof1 ?? '');
	if ($siren === '') {
		$siren = lemonfacturx_extract_siren($party->idprof2 ?? '');
	}
	return (strlen($siren) === 9) ? $siren : '';
}

/**
 * Cherche l'email d'un tiers : d'abord sur la fiche, sinon sur le 1er contact
 */
function lemonfacturx_get_buyer_email($buyer, $db)
{
	if (!empty($buyer->email)) {
		return $buyer->email;
	}
	if (empty($buyer->id) || !is_object($db)) {
		return '';
	}

	$sql = "SELECT email FROM ".MAIN_DB_PREFIX."socpeople"
		." WHERE fk_soc = ".((int) $buyer->id)
		." AND email IS NOT NULL AND email != ''";
	if (function_exists('getEntity')) {
		$sql .= " AND entity IN (".getEntity('socpeople').")";
	}
	$sql .= " ORDER BY rowid ASC LIMIT 1";
	$res = $db->query($sql);
	if (!$res) {
		return '';
	}
	$obj = $db->fetch_object($res);
	return ($obj && !empty($obj->email)) ? $obj->email : '';
}

/**
 * Résout le chemin du PDF principal d'une facture, ou null s'il n'existe pas.
 * Convention Dolibarr : <dir_entité>/<ref>/<ref>.pdf ; repli sur last_main_doc
 * (chemin relatif à DOL_DATA_ROOT) si le fichier conventionnel est absent.
 *
 * @param string $ref          Référence de la facture
 * @param int    $entity       Entité de la facture (multicompany)
 * @param string $lastMainDoc  Valeur de llx_facture.last_main_doc (optionnel)
 * @return string|null
 */
function lemonfacturx_invoice_pdf_path($ref, $entity, $lastMainDoc = '')
{
	global $conf;

	$dir = !empty($conf->facture->multidir_output[$entity])
		? $conf->facture->multidir_output[$entity]
		: ($conf->facture->dir_output ?? '');
	if (!empty($dir)) {
		$safeRef = dol_sanitizeFileName($ref);
		$path = $dir.'/'.$safeRef.'/'.$safeRef.'.pdf';
		if (file_exists($path)) {
			return $path;
		}
	}
	if (!empty($lastMainDoc) && defined('DOL_DATA_ROOT')) {
		$candidate = DOL_DATA_ROOT.'/'.ltrim($lastMainDoc, '/');
		if (file_exists($candidate)) {
			return $candidate;
		}
	}
	return null;
}

/**
 * Extrait le XML Factur-X embarqué dans un PDF (lecture seule, in-process :
 * le Reader atgp repose sur smalot/pdfparser, sans conflit FPDF/TCPDF).
 *
 * @param string $pdfPath Chemin du PDF
 * @return string|null    XML, ou null si absent/illisible
 */
function lemonfacturx_extract_xml_from_pdf($pdfPath)
{
	require_once dirname(__DIR__, 2).'/vendor/autoload.php';
	try {
		$reader = new \Atgp\FacturX\Reader();
		return $reader->extractXML((string) file_get_contents($pdfPath), false);
	} catch (\Throwable $e) {
		return null;
	}
}

/**
 * Tronque un IBAN pour affichage : "FR76...0185". Vide si IBAN absent.
 */
function lemonfacturx_iban_short($iban)
{
	if (empty($iban)) {
		return '';
	}
	return substr($iban, 0, 4).'...'.substr($iban, -4);
}

if (!function_exists('lemonfacturx_xml_encode')) {
	/**
	 * Échappe une valeur pour insertion dans le XML (ENT_XML1).
	 */
	function lemonfacturx_xml_encode($str)
	{
		return htmlspecialchars((string) $str, ENT_XML1 | ENT_QUOTES, 'UTF-8');
	}
}

if (!function_exists('lemonfacturx_format_amount')) {
	/**
	 * Formate un montant à 2 décimales (AmountType EN16931).
	 */
	function lemonfacturx_format_amount($amount)
	{
		return number_format((float) $amount, 2, '.', '');
	}
}

/**
 * Formate une quantité (BT-129) : jusqu'à 4 décimales, zéros traînants retirés.
 */
function lemonfacturx_format_qty($qty)
{
	$s = number_format((float) $qty, 4, '.', '');
	$s = rtrim($s, '0');
	return rtrim($s, '.');
}

/**
 * Formate un prix unitaire net (BT-146) : 2 décimales, étendu à 4 quand la
 * précision le justifie (ex. 100/3 = 33.3333) pour limiter l'écart qty x prix.
 */
function lemonfacturx_format_unit_price($price)
{
	$price = (float) $price;
	if (abs(round($price, 2) - round($price, 4)) < 0.0000001) {
		return number_format($price, 2, '.', '');
	}
	return number_format($price, 4, '.', '');
}

/**
 * Interroge l'API GitHub pour la dernière release publiée du module.
 * Résultat (succès OU échec) mis en cache 24h dans une constante Dolibarr pour
 * ne pas retenter un appel réseau lent à chaque ouverture de la page admin.
 *
 * @param object $db               Handle DB Dolibarr
 * @param string $currentVersion   Version actuelle du module (ex: "1.1.0")
 * @return array|null              ['version' => 'x.y.z', 'url' => 'https://...']
 *                                 si une version plus récente existe, null sinon
 */
function lemonfacturx_check_latest_release($db, $currentVersion)
{
	$now = time();
	$cacheRaw = getDolGlobalString('LEMONFACTURX_UPDATE_CHECK_CACHE', '');
	$cache = !empty($cacheRaw) ? json_decode($cacheRaw, true) : null;

	$latest = null;
	$htmlUrl = '';
	if (is_array($cache) && isset($cache['ts']) && ($now - (int) $cache['ts']) < 86400) {
		$latest  = $cache['version'] ?? null;
		$htmlUrl = $cache['url']     ?? '';
	} else {
		$latest = null;
		$htmlUrl = '';
		if (function_exists('curl_init')) {
			$url = 'https://api.github.com/repos/hello-lemon/module-dolibarr-lemonfacturx/releases/latest';
			$ch = curl_init($url);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_USERAGENT, 'LemonFacturX-UpdateCheck');
			curl_setopt($ch, CURLOPT_TIMEOUT, 5);
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
			$json = @curl_exec($ch);
			$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			curl_close($ch);

			if ($httpCode === 200 && !empty($json)) {
				$data = json_decode($json, true);
				if (is_array($data) && !empty($data['tag_name'])) {
					$latest  = ltrim($data['tag_name'], 'v');
					$htmlUrl = $data['html_url'] ?? '';
					// Validation défensive : on n'accepte qu'une URL github.com officielle du repo
					if (!preg_match('#^https://github\.com/hello-lemon/module-dolibarr-lemonfacturx/#', $htmlUrl)) {
						$htmlUrl = 'https://github.com/hello-lemon/module-dolibarr-lemonfacturx/releases';
					}
				}
			}
		}

		// Cacher aussi l'échec (version null) : GitHub injoignable ne doit pas
		// ralentir la page admin de 5s à chaque ouverture pendant 24h.
		dolibarr_set_const($db, 'LEMONFACTURX_UPDATE_CHECK_CACHE', json_encode([
			'ts'      => $now,
			'version' => $latest,
			'url'     => $htmlUrl,
		]), 'chaine', 0, '', 0);
	}

	if (!empty($latest) && version_compare($latest, $currentVersion, '>')) {
		return ['version' => $latest, 'url' => $htmlUrl];
	}
	return null;
}
