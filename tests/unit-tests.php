<?php
/*
 * Suite de tests unitaires standalone LemonFacturX — AUCUN Dolibarr requis.
 *
 * Génère le XML Factur-X sur des objets factices (stubs.php) et vérifie pour
 * chaque scénario : validation XSD EN16931, règles métier internes (BR-*),
 * et assertions ciblées (TypeCode, catégories, montants, blocs optionnels).
 *
 * Usage : php tests/unit-tests.php
 * Exit code 0 = tous les tests passent, 1 = au moins un échec.
 *
 * Complémentaire de tests/run-tests.php qui, lui, s'exécute contre un
 * Dolibarr réel avec les fixtures de demo/.
 */

require_once __DIR__.'/stubs.php';
require_once __DIR__.'/../core/lib/lemonfacturx.lib.php';
require_once __DIR__.'/../core/lib/lemonfacturx_rules.php';

$xsdPath = __DIR__.'/../vendor/atgp/factur-x/xsd/factur-x/en16931/Factur-X_1.08_EN16931.xsd';

$passed = 0;
$failed = 0;
$failures = [];

function lfx_assert($cond, $label)
{
	global $passed, $failed, $failures, $currentTest;
	if ($cond) {
		$passed++;
		return;
	}
	$failed++;
	$failures[] = "[$currentTest] $label";
}

function lfx_assert_eq($expected, $actual, $label)
{
	global $passed, $failed, $failures, $currentTest;
	$ok = ($expected === $actual)
		|| (is_numeric($expected) && is_numeric($actual) && abs((float) $expected - (float) $actual) < 0.005);
	if ($ok) {
		$passed++;
		return;
	}
	$failed++;
	$failures[] = "[$currentTest] $label : attendu=".var_export($expected, true)." obtenu=".var_export($actual, true);
}

