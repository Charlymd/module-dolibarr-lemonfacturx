<?php
/*
 * Copyright (C) 2026 SASU LEMON <https://hellolemon.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Contrôle interne des règles métier EN16931 (sous-ensemble Schematron).
 *
 * Le XSD ne porte aucune règle BR-* : ce module vérifie en PHP les règles de
 * calcul et de cohérence les plus fréquemment contrôlées par les validateurs
 * (Mustang/FNFE, Chorus Pro, PDP) AVANT injection dans le PDF. Ce n'est PAS
 * un remplacement du Schematron officiel (200+ règles, XSLT 2.0 — non
 * exécutable avec l'extension XSL de PHP, limitée à XSLT 1.0) : c'est un
 * filet de sécurité couvrant les rejets les plus courants.
 */

/**
 * Valide un XML contre le XSD Factur-X EN16931 embarqué.
 * Fonction partagée par le hook, les tests unitaires et les tests d'intégration
 * — une seule implémentation de la validation structurelle.
 *
 * @param string $xml         XML à valider
 * @param string $modulePath  Racine du module (pour localiser le XSD embarqué)
 * @return string|null        Message d'erreur si invalide, null si OK
 *                            (ou si le XSD est absent : well-formed seul)
 */
function lemonfacturx_validate_xsd($xml, $modulePath)
{
	if (empty($xml)) {
		return 'XML vide';
	}

	$prevUseErrors = libxml_use_internal_errors(true);
	libxml_clear_errors();

	$firstError = function ($fallback) use ($prevUseErrors) {
		$errs = libxml_get_errors();
		libxml_clear_errors();
		libxml_use_internal_errors($prevUseErrors);
		return !empty($errs) ? trim($errs[0]->message) : $fallback;
	};

	$dom = new DOMDocument();
	if (!$dom->loadXML($xml)) {
		return 'XML mal formé : '.$firstError('XML mal formé');
	}

	$xsdPath = $modulePath.'/vendor/atgp/factur-x/xsd/factur-x/en16931/Factur-X_1.08_EN16931.xsd';
	if (!file_exists($xsdPath)) {
		// Absence du XSD embarqué : on ne bloque pas, le well-formed est vérifié
		libxml_clear_errors();
		libxml_use_internal_errors($prevUseErrors);
		return null;
	}

	if (!$dom->schemaValidate($xsdPath)) {
		return 'non conforme XSD EN16931 : '.$firstError('violation de contrainte inconnue');
	}

	libxml_clear_errors();
	libxml_use_internal_errors($prevUseErrors);
	return null;
}

/**
 * Valide un XML CrossIndustryInvoice contre un sous-ensemble des règles
 * métier EN16931 (BR-*, BR-CO-*, BR-S/E/K/G/AE-*, BR-IC-*).
 *
 * @param string $xml XML CrossIndustryInvoice généré
 * @return array      Liste de violations ("BR-XX : message"), vide si conforme
 */
function lemonfacturx_validate_business_rules($xml)
{
	$violations = [];

	$prevUseErrors = libxml_use_internal_errors(true);
	$dom = new DOMDocument();
	if (!$dom->loadXML($xml)) {
		libxml_clear_errors();
		libxml_use_internal_errors($prevUseErrors);
		return ['XML : document non parsable'];
	}
	libxml_clear_errors();
	libxml_use_internal_errors($prevUseErrors);

	$xp = new DOMXPath($dom);
	$xp->registerNamespace('rsm', 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100');
	$xp->registerNamespace('ram', 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100');
	$xp->registerNamespace('udt', 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100');
	$xp->registerNamespace('qdt', 'urn:un:unece:uncefact:data:standard:QualifiedDataType:100');

	$num = function ($query, $context = null) use ($xp) {
		$nodes = $context ? $xp->query($query, $context) : $xp->query($query);
		return ($nodes && $nodes->length > 0) ? (float) $nodes->item(0)->textContent : null;
	};
	$str = function ($query, $context = null) use ($xp) {
		$nodes = $context ? $xp->query($query, $context) : $xp->query($query);
		return ($nodes && $nodes->length > 0) ? trim($nodes->item(0)->textContent) : null;
	};
	$eq = function ($a, $b) {
		return abs($a - $b) < 0.005;
	};

	// === BR-16 : au moins une ligne ===
	$lines = $xp->query('//ram:IncludedSupplyChainTradeLineItem');
	if ($lines->length === 0) {
		$violations[] = 'BR-16 : la facture doit comporter au moins une ligne';
	}

	// === Lignes : BR-27 (prix net >= 0) + collecte pour BR-CO-10 et bases ===
	$sumLines = 0.0;
	$lineBasis = []; // "cat|rate" => somme des LineTotalAmount
	foreach ($lines as $i => $line) {
		$n = $i + 1;
		$price = $num('.//ram:NetPriceProductTradePrice/ram:ChargeAmount', $line);
		if ($price !== null && $price < 0) {
			$violations[] = 'BR-27 : ligne '.$n.' — le prix net unitaire ne doit pas être négatif ('.$price.')';
		}
		$lineTotal = $num('.//ram:SpecifiedTradeSettlementLineMonetarySummation/ram:LineTotalAmount', $line);
		if ($lineTotal !== null) {
			$sumLines += $lineTotal;
		}
		$cat = $str('.//ram:ApplicableTradeTax/ram:CategoryCode', $line);
		$rate = $num('.//ram:ApplicableTradeTax/ram:RateApplicablePercent', $line);
		$key = $cat.'|'.(float) $rate;
		$lineBasis[$key] = ($lineBasis[$key] ?? 0.0) + (float) $lineTotal;

		// Cohérence catégorie/taux au niveau ligne
		if ($cat === 'S' && $rate !== null && $rate <= 0) {
			$violations[] = 'BR-S-05 : ligne '.$n.' — catégorie S avec taux nul';
		}
		if (in_array($cat, ['E', 'K', 'G', 'AE', 'Z'], true) && $rate !== null && $rate != 0) {
			$violations[] = 'BR-'.$cat.'-05 : ligne '.$n.' — catégorie '.$cat.' avec taux non nul ('.$rate.')';
		}
	}

	// === Remises/frais document (BG-20/BG-21) ===
	$sumAllowances = 0.0;
	$sumCharges = 0.0;
	foreach ($xp->query('//ram:ApplicableHeaderTradeSettlement/ram:SpecifiedTradeAllowanceCharge') as $ac) {
		$indicator = $str('.//ram:ChargeIndicator/udt:Indicator', $ac);
		$amount = (float) $num('.//ram:ActualAmount', $ac);
		$cat = $str('.//ram:CategoryTradeTax/ram:CategoryCode', $ac);
		$rate = $num('.//ram:CategoryTradeTax/ram:RateApplicablePercent', $ac);
		$key = $cat.'|'.(float) $rate;
		if ($indicator === 'true') {
			$sumCharges += $amount;
			$lineBasis[$key] = ($lineBasis[$key] ?? 0.0) + $amount;
		} else {
			$sumAllowances += $amount;
			$lineBasis[$key] = ($lineBasis[$key] ?? 0.0) - $amount;
		}
	}

	// === Totaux (BG-22) ===
	$ms = '//ram:SpecifiedTradeSettlementHeaderMonetarySummation/';
	$btLineTotal   = $num($ms.'ram:LineTotalAmount');
	$btAllowance   = $num($ms.'ram:AllowanceTotalAmount') ?? 0.0;
	$btCharge      = $num($ms.'ram:ChargeTotalAmount') ?? 0.0;
	$btTaxBasis    = $num($ms.'ram:TaxBasisTotalAmount');
	// BT-110 : EN16931 autorise deux TaxTotalAmount (devise facture BT-110 +
	// devise de TVA BT-111) — prendre celui dont currencyID = devise facture.
	$invoiceCurrency = $str('//ram:ApplicableHeaderTradeSettlement/ram:InvoiceCurrencyCode');
	$btTaxTotal = null;
	foreach ($xp->query($ms.'ram:TaxTotalAmount') as $taxTotalNode) {
		$nodeCurrency = $taxTotalNode->getAttribute('currencyID');
		if ($btTaxTotal === null || $nodeCurrency === $invoiceCurrency || $nodeCurrency === '') {
			$btTaxTotal = (float) $taxTotalNode->textContent;
			if ($nodeCurrency === $invoiceCurrency) {
				break;
			}
		}
	}
	$btTaxTotal    = $btTaxTotal ?? 0.0;
	$btGrandTotal  = $num($ms.'ram:GrandTotalAmount');
	$btPrepaid     = $num($ms.'ram:TotalPrepaidAmount') ?? 0.0;
	$btDuePayable  = $num($ms.'ram:DuePayableAmount');

	if ($btLineTotal !== null && !$eq($btLineTotal, $sumLines)) {
		$violations[] = sprintf('BR-CO-10 : LineTotalAmount (%.2f) != somme des lignes (%.2f)', $btLineTotal, $sumLines);
	}
	if (!$eq($btAllowance, $sumAllowances)) {
		$violations[] = sprintf('BR-CO-11 : AllowanceTotalAmount (%.2f) != somme des remises (%.2f)', $btAllowance, $sumAllowances);
	}
	if (!$eq($btCharge, $sumCharges)) {
		$violations[] = sprintf('BR-CO-12 : ChargeTotalAmount (%.2f) != somme des frais (%.2f)', $btCharge, $sumCharges);
	}
	if ($btTaxBasis !== null && $btLineTotal !== null && !$eq($btTaxBasis, $btLineTotal - $btAllowance + $btCharge)) {
		$violations[] = sprintf('BR-CO-13 : TaxBasisTotalAmount (%.2f) != lignes - remises + frais (%.2f)', $btTaxBasis, $btLineTotal - $btAllowance + $btCharge);
	}
	if ($btGrandTotal !== null && $btTaxBasis !== null && !$eq($btGrandTotal, $btTaxBasis + $btTaxTotal)) {
		$violations[] = sprintf('BR-CO-15 : GrandTotalAmount (%.2f) != base (%.2f) + TVA (%.2f)', $btGrandTotal, $btTaxBasis, $btTaxTotal);
	}
	if ($btDuePayable !== null && $btGrandTotal !== null && !$eq($btDuePayable, $btGrandTotal - $btPrepaid)) {
		$violations[] = sprintf('BR-CO-16 : DuePayableAmount (%.2f) != GrandTotal (%.2f) - Prepaid (%.2f)', $btDuePayable, $btGrandTotal, $btPrepaid);
	}

	// === Ventilation TVA (BG-23) : BR-CO-14, BR-CO-17, bases par catégorie ===
	$sumCalculated = 0.0;
	$hasK = false;
	foreach ($xp->query('//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax') as $tax) {
		$cat = $str('ram:CategoryCode', $tax);
		$rate = $num('ram:RateApplicablePercent', $tax);
		$basis = (float) $num('ram:BasisAmount', $tax);
		$calc = (float) $num('ram:CalculatedAmount', $tax);
		$sumCalculated += $calc;
		if ($cat === 'K') {
			$hasK = true;
		}

		// BR-CO-17 : montant TVA = base x taux, arrondi à 2 décimales (+/- 0.01)
		if ($rate !== null && abs($calc - round($basis * $rate / 100, 2)) > 0.0105) {
			$violations[] = sprintf('BR-CO-17 : TVA calculée (%.2f) != base (%.2f) x taux (%.2f%%) = %.2f', $calc, $basis, $rate, round($basis * $rate / 100, 2));
		}
		// Base par (catégorie, taux) = somme lignes - remises + frais de la catégorie
		$key = $cat.'|'.(float) $rate;
		if (isset($lineBasis[$key]) && !$eq($basis, $lineBasis[$key])) {
			$violations[] = sprintf('BR-%s-08 : base de la catégorie %s @ %s%% (%.2f) != somme des lignes/remises (%.2f)', $cat, $cat, (float) $rate, $basis, $lineBasis[$key]);
		}
		// Exonérations : motif obligatoire (BR-E-10, BR-K-10, BR-G-10, BR-AE-10)
		if (in_array($cat, ['E', 'K', 'G', 'AE'], true)) {
			$reason = $str('ram:ExemptionReason', $tax);
			$reasonCode = $str('ram:ExemptionReasonCode', $tax);
			if (empty($reason) && empty($reasonCode)) {
				$violations[] = 'BR-'.$cat.'-10 : motif d\'exonération manquant pour la catégorie '.$cat;
			}
		}
	}
	if (!$eq($btTaxTotal, $sumCalculated)) {
		$violations[] = sprintf('BR-CO-14 : TaxTotalAmount (%.2f) != somme des TVA par catégorie (%.2f)', $btTaxTotal, $sumCalculated);
	}

	// === BR-61 : virement (30/58) => IBAN obligatoire ===
	foreach ($xp->query('//ram:SpecifiedTradeSettlementPaymentMeans') as $pm) {
		$typeCode = $str('ram:TypeCode', $pm);
		if (in_array($typeCode, ['30', '58'], true)) {
			$iban = $str('ram:PayeePartyCreditorFinancialAccount/ram:IBANID', $pm);
			if (empty($iban)) {
				$violations[] = 'BR-61 : moyen de paiement '.$typeCode.' (virement) sans IBAN du bénéficiaire';
			}
		}
	}

	// === BR-IC-11/12 : livraison intracommunautaire (K) ===
	if ($hasK) {
		$deliveryDate = $str('//ram:ApplicableHeaderTradeDelivery/ram:ActualDeliverySupplyChainEvent/ram:OccurrenceDateTime/udt:DateTimeString');
		$period = $xp->query('//ram:ApplicableHeaderTradeSettlement/ram:BillingSpecifiedPeriod');
		if (empty($deliveryDate) && $period->length === 0) {
			$violations[] = 'BR-IC-11 : catégorie K sans date de livraison ni période de facturation';
		}
		$shipToCountry = $str('//ram:ApplicableHeaderTradeDelivery/ram:ShipToTradeParty/ram:PostalTradeAddress/ram:CountryID');
		if (empty($shipToCountry)) {
			$violations[] = 'BR-IC-12 : catégorie K sans pays de livraison (BT-80)';
		}
		// BR-IC-02 : K => TVA intra vendeur obligatoire
		$sellerVat = $str('//ram:SellerTradeParty/ram:SpecifiedTaxRegistration/ram:ID[@schemeID="VA"]');
		if (empty($sellerVat)) {
			$violations[] = 'BR-IC-02 : catégorie K sans numéro de TVA du vendeur (BT-31)';
		}
	}

	// === BR-AE-02 : autoliquidation => TVA vendeur ET acheteur ===
	$hasAE = $xp->query('//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax[ram:CategoryCode="AE"]')->length > 0;
	if ($hasAE) {
		$sellerVat = $str('//ram:SellerTradeParty/ram:SpecifiedTaxRegistration/ram:ID[@schemeID="VA"]');
		$buyerVat = $str('//ram:BuyerTradeParty/ram:SpecifiedTaxRegistration/ram:ID[@schemeID="VA"]');
		if (empty($sellerVat) || empty($buyerVat)) {
			$violations[] = 'BR-AE-02 : catégorie AE — les numéros de TVA du vendeur (BT-31) et de l\'acheteur (BT-48) sont obligatoires';
		}
	}

	// === BR-CO-26 : identifiant vendeur (légal ou fiscal) obligatoire ===
	$sellerLegal = $str('//ram:SellerTradeParty/ram:SpecifiedLegalOrganization/ram:ID');
	$sellerTax = $str('//ram:SellerTradeParty/ram:SpecifiedTaxRegistration/ram:ID');
	if (empty($sellerLegal) && empty($sellerTax)) {
		$violations[] = 'BR-CO-26 : le vendeur doit porter un identifiant légal (BT-30) ou fiscal (BT-31/BT-32)';
	}

	// === BR-09 / BR-11 : pays vendeur et acheteur obligatoires ===
	if (empty($str('//ram:SellerTradeParty/ram:PostalTradeAddress/ram:CountryID'))) {
		$violations[] = 'BR-09 : pays de l\'adresse du vendeur manquant (BT-40)';
	}
	if (empty($str('//ram:BuyerTradeParty/ram:PostalTradeAddress/ram:CountryID'))) {
		$violations[] = 'BR-11 : pays de l\'adresse de l\'acheteur manquant (BT-55)';
	}

	// === BR-CO-25 : montant dû > 0 => date d'échéance ou conditions de paiement ===
	if ($btDuePayable !== null && $btDuePayable > 0) {
		$dueDate = $str('//ram:SpecifiedTradePaymentTerms/ram:DueDateDateTime/udt:DateTimeString');
		$terms = $str('//ram:SpecifiedTradePaymentTerms/ram:Description');
		if (empty($dueDate) && empty($terms)) {
			$violations[] = 'BR-CO-25 : montant à payer positif sans date d\'échéance (BT-9) ni conditions de paiement (BT-20)';
		}
	}

	return $violations;
}