function lfx_xpath($xml)
{
	$dom = new DOMDocument();
	$dom->loadXML($xml);
	$xp = new DOMXPath($dom);
	$xp->registerNamespace('rsm', 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100');
	$xp->registerNamespace('ram', 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100');
	$xp->registerNamespace('udt', 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100');
	$xp->registerNamespace('qdt', 'urn:un:unece:uncefact:data:standard:QualifiedDataType:100');
	return $xp;
}

function lfx_xp_str($xp, $query)
{
	$n = $xp->query($query);
	return ($n && $n->length > 0) ? trim($n->item(0)->textContent) : null;
}

function lfx_check_xsd($xml, $xsdPath)
{
	// Même implémentation que la production (core/lib/lemonfacturx_rules.php) :
	// les tests valident le code réellement exécuté par le hook.
	if (!file_exists($xsdPath)) {
		return 'XSD absent';
	}
	return lemonfacturx_validate_xsd($xml, dirname(__DIR__));
}

/**
 * Réinitialise la config factice avant chaque scénario.
 */
function lfx_reset($conf = [], $bank = ['iban' => 'FR7630006000011234567890189', 'bic' => 'AGRIFRPP'], $dbHandlers = [])
{
	$GLOBALS['lfx_test_conf'] = array_merge([
		'LEMONFACTURX_BANK_ACCOUNT' => $bank !== null ? 1 : 0,
		'LEMONFACTURX_PAYMENT_MEANS' => '30',
		'MAIN_PDF_FORCE_FONT' => 'pdfahelvetica',
	], $conf);
	$GLOBALS['lfx_test_bank'] = $bank;
	$GLOBALS['lfx_test_db_handlers'] = $dbHandlers;
}

function lfx_std_validate($xml, $xsdPath, $expectBrOk = true)
{
	$xsdErr = lfx_check_xsd($xml, $xsdPath);
	lfx_assert($xsdErr === null, 'XSD valide ('.($xsdErr ?? '').')');
	$violations = lemonfacturx_validate_business_rules($xml);
	if ($expectBrOk) {
		lfx_assert(empty($violations), 'Règles BR respectées ('.implode(' ; ', $violations).')');
	} else {
		lfx_assert(!empty($violations), 'Violations BR attendues');
	}
	return $violations;
}

/**
 * Vrai si au moins une entrée du tableau contient le fragment donné
 * (préfixe de règle « BR-FR-01 », clé de traduction, etc.).
 */
function lfx_contains($entries, $needle)
{
	foreach ((array) $entries as $e) {
		if (strpos((string) $e, $needle) !== false) {
			return true;
		}
	}
	return false;
}

echo "=== LemonFacturX - Tests unitaires standalone ===\n\n";

$mysoc = lfx_make_party(['name' => 'LEMON SASU', 'idprof1' => '909458306', 'idprof2' => '90945830600012', 'tva_intra' => 'FR38909458306']);

// ---------------------------------------------------------------------------
$currentTest = 'U01 standard FR 20%';
lfx_reset();
$inv = new LfxFakeInvoice();
$inv->ref = 'FA2606-0001';
$inv->thirdparty = lfx_make_party();
$inv->lines = [lfx_make_line(1000.00, 20.0, 200.00)];
$inv->total_ht = 1000.00;
$inv->total_tva = 200.00;
$inv->total_ttc = 1200.00;
$w = [];
$xml = lemonfacturx_build_xml($inv, $mysoc, $w);
lfx_std_validate($xml, $xsdPath);
$xp = lfx_xpath($xml);
lfx_assert_eq('380', lfx_xp_str($xp, '//rsm:ExchangedDocument/ram:TypeCode'), 'TypeCode');
lfx_assert_eq('1200.00', lfx_xp_str($xp, '//ram:DuePayableAmount'), 'DuePayableAmount');
lfx_assert_eq('S', lfx_xp_str($xp, '//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax/ram:CategoryCode'), 'CategoryCode');
// BT-29 : SIRET (établissement) dans ram:GlobalID/0009 (le schemeID ne se met PAS
// sur ram:ID en EN16931 — sinon « @schemeID not used » côté Schematron FNFE)
lfx_assert_eq('0009', lfx_xp_str($xp, '//ram:SellerTradeParty/ram:GlobalID/@schemeID'), 'BT-29 schemeID SIRET (0009)');
lfx_assert_eq('90945830600012', lfx_xp_str($xp, '//ram:SellerTradeParty/ram:GlobalID'), 'BT-29 SIRET vendeur');
// BT-30 : SIREN (entité légale) dans SpecifiedLegalOrganization/0002
lfx_assert_eq('0002', lfx_xp_str($xp, '//ram:SellerTradeParty/ram:SpecifiedLegalOrganization/ram:ID/@schemeID'), 'BT-30 schemeID SIREN (0002)');
lfx_assert_eq('909458306', lfx_xp_str($xp, '//ram:SellerTradeParty/ram:SpecifiedLegalOrganization/ram:ID'), 'BT-30 SIREN vendeur');
lfx_assert(empty($w), 'aucun warning ('.implode(' ; ', $w).')');
echo "U01 OK\n";

// U01b : source du SIREN — idprof1 prioritaire, fallback dérivé du SIRET
$wb = [];
$socPrio = lfx_make_party(['idprof1' => '111222333', 'idprof2' => '44455566600018', 'tva_intra' => '', 'tva_assuj' => 0]);
$xpb = lfx_xpath(lemonfacturx_build_xml($inv, $socPrio, $wb));
lfx_assert_eq('111222333', lfx_xp_str($xpb, '//ram:SellerTradeParty/ram:SpecifiedLegalOrganization/ram:ID'), 'BT-30 SIREN = idprof1 (prioritaire sur le SIRET)');
lfx_assert_eq('44455566600018', lfx_xp_str($xpb, '//ram:SellerTradeParty/ram:GlobalID'), 'BT-29 SIRET = idprof2');
$wf = [];
$socFb = lfx_make_party(['idprof1' => '', 'idprof2' => '90945830600012', 'tva_intra' => 'FR38909458306']);
$xpf = lfx_xpath(lemonfacturx_build_xml($inv, $socFb, $wf));
lfx_assert_eq('909458306', lfx_xp_str($xpf, '//ram:SellerTradeParty/ram:SpecifiedLegalOrganization/ram:ID'), 'BT-30 SIREN dérivé du SIRET quand idprof1 absent');
echo "U01b OK\n";

// U01c : profil Chorus Pro — SIRET-14 dans SpecifiedLegalOrganization (0009) + BT-10/12/13
$wc = [];
$xmlChorus = lemonfacturx_build_xml($inv, $mysoc, $wc, [
	'profile'      => 'choruspro',
	'service_code' => 'SVC12345678',
	'engagement'   => 'EJ2026-42',
	'marche'       => 'MARCHE-7',
]);
lfx_std_validate($xmlChorus, $xsdPath);
$xpc = lfx_xpath($xmlChorus);
lfx_assert_eq('0009', lfx_xp_str($xpc, '//ram:SellerTradeParty/ram:SpecifiedLegalOrganization/ram:ID/@schemeID'), 'Chorus: BT-30 schemeID SIRET (0009)');
lfx_assert_eq('90945830600012', lfx_xp_str($xpc, '//ram:SellerTradeParty/ram:SpecifiedLegalOrganization/ram:ID'), 'Chorus: BT-30 SIRET-14 vendeur');
lfx_assert_eq(0, $xpc->query('//ram:SellerTradeParty/ram:GlobalID')->length, 'Chorus: pas de GlobalID BT-29 séparé (SIRET uniquement en SpecifiedLegalOrganization)');
lfx_assert_eq('SVC12345678', lfx_xp_str($xpc, '//ram:ApplicableHeaderTradeAgreement/ram:BuyerReference'), 'Chorus: BT-10 code service');
lfx_assert_eq('EJ2026-42', lfx_xp_str($xpc, '//ram:BuyerOrderReferencedDocument/ram:IssuerAssignedID'), 'Chorus: BT-13 n° engagement');
lfx_assert_eq('MARCHE-7', lfx_xp_str($xpc, '//ram:ContractReferencedDocument/ram:IssuerAssignedID'), 'Chorus: BT-12 n° marché');
echo "U01c OK\n";

// ---------------------------------------------------------------------------
$currentTest = 'U02 avoir 381 montants positifs + BG-3';
lfx_reset([], ['iban' => 'FR7630006000011234567890189', 'bic' => 'AGRIFRPP'], [
	'FROM '.MAIN_DB_PREFIX.'facture WHERE rowid = 42' => [(object) ['ref' => 'FA2605-0099', 'datef' => '2026-05-12']],
]);
$inv = new LfxFakeInvoice();
$inv->ref = 'AV2606-0001';
$inv->type = 2; // TYPE_CREDIT_NOTE
$inv->fk_facture_source = 42;
$inv->thirdparty = lfx_make_party();
$inv->lines = [lfx_make_line(-1000.00, 20.0, -200.00, 1.0, 'Avoir sur FA2605-0099')];
$inv->total_ht = -1000.00;
$inv->total_tva = -200.00;
$inv->total_ttc = -1200.00;
$w = [];
$xml = lemonfacturx_build_xml($inv, $mysoc, $w);
lfx_std_validate($xml, $xsdPath);
$xp = lfx_xpath($xml);
lfx_assert_eq('381', lfx_xp_str($xp, '//rsm:ExchangedDocument/ram:TypeCode'), 'TypeCode 381');
lfx_assert_eq('1200.00', lfx_xp_str($xp, '//ram:GrandTotalAmount'), 'GrandTotal positif');
lfx_assert_eq('1200.00', lfx_xp_str($xp, '//ram:DuePayableAmount'), 'DuePayable positif (BR-CO-16, pas de max(0))');
$creditPrice = lfx_xp_str($xp, '//ram:NetPriceProductTradePrice/ram:ChargeAmount');
lfx_assert($creditPrice !== null, 'prix unitaire présent');
lfx_assert((float) $creditPrice >= 0, 'prix unitaire positif (BR-27)');
lfx_assert_eq('FA2605-0099', lfx_xp_str($xp, '//ram:InvoiceReferencedDocument/ram:IssuerAssignedID'), 'BG-3 facture d\'origine');
lfx_assert_eq('20260512', lfx_xp_str($xp, '//ram:InvoiceReferencedDocument/ram:FormattedIssueDateTime/qdt:DateTimeString'), 'BG-3 date d\'origine');
echo "U02 OK\n";

// ---------------------------------------------------------------------------
$currentTest = 'U03 remise fixe -> BG-21';
lfx_reset();
$inv = new LfxFakeInvoice();
$inv->ref = 'FA2606-0002';
$inv->thirdparty = lfx_make_party();
$inv->lines = [
	lfx_make_line(1000.00, 20.0, 200.00, 1.0, 'Prestation'),
	lfx_make_line(-100.00, 20.0, -20.00, 1.0, 'Remise commerciale'),
];
$inv->total_ht = 900.00;
$inv->total_tva = 180.00;
$inv->total_ttc = 1080.00;
$w = [];
$xml = lemonfacturx_build_xml($inv, $mysoc, $w);
lfx_std_validate($xml, $xsdPath);
$xp = lfx_xpath($xml);
lfx_assert_eq(1, $xp->query('//ram:IncludedSupplyChainTradeLineItem')->length, 'une seule ligne (la remise est sortie des lignes)');
lfx_assert_eq('100.00', lfx_xp_str($xp, '//ram:SpecifiedTradeAllowanceCharge/ram:ActualAmount'), 'BG-21 montant remise');
lfx_assert_eq('false', lfx_xp_str($xp, '//ram:SpecifiedTradeAllowanceCharge/ram:ChargeIndicator/udt:Indicator'), 'ChargeIndicator false');
lfx_assert_eq('1000.00', lfx_xp_str($xp, '//ram:SpecifiedTradeSettlementHeaderMonetarySummation/ram:LineTotalAmount'), 'BT-106');
lfx_assert_eq('100.00', lfx_xp_str($xp, '//ram:AllowanceTotalAmount'), 'BT-107');
lfx_assert_eq('900.00', lfx_xp_str($xp, '//ram:TaxBasisTotalAmount'), 'BT-109');
lfx_assert_eq('180.00', lfx_xp_str($xp, '//ram:TaxTotalAmount'), 'BT-110');
lfx_assert_eq('1080.00', lfx_xp_str($xp, '//ram:DuePayableAmount'), 'BT-115');
echo "U03 OK\n";

// ---------------------------------------------------------------------------
$currentTest = 'U04 intracom biens (K) + ShipTo';
lfx_reset();
$inv = new LfxFakeInvoice();
$inv->ref = 'FA2606-0003';
$inv->thirdparty = lfx_make_party(['country_code' => 'DE', 'tva_intra' => 'DE129273398', 'idprof2' => '']);
$inv->lines = [lfx_make_line(2000.00, 0.0, 0.00, 1.0, 'Marchandise', 0)];
$inv->total_ht = 2000.00;
$inv->total_tva = 0.00;
$inv->total_ttc = 2000.00;
$w = [];
$xml = lemonfacturx_build_xml($inv, $mysoc, $w);
lfx_std_validate($xml, $xsdPath);
$xp = lfx_xpath($xml);
lfx_assert_eq('K', lfx_xp_str($xp, '//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax/ram:CategoryCode'), 'catégorie K (biens)');
lfx_assert_eq('VATEX-EU-IC', lfx_xp_str($xp, '//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax/ram:ExemptionReasonCode'), 'BT-121 VATEX-EU-IC');
lfx_assert_eq('DE', lfx_xp_str($xp, '//ram:ShipToTradeParty/ram:PostalTradeAddress/ram:CountryID'), 'BT-80 pays de livraison (BR-IC-12)');
lfx_assert(lfx_xp_str($xp, '//ram:ActualDeliverySupplyChainEvent/ram:OccurrenceDateTime/udt:DateTimeString') !== null, 'BT-72 date de livraison (BR-IC-11)');
echo "U04 OK\n";

// ---------------------------------------------------------------------------
$currentTest = 'U05 intracom services (AE)';
lfx_reset();
$inv = new LfxFakeInvoice();
$inv->ref = 'FA2606-0004';
$inv->thirdparty = lfx_make_party(['country_code' => 'DE', 'tva_intra' => 'DE129273398', 'idprof2' => '']);
$inv->lines = [lfx_make_line(2000.00, 0.0, 0.00, 1.0, 'Prestation de conseil', 1)];
$inv->total_ht = 2000.00;
$inv->total_tva = 0.00;
$inv->total_ttc = 2000.00;
$w = [];
$xml = lemonfacturx_build_xml($inv, $mysoc, $w);
lfx_std_validate($xml, $xsdPath);
$xp = lfx_xpath($xml);
lfx_assert_eq('AE', lfx_xp_str($xp, '//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax/ram:CategoryCode'), 'catégorie AE (services, art. 196)');
lfx_assert_eq('VATEX-EU-AE', lfx_xp_str($xp, '//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax/ram:ExemptionReasonCode'), 'BT-121 VATEX-EU-AE');
echo "U05 OK\n";

// ---------------------------------------------------------------------------
$currentTest = 'U06 export hors UE (G)';
lfx_reset();
$inv = new LfxFakeInvoice();
$inv->ref = 'FA2606-0005';
$inv->thirdparty = lfx_make_party(['country_code' => 'US', 'tva_intra' => '', 'idprof2' => '']);
$inv->lines = [lfx_make_line(5000.00, 0.0, 0.00)];
$inv->total_ht = 5000.00;
$inv->total_tva = 0.00;
$inv->total_ttc = 5000.00;
$w = [];
$xml = lemonfacturx_build_xml($inv, $mysoc, $w);
lfx_std_validate($xml, $xsdPath);
$xp = lfx_xpath($xml);
lfx_assert_eq('G', lfx_xp_str($xp, '//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax/ram:CategoryCode'), 'catégorie G');
lfx_assert_eq('VATEX-EU-G', lfx_xp_str($xp, '//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax/ram:ExemptionReasonCode'), 'BT-121 VATEX-EU-G');
echo "U06 OK\n";

// ---------------------------------------------------------------------------
$currentTest = 'U07 franchise en base (E + FC)';
lfx_reset();
$mysocFranchise = lfx_make_party(['name' => 'MICRO EI', 'idprof2' => '90945830600012', 'tva_intra' => '', 'tva_assuj' => 0]);
$inv = new LfxFakeInvoice();
$inv->ref = 'FA2606-0006';
$inv->thirdparty = lfx_make_party();
$inv->lines = [lfx_make_line(500.00, 0.0, 0.00)];
$inv->total_ht = 500.00;
$inv->total_tva = 0.00;
$inv->total_ttc = 500.00;
$w = [];
$xml = lemonfacturx_build_xml($inv, $mysocFranchise, $w);
lfx_std_validate($xml, $xsdPath);
$xp = lfx_xpath($xml);
lfx_assert_eq('E', lfx_xp_str($xp, '//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax/ram:CategoryCode'), 'catégorie E');
lfx_assert_eq('VATEX-FR-FRANCHISE', lfx_xp_str($xp, '//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax/ram:ExemptionReasonCode'), 'BT-121 VATEX-FR-FRANCHISE');
lfx_assert_eq('FC', lfx_xp_str($xp, '//ram:SellerTradeParty/ram:SpecifiedTaxRegistration/ram:ID/@schemeID'), 'BT-32 SIREN schemeID FC');
echo "U07 OK\n";

// ---------------------------------------------------------------------------
$currentTest = 'U08 stress arrondis 50 lignes';
lfx_reset();
$inv = new LfxFakeInvoice();
$inv->ref = 'FA2606-0007';
$inv->thirdparty = lfx_make_party();
$inv->lines = [];
for ($i = 0; $i < 50; $i++) {
	// Chaque ligne : 0.33 HT, TVA ligne arrondie par excès à 0.07 (cas Dolibarr)
	$inv->lines[] = lfx_make_line(0.33, 20.0, 0.07, 1.0, 'Article '.$i);
}
// Totaux facture calculés globalement par Dolibarr : 16.50 HT, 3.30 TVA
$inv->total_ht = 16.50;
$inv->total_tva = 3.30;
$inv->total_ttc = 19.80;
$w = [];
$xml = lemonfacturx_build_xml($inv, $mysoc, $w);
lfx_std_validate($xml, $xsdPath);
$xp = lfx_xpath($xml);
lfx_assert_eq('3.30', lfx_xp_str($xp, '//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax/ram:CalculatedAmount'), 'TVA réconciliée (BR-CO-17)');
lfx_assert_eq('3.30', lfx_xp_str($xp, '//ram:TaxTotalAmount'), 'BT-110 réconcilié (BR-CO-14)');
lfx_assert_eq('19.80', lfx_xp_str($xp, '//ram:GrandTotalAmount'), 'BT-112');
lfx_assert(!empty($w), 'warning d\'écart d\'arrondi émis');
echo "U08 OK\n";

// ---------------------------------------------------------------------------
$currentTest = 'U09 finale avec acompte (BT-113 + BG-3)';
lfx_reset([], ['iban' => 'FR7630006000011234567890189', 'bic' => 'AGRIFRPP'], [
	'societe_remise_except' => [(object) ['fk_facture_source' => 9]],
	'FROM '.MAIN_DB_PREFIX.'facture WHERE rowid = 9' => [(object) ['ref' => 'AC2606-0009', 'datef' => '2026-05-01']],
]);
$inv = new LfxFakeInvoice();
$inv->id = 10;
$inv->ref = 'FA2606-0008';
$inv->thirdparty = lfx_make_party();
$inv->lines = [lfx_make_line(1000.00, 20.0, 200.00)];
$inv->total_ht = 1000.00;
$inv->total_tva = 200.00;
$inv->total_ttc = 1200.00;
$inv->sumDepositsUsed = 240.00;
$w = [];
$xml = lemonfacturx_build_xml($inv, $mysoc, $w);
lfx_std_validate($xml, $xsdPath);
$xp = lfx_xpath($xml);
lfx_assert_eq('240.00', lfx_xp_str($xp, '//ram:TotalPrepaidAmount'), 'BT-113 acompte imputé');
lfx_assert_eq('960.00', lfx_xp_str($xp, '//ram:DuePayableAmount'), 'BT-115 = 1200 - 240');
lfx_assert_eq('AC2606-0009', lfx_xp_str($xp, '//ram:InvoiceReferencedDocument/ram:IssuerAssignedID'), 'BG-3 facture d\'acompte');
echo "U09 OK\n";

// ---------------------------------------------------------------------------
$currentTest = 'U10 virement sans IBAN -> PaymentMeans omis (BR-61)';
lfx_reset(['LEMONFACTURX_BANK_ACCOUNT' => 0], null);
$inv = new LfxFakeInvoice();
$inv->ref = 'FA2606-0009';
$inv->thirdparty = lfx_make_party();
$inv->lines = [lfx_make_line(100.00, 20.0, 20.00)];
$inv->total_ht = 100.00;
$inv->total_tva = 20.00;
$inv->total_ttc = 120.00;
$w = [];
$xml = lemonfacturx_build_xml($inv, $mysoc, $w);
lfx_std_validate($xml, $xsdPath);
$xp = lfx_xpath($xml);
lfx_assert_eq(0, $xp->query('//ram:SpecifiedTradeSettlementPaymentMeans')->length, 'bloc PaymentMeans omis');
lfx_assert(!empty($w), 'warning BR-61 émis');
echo "U10 OK\n";

// ---------------------------------------------------------------------------
$currentTest = 'U11 prélèvement SEPA 59 (ICS/RUM/IBAN débiteur)';
lfx_reset([
	'LEMONFACTURX_PAYMENT_MEANS' => '59',
	'PRELEVEMENT_ICS' => 'FR12ZZZ123456',
], ['iban' => 'FR7630006000011234567890189', 'bic' => 'AGRIFRPP'], [
	'societe_rib' => [(object) ['rum' => 'RUM-2026-001', 'iban_prefix' => 'FR7611111111111111111111111']],
]);
$inv = new LfxFakeInvoice();
$inv->ref = 'FA2606-0010';
$inv->thirdparty = lfx_make_party();
$inv->lines = [lfx_make_line(100.00, 20.0, 20.00)];
$inv->total_ht = 100.00;
$inv->total_tva = 20.00;
$inv->total_ttc = 120.00;
$w = [];
$xml = lemonfacturx_build_xml($inv, $mysoc, $w);
lfx_std_validate($xml, $xsdPath);
$xp = lfx_xpath($xml);
lfx_assert_eq('FR12ZZZ123456', lfx_xp_str($xp, '//ram:ApplicableHeaderTradeSettlement/ram:CreditorReferenceID'), 'BT-90 ICS');
lfx_assert_eq('RUM-2026-001', lfx_xp_str($xp, '//ram:SpecifiedTradePaymentTerms/ram:DirectDebitMandateID'), 'BT-89 RUM');
lfx_assert_eq('FR7611111111111111111111111', lfx_xp_str($xp, '//ram:PayerPartyDebtorFinancialAccount/ram:IBANID'), 'BT-91 IBAN débiteur');
echo "U11 OK\n";

// ---------------------------------------------------------------------------
$currentTest = 'U12 quantités/prix décimaux';
lfx_reset();
$inv = new LfxFakeInvoice();
$inv->ref = 'FA2606-0011';
$inv->thirdparty = lfx_make_party();
$inv->lines = [
	lfx_make_line(12.50, 20.0, 2.50, 0.125, 'Lot fractionné'),
	lfx_make_line(100.00, 20.0, 20.00, 3.0, 'Tiers d\'heure'),
];
$inv->total_ht = 112.50;
$inv->total_tva = 22.50;
$inv->total_ttc = 135.00;
$w = [];
$xml = lemonfacturx_build_xml($inv, $mysoc, $w);
lfx_std_validate($xml, $xsdPath);
$xp = lfx_xpath($xml);
lfx_assert_eq('0.125', lfx_xp_str($xp, '(//ram:BilledQuantity)[1]'), 'quantité 4 décimales sans zéros traînants');
lfx_assert_eq('100.00', lfx_xp_str($xp, '(//ram:NetPriceProductTradePrice/ram:ChargeAmount)[1]'), 'prix unitaire 12.50/0.125');
lfx_assert_eq('33.3333', lfx_xp_str($xp, '(//ram:NetPriceProductTradePrice/ram:ChargeAmount)[2]'), 'prix unitaire 100/3 en 4 décimales');
echo "U12 OK\n";

// ---------------------------------------------------------------------------
$currentTest = 'U13 deux catégories au même taux (K + AE à 0%)';
lfx_reset();
$inv = new LfxFakeInvoice();
$inv->ref = 'FA2606-0012';
$inv->thirdparty = lfx_make_party(['country_code' => 'DE', 'tva_intra' => 'DE129273398', 'idprof2' => '']);
$inv->lines = [
	lfx_make_line(1000.00, 0.0, 0.00, 1.0, 'Marchandise', 0),
	lfx_make_line(500.00, 0.0, 0.00, 1.0, 'Installation', 1),
];
$inv->total_ht = 1500.00;
$inv->total_tva = 0.00;
$inv->total_ttc = 1500.00;
$w = [];
$xml = lemonfacturx_build_xml($inv, $mysoc, $w);
lfx_std_validate($xml, $xsdPath);
$xp = lfx_xpath($xml);
lfx_assert_eq(2, $xp->query('//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax')->length, 'deux blocs de ventilation (clé catégorie|taux)');
echo "U13 OK\n";

// ---------------------------------------------------------------------------
$currentTest = 'U14 BT-8 (depuis TAX_MODE) + BT-10 + BT-13';
lfx_reset([
	'TAX_MODE' => 2,
], ['iban' => 'FR7630006000011234567890189', 'bic' => 'AGRIFRPP'], [
	'element_element' => [(object) ['ref' => 'CO2606-0042']],
]);
$inv = new LfxFakeInvoice();
$inv->id = 11;
$inv->ref = 'FA2606-0013';
$inv->ref_client = 'SERVICE-ACHATS-75';
$inv->thirdparty = lfx_make_party();
$inv->lines = [lfx_make_line(1000.00, 20.0, 200.00)];
$inv->total_ht = 1000.00;
$inv->total_tva = 200.00;
$inv->total_ttc = 1200.00;
$w = [];
$xml = lemonfacturx_build_xml($inv, $mysoc, $w);
lfx_std_validate($xml, $xsdPath);
$xp = lfx_xpath($xml);
// BT-23 (BR-FR-08) : cadre socle émis en profil PDP — B1 = dépôt fournisseur de biens
lfx_assert_eq('B1', lfx_xp_str($xp, '//ram:BusinessProcessSpecifiedDocumentContextParameter/ram:ID'), 'BT-23 socle B1 en profil PDP (BR-FR-08)');
lfx_assert_eq('72', lfx_xp_str($xp, '//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax/ram:DueDateTypeCode'), 'BT-8 = 72 (TAX_MODE=2 encaissements)');
lfx_assert_eq('SERVICE-ACHATS-75', lfx_xp_str($xp, '//ram:BuyerReference'), 'BT-10 ref client');
lfx_assert_eq('CO2606-0042', lfx_xp_str($xp, '//ram:BuyerOrderReferencedDocument/ram:IssuerAssignedID'), 'BT-13 commande liée');
echo "U14 OK\n";

// ---------------------------------------------------------------------------
$currentTest = 'U15 rectificative (384) + situation (warning)';
lfx_reset([], ['iban' => 'FR7630006000011234567890189', 'bic' => 'AGRIFRPP'], [
	'FROM '.MAIN_DB_PREFIX.'facture WHERE rowid = 7' => [(object) ['ref' => 'FA2605-0007', 'datef' => '2026-05-02']],
]);
$inv = new LfxFakeInvoice();
$inv->ref = 'FA2606-0014';
$inv->type = 1; // TYPE_REPLACEMENT
$inv->fk_facture_source = 7;
$inv->thirdparty = lfx_make_party();
$inv->lines = [lfx_make_line(1000.00, 20.0, 200.00)];
$inv->total_ht = 1000.00;
$inv->total_tva = 200.00;
$inv->total_ttc = 1200.00;
$w = [];
$xml = lemonfacturx_build_xml($inv, $mysoc, $w);
lfx_std_validate($xml, $xsdPath);
$xp = lfx_xpath($xml);
lfx_assert_eq('384', lfx_xp_str($xp, '//rsm:ExchangedDocument/ram:TypeCode'), 'TypeCode 384 rectificative');
lfx_assert_eq('FA2605-0007', lfx_xp_str($xp, '//ram:InvoiceReferencedDocument/ram:IssuerAssignedID'), 'BG-3 facture remplacée');

$inv->type = 5; // TYPE_SITUATION
$inv->fk_facture_source = null;
$w = [];
$xml = lemonfacturx_build_xml($inv, $mysoc, $w);
$xp = lfx_xpath($xml);
lfx_assert_eq('380', lfx_xp_str($xp, '//rsm:ExchangedDocument/ram:TypeCode'), 'situation en 380');
lfx_assert(!empty($w), 'warning situation émis');
echo "U15 OK\n";

// ---------------------------------------------------------------------------
$currentTest = 'U16 multidevise refusée + BG-14 période';
lfx_reset();
$inv = new LfxFakeInvoice();
$inv->multicurrency_code = 'USD';
$err = lemonfacturx_check_supported($inv);
lfx_assert($err !== null, 'multidevise détectée comme non supportée');
$inv->multicurrency_code = 'EUR';
lfx_assert(lemonfacturx_check_supported($inv) === null, 'EUR = devise société acceptée');

$inv2 = new LfxFakeInvoice();
$inv2->ref = 'FA2606-0015';
$inv2->thirdparty = lfx_make_party();
$line = lfx_make_line(1000.00, 20.0, 200.00);
$line->date_start = mktime(0, 0, 0, 5, 1, 2026);
$line->date_end = mktime(0, 0, 0, 5, 31, 2026);
$inv2->lines = [$line];
$inv2->total_ht = 1000.00;
$inv2->total_tva = 200.00;
$inv2->total_ttc = 1200.00;
$w = [];
$xml = lemonfacturx_build_xml($inv2, $mysoc, $w);
lfx_std_validate($xml, $xsdPath);
$xp = lfx_xpath($xml);
lfx_assert_eq('20260501', lfx_xp_str($xp, '//ram:BillingSpecifiedPeriod/ram:StartDateTime/udt:DateTimeString'), 'BG-14 début');
lfx_assert_eq('20260531', lfx_xp_str($xp, '//ram:BillingSpecifiedPeriod/ram:EndDateTime/udt:DateTimeString'), 'BG-14 fin');
echo "U16 OK\n";

// ---------------------------------------------------------------------------
$currentTest = 'U17 validateur BR détecte les violations';
// XML volontairement faux : prix négatif + total incohérent
lfx_reset();
$inv = new LfxFakeInvoice();
$inv->ref = 'FA2606-0016';
$inv->thirdparty = lfx_make_party();
$inv->lines = [
	lfx_make_line(-50.00, 20.0, -10.00, 1.0, 'Remise orpheline'),
	lfx_make_line(-25.00, 20.0, -5.00, 1.0, 'Autre remise'),
];
$inv->total_ht = -75.00;
$inv->total_tva = -15.00;
$inv->total_ttc = -90.00;
$w = [];
$xml = lemonfacturx_build_xml($inv, $mysoc, $w); // 380 entièrement négatif : lignes ré-émises brutes
$violations = lemonfacturx_validate_business_rules($xml);
lfx_assert(!empty($violations), 'violations détectées sur facture entièrement négative');
$hasBr27 = false;
foreach ($violations as $v) {
	if (strpos($v, 'BR-27') === 0) {
		$hasBr27 = true;
	}
}
lfx_assert($hasBr27, 'BR-27 détectée');
lfx_assert(!empty($w), 'warning facture entièrement négative émis');
echo "U17 OK\n";

// ---------------------------------------------------------------------------
$currentTest = 'U19 franchise avec TVA réelle -> S (pas E contradictoire)';
lfx_reset();
$mysocFranchise2 = lfx_make_party(['name' => 'EX-MICRO', 'idprof2' => '90945830600012', 'tva_intra' => 'FR38909458306', 'tva_assuj' => 0]);
$inv = new LfxFakeInvoice();
$inv->ref = 'FA2606-0017';
$inv->thirdparty = lfx_make_party();
$inv->lines = [lfx_make_line(1000.00, 20.0, 200.00)]; // ancienne facture régénérée après passage en franchise
$inv->total_ht = 1000.00;
$inv->total_tva = 200.00;
$inv->total_ttc = 1200.00;
$w = [];
$xml = lemonfacturx_build_xml($inv, $mysocFranchise2, $w);
lfx_std_validate($xml, $xsdPath);
$xp = lfx_xpath($xml);
lfx_assert_eq('S', lfx_xp_str($xp, '//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax/ram:CategoryCode'), 'ligne avec TVA réelle reste en S (BR-E-05)');
echo "U19 OK\n";

// ---------------------------------------------------------------------------
$currentTest = 'U20 acheteur étranger : idprof2 non publié en SIREN/SIRET';
lfx_reset();
$inv = new LfxFakeInvoice();
$inv->ref = 'FA2606-0018';
$inv->thirdparty = lfx_make_party(['country_code' => 'DE', 'tva_intra' => 'DE129273398', 'idprof2' => 'HRB 123456789']);
$inv->lines = [lfx_make_line(2000.00, 0.0, 0.00, 1.0, 'Marchandise', 0)];
$inv->total_ht = 2000.00;
$inv->total_tva = 0.00;
$inv->total_ttc = 2000.00;
$w = [];
$xml = lemonfacturx_build_xml($inv, $mysoc, $w);
lfx_std_validate($xml, $xsdPath);
$xp = lfx_xpath($xml);
lfx_assert_eq(0, $xp->query('//ram:BuyerTradeParty/ram:SpecifiedLegalOrganization')->length, 'pas de SpecifiedLegalOrganization avec un identifiant local étranger');
lfx_assert_eq('EM', lfx_xp_str($xp, '//ram:BuyerTradeParty/ram:URIUniversalCommunication/ram:URIID/@schemeID'), 'endpoint en repli email (pas de faux SIREN 0225)');
echo "U20 OK\n";

// ---------------------------------------------------------------------------
$currentTest = 'U21 taxes locales refusées proprement';
lfx_reset();
$inv = new LfxFakeInvoice();
$inv->total_localtax1 = 12.50;
lfx_assert(lemonfacturx_check_supported($inv) !== null, 'localtax détectée comme non supportée');
$inv->total_localtax1 = 0.0;
lfx_assert(lemonfacturx_check_supported($inv) === null, 'sans localtax : supporté');
echo "U21 OK\n";

// ---------------------------------------------------------------------------
$currentTest = 'U18 fonctions de formatage';
lfx_assert_eq('10', lemonfacturx_format_qty(10.0), 'qty entière');
lfx_assert_eq('0.125', lemonfacturx_format_qty(0.125), 'qty 3 décimales');
lfx_assert_eq('2.5', lemonfacturx_format_qty(2.50), 'qty zéros retirés');
lfx_assert_eq('100.00', lemonfacturx_format_unit_price(100.0), 'prix 2 décimales');
lfx_assert_eq('33.3333', lemonfacturx_format_unit_price(100 / 3), 'prix 4 décimales');
lfx_assert_eq('1234.50', lemonfacturx_format_amount(1234.5), 'montant');
lfx_assert_eq('a &amp; b &lt;c&gt;', lemonfacturx_xml_encode('a & b <c>'), 'échappement XML');
lfx_assert_eq('123456789', lemonfacturx_extract_siren('12345678900011'), 'SIREN depuis SIRET');
echo "U18 OK\n";

// ---------------------------------------------------------------------------
$currentTest = 'U22 BR-FR-01/02 identifiant de facture (BT-1)';
lfx_reset();
$inv = new LfxFakeInvoice();
$inv->ref = 'FA2606-0001-AVEC-UNE-REFERENCE-BEAUCOUP-TROP-LONGUE'; // 51 caractères
$inv->thirdparty = lfx_make_party();
$inv->lines = [lfx_make_line(100.00, 20.0, 20.00)];
$inv->total_ht = 100.00;
$inv->total_tva = 20.00;
$inv->total_ttc = 120.00;
$w = [];
$violations = lemonfacturx_validate_business_rules(lemonfacturx_build_xml($inv, $mysoc, $w));
lfx_assert(lfx_contains($violations, 'BR-FR-01'), 'BR-FR-01 détectée (BT-1 > 35 caractères)');
lfx_assert(!lfx_contains($violations, 'BR-FR-02'), 'pas de BR-FR-02 (charset valide)');
lfx_assert(lfx_contains(lemonfacturx_check_mandatory($inv, $mysoc), 'LemonFacturXWarnRefTooLong'), 'warning amont BT-1 trop long (check_mandatory)');

$inv->ref = 'FA 2606 (BIS)'; // espace et parenthèses interdits
$w = [];
$violations = lemonfacturx_validate_business_rules(lemonfacturx_build_xml($inv, $mysoc, $w));
lfx_assert(lfx_contains($violations, 'BR-FR-02'), 'BR-FR-02 détectée (espace/parenthèses dans BT-1)');
lfx_assert(lfx_contains(lemonfacturx_check_mandatory($inv, $mysoc), 'LemonFacturXWarnRefCharset'), 'warning amont charset BT-1 (check_mandatory)');

$inv->ref = 'FA/2606+01_A'; // - + _ / admis par BR-FR-02
$w = [];
$violations = lemonfacturx_validate_business_rules(lemonfacturx_build_xml($inv, $mysoc, $w));
lfx_assert(!lfx_contains($violations, 'BR-FR-01') && !lfx_contains($violations, 'BR-FR-02'), 'référence avec - + _ / acceptée');
echo "U22 OK\n";

// ---------------------------------------------------------------------------
$currentTest = 'U23 BR-FR-09 cohérence SIREN/SIRET';
lfx_reset();
$inv = new LfxFakeInvoice();
$inv->ref = 'FA2606-0020';
$inv->thirdparty = lfx_make_party();
$inv->lines = [lfx_make_line(100.00, 20.0, 20.00)];
$inv->total_ht = 100.00;
$inv->total_tva = 20.00;
$inv->total_ttc = 120.00;
// Vendeur : SIREN saisi (idprof1) sans rapport avec le SIRET (idprof2)
$socBad = lfx_make_party(['name' => 'INCOHERENT SARL', 'idprof1' => '111222333', 'idprof2' => '99988877700012', 'tva_intra' => 'FR00111222333']);
$w = [];
$violations = lemonfacturx_validate_business_rules(lemonfacturx_build_xml($inv, $socBad, $w));
lfx_assert(lfx_contains($violations, 'BR-FR-09'), 'BR-FR-09 détectée (SIRET vendeur ≠ SIREN)');
lfx_assert(lfx_contains(lemonfacturx_check_mandatory($inv, $socBad), 'LemonFacturXWarnSellerSirenSiretMismatch'), 'warning amont vendeur (check_mandatory)');
// Acheteur : même incohérence côté tiers
$inv->thirdparty = lfx_make_party(['idprof1' => '555666777', 'idprof2' => '12345678900011']);
$w = [];
$violations = lemonfacturx_validate_business_rules(lemonfacturx_build_xml($inv, $mysoc, $w));
lfx_assert(lfx_contains($violations, 'BR-FR-09'), 'BR-FR-09 détectée (SIRET acheteur ≠ SIREN)');
lfx_assert(lfx_contains(lemonfacturx_check_mandatory($inv, $mysoc), 'LemonFacturXWarnBuyerSirenSiretMismatch'), 'warning amont acheteur (check_mandatory)');
// Cohérent : aucune violation BR-FR-09
$inv->thirdparty = lfx_make_party();
$w = [];
$violations = lemonfacturx_validate_business_rules(lemonfacturx_build_xml($inv, $mysoc, $w));
lfx_assert(!lfx_contains($violations, 'BR-FR-09'), 'pas de BR-FR-09 quand SIREN et SIRET concordent');
echo "U23 OK\n";

// ---------------------------------------------------------------------------
$currentTest = 'U24 BR-FR-CO-04/CO-05 avoir et rectificative sans facture d\'origine';
lfx_reset();
$inv = new LfxFakeInvoice();
$inv->ref = 'AV2606-0002';
$inv->type = 2; // TYPE_CREDIT_NOTE, sans fk_facture_source
$inv->thirdparty = lfx_make_party();
$inv->lines = [lfx_make_line(-500.00, 20.0, -100.00)];
$inv->total_ht = -500.00;
$inv->total_tva = -100.00;
$inv->total_ttc = -600.00;
$w = [];
$violations = lemonfacturx_validate_business_rules(lemonfacturx_build_xml($inv, $mysoc, $w));
lfx_assert(lfx_contains($violations, 'BR-FR-CO-05'), 'BR-FR-CO-05 : avoir sans référence BT-25 bloqué');
lfx_assert(lfx_contains($w, 'LemonFacturXWarnCreditNoteNoSource'), 'warning avoir sans origine conservé');

$inv2 = new LfxFakeInvoice();
$inv2->ref = 'FA2606-0021';
$inv2->type = 1; // TYPE_REPLACEMENT, sans fk_facture_source
$inv2->thirdparty = lfx_make_party();
$inv2->lines = [lfx_make_line(500.00, 20.0, 100.00)];
$inv2->total_ht = 500.00;
$inv2->total_tva = 100.00;
$inv2->total_ttc = 600.00;
$w2 = [];
$violations = lemonfacturx_validate_business_rules(lemonfacturx_build_xml($inv2, $mysoc, $w2));
lfx_assert(lfx_contains($violations, 'BR-FR-CO-04'), 'BR-FR-CO-04 : rectificative sans référence BT-25 bloquée');
lfx_assert(lfx_contains($w2, 'LemonFacturXWarnCorrectiveNoSource'), 'warning rectificative sans origine émis');
echo "U24 OK\n";

// ---------------------------------------------------------------------------
$currentTest = 'U24bis rectificative 384 + acompte imputé : une seule référence BG-3';
lfx_reset([], ['iban' => 'FR7630006000011234567890189', 'bic' => 'AGRIFRPP'], [
	'societe_remise_except' => [(object) ['fk_facture_source' => 9]],
	'FROM '.MAIN_DB_PREFIX.'facture WHERE rowid = 9' => [(object) ['ref' => 'AC2606-0009', 'datef' => '2026-05-01']],
	'FROM '.MAIN_DB_PREFIX.'facture WHERE rowid = 42' => [(object) ['ref' => 'FA2605-0099', 'datef' => '2026-05-12']],
]);
$inv = new LfxFakeInvoice();
$inv->id = 11;
$inv->ref = 'FA2606-0027';
$inv->type = 1; // TYPE_REPLACEMENT → 384
$inv->fk_facture_source = 42; // facture remplacée
$inv->thirdparty = lfx_make_party();
$inv->lines = [lfx_make_line(1000.00, 20.0, 200.00)];
$inv->total_ht = 1000.00;
$inv->total_tva = 200.00;
$inv->total_ttc = 1200.00;
$inv->sumDepositsUsed = 240.00; // acompte consommé via remise exceptionnelle
$w = [];
$xml = lemonfacturx_build_xml($inv, $mysoc, $w);
$violations = lfx_std_validate($xml, $xsdPath);
lfx_assert(!lfx_contains($violations, 'BR-FR-CO-04'), 'pas de fausse violation BR-FR-CO-04 sur une 384 avec acompte imputé');
$xp = lfx_xpath($xml);
lfx_assert_eq('384', lfx_xp_str($xp, '//rsm:ExchangedDocument/ram:TypeCode'), 'TypeCode 384');
lfx_assert_eq(1, $xp->query('//ram:InvoiceReferencedDocument')->length, 'une et une seule référence BT-25 (BR-FR-CO-04)');
lfx_assert_eq('FA2605-0099', lfx_xp_str($xp, '//ram:InvoiceReferencedDocument/ram:IssuerAssignedID'), 'BG-3 = la facture remplacée (pas l\'acompte)');
lfx_assert_eq('20260512', lfx_xp_str($xp, '//ram:InvoiceReferencedDocument/ram:FormattedIssueDateTime/qdt:DateTimeString'), 'BG-3 date de la facture remplacée (BT-26)');
lfx_assert_eq('240.00', lfx_xp_str($xp, '//ram:TotalPrepaidAmount'), 'BT-113 acompte imputé conservé');
lfx_assert_eq('960.00', lfx_xp_str($xp, '//ram:DuePayableAmount'), 'BT-115 = 1200 - 240');
echo "U24bis OK\n";

// ---------------------------------------------------------------------------
$currentTest = 'U25 BT-23 socle (BR-FR-08) : S1/M1/B4 et garde acompte';
lfx_reset();
$bt23Query = '//ram:BusinessProcessSpecifiedDocumentContextParameter/ram:ID';
// Services uniquement → S1
$inv = new LfxFakeInvoice();
$inv->ref = 'FA2606-0022';
$inv->thirdparty = lfx_make_party();
$inv->lines = [lfx_make_line(1000.00, 20.0, 200.00, 1.0, 'Prestation', 1)];
$inv->total_ht = 1000.00;
$inv->total_tva = 200.00;
$inv->total_ttc = 1200.00;
$w = [];
$xml = lemonfacturx_build_xml($inv, $mysoc, $w);
lfx_std_validate($xml, $xsdPath);
lfx_assert_eq('S1', lfx_xp_str(lfx_xpath($xml), $bt23Query), 'BT-23 = S1 (services)');
// Biens + services → M1
$inv->lines = [
	lfx_make_line(600.00, 20.0, 120.00, 1.0, 'Marchandise', 0),
	lfx_make_line(400.00, 20.0, 80.00, 1.0, 'Installation', 1),
];
$w = [];
lfx_assert_eq('M1', lfx_xp_str(lfx_xpath(lemonfacturx_build_xml($inv, $mysoc, $w)), $bt23Query), 'BT-23 = M1 (double)');
// Facture définitive après acompte → B4
$inv->lines = [lfx_make_line(1000.00, 20.0, 200.00, 1.0, 'Marchandise', 0)];
$inv->sumDepositsUsed = 240.00;
$w = [];
lfx_assert_eq('B4', lfx_xp_str(lfx_xpath(lemonfacturx_build_xml($inv, $mysoc, $w)), $bt23Query), 'BT-23 = B4 (définitive après acompte)');
// Facture d'acompte (386) : jamais de cadre x4 (BR-FR-CO-08)
$inv->type = 3; // TYPE_DEPOSIT
$w = [];
lfx_assert_eq('B1', lfx_xp_str(lfx_xpath(lemonfacturx_build_xml($inv, $mysoc, $w)), $bt23Query), 'BT-23 = B1 sur une facture d\'acompte (garde BR-FR-CO-08)');
echo "U25 OK\n";

// ---------------------------------------------------------------------------
$currentTest = 'U26 note BAR (BR-FR-07/20/31)';
lfx_reset();
$barQuery = '//ram:IncludedNote[ram:SubjectCode="BAR"]/ram:Content';
$inv = new LfxFakeInvoice();
$inv->ref = 'FA2606-0023';
$inv->thirdparty = lfx_make_party();
$inv->lines = [lfx_make_line(1000.00, 20.0, 200.00)];
$inv->total_ht = 1000.00;
$inv->total_tva = 200.00;
$inv->total_ttc = 1200.00;
$w = [];
$xml = lemonfacturx_build_xml($inv, $mysoc, $w);
lfx_std_validate($xml, $xsdPath);
$xp = lfx_xpath($xml);
lfx_assert_eq('B2B', lfx_xp_str($xp, $barQuery), 'note BAR = B2B (acheteur assujetti FR)');
lfx_assert_eq(1, $xp->query('//ram:IncludedNote[ram:SubjectCode="BAR"]')->length, 'une seule note BAR (BR-FR-31)');
// Acheteur assujetti UE → B2BINT (e-reporting international)
$inv->thirdparty = lfx_make_party(['country_code' => 'DE', 'tva_intra' => 'DE129273398', 'idprof2' => '']);
$inv->lines = [lfx_make_line(1000.00, 0.0, 0.00, 1.0, 'Prestation', 1)];
$inv->total_tva = 0.00;
$inv->total_ttc = 1000.00;
$inv->total_ht = 1000.00;
$w = [];
lfx_assert_eq('B2BINT', lfx_xp_str(lfx_xpath(lemonfacturx_build_xml($inv, $mysoc, $w)), $barQuery), 'note BAR = B2BINT (acheteur UE)');
// Particulier → B2C (e-reporting B2C)
$inv->thirdparty = lfx_make_party(['typent_code' => 'TE_PRIVATE', 'idprof2' => '', 'tva_intra' => '']);
$inv->lines = [lfx_make_line(1000.00, 20.0, 200.00)];
$inv->total_tva = 200.00;
$inv->total_ttc = 1200.00;
$w = [];
lfx_assert_eq('B2C', lfx_xp_str(lfx_xpath(lemonfacturx_build_xml($inv, $mysoc, $w)), $barQuery), 'note BAR = B2C (particulier)');
// Particulier étranger → B2C aussi : choix assumé, la liste fermée BR-FR-20
// (révision juillet 2025) ne comporte pas de valeur « B2C international »
// (cf. docblock de lemonfacturx_resolve_bar_code)
$inv->thirdparty = lfx_make_party(['typent_code' => 'TE_PRIVATE', 'country_code' => 'DE', 'idprof2' => '', 'tva_intra' => '']);
$w = [];
lfx_assert_eq('B2C', lfx_xp_str(lfx_xpath(lemonfacturx_build_xml($inv, $mysoc, $w)), $barQuery), 'note BAR = B2C (particulier étranger — pas de B2CINT dans la liste fermée BR-FR-20)');
// Profil Chorus B2G : pas de note BAR (marquage B2G distinct, chemin inchangé)
$inv->thirdparty = lfx_make_party();
$w = [];
$xmlChorus = lemonfacturx_build_xml($inv, $mysoc, $w, ['profile' => 'choruspro']);
lfx_assert_eq(0, lfx_xpath($xmlChorus)->query('//ram:IncludedNote[ram:SubjectCode="BAR"]')->length, 'pas de note BAR en profil Chorus');
echo "U26 OK\n";

// ---------------------------------------------------------------------------
$currentTest = 'U27 suffixe endpoint vendeur sanitisé (BR-FR-23)';
lfx_reset(['LEMONFACTURX_ENDPOINT_SUFFIX_SELLER' => '_Status!*']);
$inv = new LfxFakeInvoice();
$inv->ref = 'FA2606-0024';
$inv->thirdparty = lfx_make_party();
$inv->lines = [lfx_make_line(100.00, 20.0, 20.00)];
$inv->total_ht = 100.00;
$inv->total_tva = 20.00;
$inv->total_ttc = 120.00;
$w = [];
$xml = lemonfacturx_build_xml($inv, $mysoc, $w);
lfx_std_validate($xml, $xsdPath);
lfx_assert_eq('909458306_Status', lfx_xp_str(lfx_xpath($xml), '//ram:SellerTradeParty/ram:URIUniversalCommunication/ram:URIID'), 'suffixe sanitisé : « !* » retirés (BR-FR-23)');
lfx_assert(lfx_contains(lemonfacturx_check_mandatory($inv, $mysoc), 'LemonFacturXWarnEndpointSuffixCharset'), 'warning charset du suffixe (check_mandatory)');
// Suffixe déjà conforme : conservé tel quel, aucun warning charset
lfx_reset(['LEMONFACTURX_ENDPOINT_SUFFIX_SELLER' => '_Status']);
$w = [];
lfx_assert_eq('909458306_Status', lfx_xp_str(lfx_xpath(lemonfacturx_build_xml($inv, $mysoc, $w)), '//ram:SellerTradeParty/ram:URIUniversalCommunication/ram:URIID'), 'suffixe conforme conservé');
lfx_assert(!lfx_contains(lemonfacturx_check_mandatory($inv, $mysoc), 'LemonFacturXWarnEndpointSuffixCharset'), 'pas de warning sur suffixe conforme');
echo "U27 OK\n";

// ---------------------------------------------------------------------------
$currentTest = 'U28 BR-FR-04 type de document hors liste FR';
lfx_reset();
$inv = new LfxFakeInvoice();
$inv->ref = 'FA2606-0025';
$inv->thirdparty = lfx_make_party();
$inv->lines = [lfx_make_line(100.00, 20.0, 20.00)];
$inv->total_ht = 100.00;
$inv->total_tva = 20.00;
$inv->total_ttc = 120.00;
$w = [];
$xml = lemonfacturx_build_xml($inv, $mysoc, $w);
lfx_assert(!lfx_contains(lemonfacturx_validate_business_rules($xml), 'BR-FR-04'), '380 admis par BR-FR-04');
// XML altéré : 751 (facture d'information, UNTDID 1001) est proscrit en France
$xmlBad = str_replace('<ram:TypeCode>380</ram:TypeCode>', '<ram:TypeCode>751</ram:TypeCode>', $xml);
lfx_assert(lfx_contains(lemonfacturx_validate_business_rules($xmlBad), 'BR-FR-04'), 'BR-FR-04 détectée (TypeCode 751 hors liste)');
echo "U28 OK\n";

// ---------------------------------------------------------------------------
$currentTest = 'U29 BR-FR-16 taux de TVA hors liste FR (warning)';
lfx_reset();
$inv = new LfxFakeInvoice();
$inv->ref = 'FA2606-0026';
$inv->thirdparty = lfx_make_party();
$inv->lines = [lfx_make_line(100.00, 19.0, 19.00)]; // 19 % : pas un taux français
$inv->total_ht = 100.00;
$inv->total_tva = 19.00;
$inv->total_ttc = 119.00;
lfx_assert(lfx_contains(lemonfacturx_check_mandatory($inv, $mysoc), 'LemonFacturXWarnVatRateNotFR'), 'warning BR-FR-16 sur un taux à 19 %');
$inv->lines = [lfx_make_line(100.00, 8.5, 8.50)]; // 8,5 % : taux DOM légitime
$inv->total_tva = 8.50;
$inv->total_ttc = 108.50;
lfx_assert(!lfx_contains(lemonfacturx_check_mandatory($inv, $mysoc), 'LemonFacturXWarnVatRateNotFR'), 'pas de warning sur le taux DOM 8,5 %');
// Jamais bloquant : le validateur ne porte aucune violation BR-FR-16
$w = [];
lfx_assert(!lfx_contains(lemonfacturx_validate_business_rules(lemonfacturx_build_xml($inv, $mysoc, $w)), 'BR-FR-16'), 'BR-FR-16 non bloquante (warning uniquement)');
echo "U29 OK\n";

// ---------------------------------------------------------------------------
$currentTest = 'U30 vendeur non français : règles BR-FR non opposables';
lfx_reset();
$mysocDE = lfx_make_party(['name' => 'ACME GMBH', 'country_code' => 'DE', 'idprof1' => '', 'idprof2' => '', 'tva_intra' => 'DE129273398', 'zip' => '10115', 'town' => 'Berlin']);
$inv = new LfxFakeInvoice();
$inv->ref = 'RE 2026 0042'; // espaces : violerait BR-FR-02 pour un vendeur FR
$inv->type = 1; // rectificative sans facture d'origine : violerait BR-FR-CO-04
$inv->thirdparty = lfx_make_party();
$inv->lines = [lfx_make_line(100.00, 20.0, 20.00)];
$inv->total_ht = 100.00;
$inv->total_tva = 20.00;
$inv->total_ttc = 120.00;
$w = [];
$violations = lemonfacturx_validate_business_rules(lemonfacturx_build_xml($inv, $mysocDE, $w));
lfx_assert(!lfx_contains($violations, 'BR-FR'), 'aucune violation BR-FR pour un vendeur DE ('.implode(' ; ', $violations).')');
// Contre-épreuve : même facture avec vendeur FR → le bloc BR-FR s'applique
$w = [];
$violations = lemonfacturx_validate_business_rules(lemonfacturx_build_xml($inv, $mysoc, $w));
lfx_assert(lfx_contains($violations, 'BR-FR-02'), 'contre-épreuve vendeur FR : BR-FR-02 détectée');
lfx_assert(lfx_contains($violations, 'BR-FR-CO-04'), 'contre-épreuve vendeur FR : BR-FR-CO-04 détectée');
echo "U30 OK\n";

// ---------------------------------------------------------------------------
echo "\n=== Résultat ===\n";
echo "Passed : $passed\n";
echo "Failed : $failed\n";
if (!empty($failures)) {
	echo "\nDétail des échecs :\n";
	foreach ($failures as $f) {
		echo "  - $f\n";
	}
}
exit($failed > 0 ? 1 : 0);
