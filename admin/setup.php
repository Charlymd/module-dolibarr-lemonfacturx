<?php
/*
 * Copyright (C) 2026 SASU LEMON <https://hellolemon.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Page de configuration du module LemonFacturX
 */

// Charger l'environnement Dolibarr
$res = 0;
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
require_once dol_buildpath('/lemonfacturx/core/lib/lemonfacturx.lib.php');

// Sécurité
if (!$user->admin) {
	accessforbidden();
}

$langs->loadLangs(["admin", "lemonfacturx@lemonfacturx"]);

$action = GETPOST('action', 'aZ09');

// Les valeurs par défaut des mentions légales BR-FR sont définies dans la lib
// (LEMONFACTURX_DEFAULT_NOTE_*) pour rester synchronisées avec le builder XML.

// Sauvegarde des paramètres
if ($action == 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
	// CSRF : vérifier le token courant (pas newToken() qui génère un futur token)
	if (GETPOST('token', 'alpha') !== currentToken()) {
		accessforbidden('Bad value for CSRF token');
	}
	$error = 0;

	$updates = [
		['LEMONFACTURX_ENABLED',      GETPOSTINT('LEMONFACTURX_ENABLED'),         'int'],
		['LEMONFACTURX_BANK_ACCOUNT', GETPOSTINT('LEMONFACTURX_BANK_ACCOUNT'),    'int'],
		['LEMONFACTURX_PAYMENT_MEANS',trim(GETPOST('LEMONFACTURX_PAYMENT_MEANS', 'alpha')), 'chaine'],
		['LEMONFACTURX_ENDPOINT_SCHEME',trim(GETPOST('LEMONFACTURX_ENDPOINT_SCHEME', 'alpha')), 'chaine'],
		['LEMONFACTURX_LEGAL_ID_SCHEME',trim(GETPOST('LEMONFACTURX_LEGAL_ID_SCHEME', 'alpha')), 'chaine'],
		['LEMONFACTURX_VAT_DUE_DATE_TYPE',trim(GETPOST('LEMONFACTURX_VAT_DUE_DATE_TYPE', 'alpha')), 'chaine'],
		['LEMONFACTURX_BT23_PROCESS', trim(GETPOST('LEMONFACTURX_BT23_PROCESS', 'alphanohtml')), 'chaine'],
		['LEMONFACTURX_STRICT_MODE',  GETPOSTINT('LEMONFACTURX_STRICT_MODE'),     'int'],
		['LEMONFACTURX_BR_CHECK',     GETPOSTINT('LEMONFACTURX_BR_CHECK'),        'int'],
		['LEMONFACTURX_PHP_CLI_PATH', trim(GETPOST('LEMONFACTURX_PHP_CLI_PATH', 'alphanohtml')), 'chaine'],
		['LEMONFACTURX_VERAPDF_PATH', trim(GETPOST('LEMONFACTURX_VERAPDF_PATH', 'alphanohtml')), 'chaine'],
		['LEMONFACTURX_NOTE_PMD',     trim(GETPOST('LEMONFACTURX_NOTE_PMD', 'restricthtml')),    'chaine'],
		['LEMONFACTURX_NOTE_PMT',     trim(GETPOST('LEMONFACTURX_NOTE_PMT', 'restricthtml')),    'chaine'],
		['LEMONFACTURX_NOTE_AAB',     trim(GETPOST('LEMONFACTURX_NOTE_AAB', 'restricthtml')),    'chaine'],
	];
	foreach ($updates as $u) {
		if (dolibarr_set_const($db, $u[0], $u[1], $u[2], 0, '', $conf->entity) < 0) {
			$error++;
		}
	}

	if (!$error) {
		setEventMessages($langs->trans("SetupSaved"), null, 'mesgs');
	} else {
		setEventMessages($langs->trans("Error"), null, 'errors');
	}
}

// Affichage
llxHeader('', $langs->trans("LemonFacturXSetup"));

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($langs->trans("LemonFacturXSetup"), $linkback, 'title_setup');

// Bandeau "Nouvelle version disponible" si le check GitHub remonte une version > locale
require_once dirname(__DIR__).'/core/modules/modLemonFacturX.class.php';
$modDesc = new modLemonFacturX($db);
$updateInfo = lemonfacturx_check_latest_release($db, $modDesc->version);
if ($updateInfo !== null) {
	print '<div class="warning" style="margin:8px 0;padding:10px;border-left:4px solid #e67e22;background:#fff3e0;">';
	print '<strong>'.$langs->trans("LemonFacturXUpdateAvailable").'</strong> : ';
	print $langs->trans("LemonFacturXUpdateAvailableMsg", dol_escape_htmltag($updateInfo['version']), dol_escape_htmltag($modDesc->version));
	print ' <a href="'.dol_escape_htmltag($updateInfo['url']).'" target="_blank" rel="noopener">'.$langs->trans("LemonFacturXUpdateSeeRelease").'</a>';
	print '</div>';
}

print '<form method="POST" action="'.dol_escape_htmltag($_SERVER["PHP_SELF"]).'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="update">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans("Parameter").'</td>';
print '<td>'.$langs->trans("Value").'</td>';
print '</tr>';

// Activer/Désactiver
print '<tr class="oddeven">';
print '<td>'.$langs->trans("LemonFacturXEnabled").'</td>';
print '<td>';
print '<select name="LEMONFACTURX_ENABLED" class="flat">';
print '<option value="0"'.(!getDolGlobalInt('LEMONFACTURX_ENABLED') ? ' selected' : '').'>'.$langs->trans("No").'</option>';
print '<option value="1"'.(getDolGlobalInt('LEMONFACTURX_ENABLED') ? ' selected' : '').'>'.$langs->trans("Yes").'</option>';
print '</select>';
print '</td>';
print '</tr>';

// Compte bancaire (IBAN/BIC)
print '<tr class="oddeven">';
print '<td>'.$langs->trans("LemonFacturXBankAccount").'</td>';
print '<td>';
$currentBankAccount = getDolGlobalInt('LEMONFACTURX_BANK_ACCOUNT');
$sql = "SELECT rowid, label, iban_prefix, bic FROM ".MAIN_DB_PREFIX."bank_account WHERE clos = 0 AND entity IN (".getEntity('bank_account').") ORDER BY label";
$resql = $db->query($sql);
print '<select name="LEMONFACTURX_BANK_ACCOUNT" class="flat minwidth300">';
print '<option value="0">-- '.$langs->trans("Select").' --</option>';
if ($resql) {
	while ($obj = $db->fetch_object($resql)) {
		$infoIban = !empty($obj->iban_prefix) ? ' ('.lemonfacturx_iban_short($obj->iban_prefix).')' : ' (pas d\'IBAN)';
		print '<option value="'.$obj->rowid.'"'.($currentBankAccount == $obj->rowid ? ' selected' : '').'>';
		print dol_escape_htmltag($obj->label.$infoIban);
		print '</option>';
	}
}
print '</select>';
print '</td>';
print '</tr>';

// Moyen de paiement
print '<tr class="oddeven">';
print '<td>'.$langs->trans("LemonFacturXPaymentMeans").'</td>';
print '<td>';
$currentMeans = getDolGlobalString('LEMONFACTURX_PAYMENT_MEANS', '30');
print '<select name="LEMONFACTURX_PAYMENT_MEANS" class="flat">';
print '<option value="30"'.($currentMeans == '30' ? ' selected' : '').'>30 - '.$langs->trans("PaymentMeans30").'</option>';
print '<option value="58"'.($currentMeans == '58' ? ' selected' : '').'>58 - '.$langs->trans("PaymentMeans58").'</option>';
print '<option value="59"'.($currentMeans == '59' ? ' selected' : '').'>59 - '.$langs->trans("PaymentMeans59").'</option>';
print '<option value="49"'.($currentMeans == '49' ? ' selected' : '').'>49 - '.$langs->trans("PaymentMeans49").'</option>';
print '</select>';
print '<br><span class="opacitymedium small">'.$langs->trans("LemonFacturXPaymentMeansHint").'</span>';
print '</td>';
print '</tr>';

// Identifiant légal BT-30 / BT-47
print '<tr class="oddeven">';
print '<td>'.$langs->trans("LemonFacturXLegalIdScheme");
print '<br><span class="opacitymedium small">'.$langs->trans("LemonFacturXLegalIdSchemeHint").'</span>';
print '</td>';
print '<td>';
$legalScheme = getDolGlobalString('LEMONFACTURX_LEGAL_ID_SCHEME', 'siret0009');
print '<select name="LEMONFACTURX_LEGAL_ID_SCHEME" class="flat">';
print '<option value="siret0009"'.($legalScheme == 'siret0009' ? ' selected' : '').'>'.$langs->trans("LegalIdSchemeSiret0009").'</option>';
print '<option value="siren0002"'.($legalScheme == 'siren0002' ? ' selected' : '').'>'.$langs->trans("LegalIdSchemeSiren0002").'</option>';
print '<option value="siret0002"'.($legalScheme == 'siret0002' ? ' selected' : '').'>'.$langs->trans("LegalIdSchemeSiret0002").'</option>';
print '</select>';
print '</td>';
print '</tr>';

// BT-8 : exigibilité de la TVA (débits / encaissements)
print '<tr class="oddeven">';
print '<td>'.$langs->trans("LemonFacturXVatDueDateType");
print '<br><span class="opacitymedium small">'.$langs->trans("LemonFacturXVatDueDateTypeHint").'</span>';
print '</td>';
print '<td>';
$dueType = getDolGlobalString('LEMONFACTURX_VAT_DUE_DATE_TYPE', '');
print '<select name="LEMONFACTURX_VAT_DUE_DATE_TYPE" class="flat">';
print '<option value=""'.($dueType == '' ? ' selected' : '').'>'.$langs->trans("VatDueDateTypeNone").'</option>';
print '<option value="72"'.($dueType == '72' ? ' selected' : '').'>72 - '.$langs->trans("VatDueDateType72").'</option>';
print '<option value="5"'.($dueType == '5' ? ' selected' : '').'>5 - '.$langs->trans("VatDueDateType5").'</option>';
print '</select>';
print '</td>';
print '</tr>';

// BT-23 : cadre de facturation
print '<tr class="oddeven">';
print '<td>'.$langs->trans("LemonFacturXBt23Process");
print '<br><span class="opacitymedium small">'.$langs->trans("LemonFacturXBt23ProcessHint").'</span>';
print '</td>';
print '<td>';
print '<input type="text" name="LEMONFACTURX_BT23_PROCESS" class="flat minwidth100" value="'.dol_escape_htmltag(getDolGlobalString('LEMONFACTURX_BT23_PROCESS', '')).'" placeholder="A1, B1, S1...">';
print '</td>';
print '</tr>';

// Contrôle interne des règles métier EN16931
print '<tr class="oddeven">';
print '<td>'.$langs->trans("LemonFacturXBrCheck");
print '<br><span class="opacitymedium small">'.$langs->trans("LemonFacturXBrCheckHint").'</span>';
print '</td>';
print '<td>';
$brCheck = getDolGlobalInt('LEMONFACTURX_BR_CHECK', 1);
print '<select name="LEMONFACTURX_BR_CHECK" class="flat">';
print '<option value="1"'.($brCheck ? ' selected' : '').'>'.$langs->trans("Yes").'</option>';
print '<option value="0"'.(!$brCheck ? ' selected' : '').'>'.$langs->trans("No").'</option>';
print '</select>';
print '</td>';
print '</tr>';

// Chemin veraPDF (post-validation PDF/A-3 optionnelle)
print '<tr class="oddeven">';
print '<td>'.$langs->trans("LemonFacturXVeraPdfPath");
print '<br><span class="opacitymedium small">'.$langs->trans("LemonFacturXVeraPdfPathHint").'</span>';
print '</td>';
print '<td>';
print '<input type="text" name="LEMONFACTURX_VERAPDF_PATH" class="flat minwidth300" value="'.dol_escape_htmltag(getDolGlobalString('LEMONFACTURX_VERAPDF_PATH', '')).'" placeholder="/usr/local/bin/verapdf">';
print '</td>';
print '</tr>';

// Schéma d'adressage de l'endpoint (BT-34 / BT-49)
print '<tr class="oddeven">';
print '<td>'.$langs->trans("LemonFacturXEndpointScheme");
print '<br><span class="opacitymedium small">'.$langs->trans("LemonFacturXEndpointSchemeHint").'</span>';
print '</td>';
print '<td>';
$endpointScheme = getDolGlobalString('LEMONFACTURX_ENDPOINT_SCHEME', '0225');
print '<select name="LEMONFACTURX_ENDPOINT_SCHEME" class="flat">';
print '<option value="0225"'.($endpointScheme == '0225' ? ' selected' : '').'>0225 - '.$langs->trans("EndpointScheme0225").'</option>';
print '<option value="0002"'.($endpointScheme == '0002' ? ' selected' : '').'>0002 - '.$langs->trans("EndpointScheme0002").'</option>';
print '<option value="0009"'.($endpointScheme == '0009' ? ' selected' : '').'>0009 - '.$langs->trans("EndpointScheme0009").'</option>';
print '</select>';
print '</td>';
print '</tr>';

// Mode strict
print '<tr class="oddeven">';
print '<td>'.$langs->trans("LemonFacturXStrictMode");
print '<br><span class="opacitymedium small">'.$langs->trans("LemonFacturXStrictModeHint").'</span>';
print '</td>';
print '<td>';
$strict = getDolGlobalInt('LEMONFACTURX_STRICT_MODE', 0);
print '<select name="LEMONFACTURX_STRICT_MODE" class="flat">';
print '<option value="0"'.($strict == 0 ? ' selected' : '').'>'.$langs->trans("LemonFacturXStrictModeBestEffort").'</option>';
print '<option value="1"'.($strict == 1 ? ' selected' : '').'>'.$langs->trans("LemonFacturXStrictModeStrict").'</option>';
print '</select>';
print '</td>';
print '</tr>';

// Chemin PHP CLI
print '<tr class="oddeven">';
print '<td>'.$langs->trans("LemonFacturXPhpCliPath");
print '<br><span class="opacitymedium small">'.$langs->trans("LemonFacturXPhpCliPathHint").'</span>';
print '</td>';
print '<td>';
print '<input type="text" name="LEMONFACTURX_PHP_CLI_PATH" class="flat minwidth300" value="'.dol_escape_htmltag(getDolGlobalString('LEMONFACTURX_PHP_CLI_PATH', 'php')).'" placeholder="php ou /usr/bin/php8.2">';
print '</td>';
print '</tr>';

// Mentions légales BR-FR : PMD / PMT / AAB
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans("LemonFacturXLegalNotes").'</td></tr>';

foreach ([
	['LEMONFACTURX_NOTE_PMD', 'LemonFacturXNotePMD', LEMONFACTURX_DEFAULT_NOTE_PMD],
	['LEMONFACTURX_NOTE_PMT', 'LemonFacturXNotePMT', LEMONFACTURX_DEFAULT_NOTE_PMT],
	['LEMONFACTURX_NOTE_AAB', 'LemonFacturXNoteAAB', LEMONFACTURX_DEFAULT_NOTE_AAB],
] as $note) {
	$val = getDolGlobalString($note[0], $note[2]);
	print '<tr class="oddeven">';
	print '<td>'.$langs->trans($note[1]).'</td>';
	print '<td><textarea name="'.$note[0].'" class="flat minwidth500" rows="3">'.dol_escape_htmltag($val).'</textarea></td>';
	print '</tr>';
}

print '</table>';

print '<br>';
print '<div class="center">';
print '<input type="submit" class="button button-save" value="'.$langs->trans("Save").'">';
print '</div>';

print '</form>';

// Info
print '<br>';
print '<div class="info">';
print $langs->trans("LemonFacturXInfo");
print '</div>';

// === Diagnostic des infos obligatoires ===
print '<br>';
print load_fiche_titre($langs->trans("LemonFacturXDiagTitle"), '', '');

$diagErrors = [];
$diagOk = [];

/**
 * Ajoute une ligne au diag : OK si la valeur est non vide, sinon en erreur.
 * Le suffixe (références BR-FR-xx) est ajouté au libellé d'erreur uniquement.
 * $fixUrl pointe vers la page Dolibarr permettant de corriger ce point précis.
 */
$diagCheck = function ($transKey, $value, $okFormatted = null, $errorSuffix = '', $fixUrl = '/admin/company.php') use ($langs, &$diagOk, &$diagErrors) {
	$label = $langs->trans($transKey);
	if (empty($value)) {
		$diagErrors[] = ['msg' => $label.($errorSuffix !== '' ? ' '.$errorSuffix : ''), 'fix' => $fixUrl];
		return;
	}
	$diagOk[] = $label.' : '.($okFormatted !== null ? $okFormatted : dol_escape_htmltag($value));
};

// Modules Dolibarr requis
if (!isModEnabled('banque')) {
	$diagErrors[] = ['msg' => $langs->trans("LemonFacturXDiagModuleBankDisabled"), 'fix' => '/admin/modules.php'];
} else {
	$diagOk[] = $langs->trans("LemonFacturXDiagModuleBankEnabled");
}
if (!isModEnabled('facture')) {
	$diagErrors[] = ['msg' => $langs->trans("LemonFacturXDiagModuleInvoiceDisabled"), 'fix' => '/admin/modules.php'];
} else {
	$diagOk[] = $langs->trans("LemonFacturXDiagModuleInvoiceEnabled");
}

$diagCheck('LemonFacturXDiagSellerName', $mysoc->name);

$hasAddr = !empty($mysoc->address) && !empty($mysoc->zip) && !empty($mysoc->town);
$diagCheck('LemonFacturXDiagSellerAddress', $hasAddr ? '1' : '', dol_escape_htmltag($mysoc->zip).' '.dol_escape_htmltag($mysoc->town));

// TVA intra : en franchise en base (293 B CGI, auto-entrepreneurs), l'absence de
// numéro est normale — le SIREN est publié comme identifiant fiscal (schemeID="FC")
// par le générateur. On n'affiche donc pas d'erreur, cohérent avec check_mandatory().
$isFranchise = isset($mysoc->tva_assuj) && (int) $mysoc->tva_assuj === 0;
if ($isFranchise && empty($mysoc->tva_intra)) {
	$diagOk[] = $langs->trans("LemonFacturXDiagSellerVAT").' : '.$langs->trans("LemonFacturXDiagSellerVATFranchise");
} else {
	$diagCheck('LemonFacturXDiagSellerVAT', $mysoc->tva_intra);
}

if (empty($mysoc->idprof2)) {
	$diagErrors[] = ['msg' => $langs->trans("LemonFacturXDiagSellerSIRET").' (BR-FR-10)', 'fix' => '/admin/company.php'];
} else {
	$siren = lemonfacturx_extract_siren($mysoc->idprof2);
	$diagOk[] = $langs->trans("LemonFacturXDiagSellerSIRET").' : SIREN '.dol_escape_htmltag($siren).' (SIRET '.dol_escape_htmltag($mysoc->idprof2).')';
}

$diagCheck('LemonFacturXDiagSellerEmail', $mysoc->email, null, '(BR-FR-13 / BT-34)');

// Banque : lien "Corriger" vers la liste des comptes (compta/bank/list.php), pas la fiche société.
$bankFixUrl = '/compta/bank/list.php?mainmenu=bank';
$bankId = getDolGlobalInt('LEMONFACTURX_BANK_ACCOUNT');
if ($bankId <= 0) {
	$diagErrors[] = ['msg' => $langs->trans("LemonFacturXDiagBankNotSet"), 'fix' => $bankFixUrl];
} else {
	$bankCheck = new Account($db);
	if ($bankCheck->fetch($bankId) <= 0) {
		$diagErrors[] = ['msg' => $langs->trans("LemonFacturXDiagBankNotFound"), 'fix' => $bankFixUrl];
	} else {
		$diagCheck('LemonFacturXDiagIBAN', $bankCheck->iban, dol_escape_htmltag(lemonfacturx_iban_short($bankCheck->iban)), '', $bankFixUrl);
		$diagCheck('LemonFacturXDiagBIC', $bankCheck->bic, null, '', $bankFixUrl);
	}
}

// PDF/A-3 : police embarquée forcée (sinon veraPDF échoue sur les polices base-14)
$forceFont = getDolGlobalString('MAIN_PDF_FORCE_FONT', '');
if ($forceFont === '') {
	$diagErrors[] = ['msg' => $langs->trans("LemonFacturXDiagForceFontMissing"), 'fix' => '/admin/const.php'];
} else {
	$diagOk[] = $langs->trans("LemonFacturXDiagForceFontOk").' : '.dol_escape_htmltag($forceFont);
}

// exec() requis pour le subprocess d'injection
if (!function_exists('exec')) {
	$diagErrors[] = ['msg' => $langs->trans("LemonFacturXDiagExecDisabled"), 'fix' => '/admin/modules.php'];
} else {
	$diagOk[] = $langs->trans("LemonFacturXDiagExecEnabled");
}

// Binaire PHP CLI configuré : si chemin absolu, vérifier qu'il est exécutable
$phpCliPath = getDolGlobalString('LEMONFACTURX_PHP_CLI_PATH', 'php');
if (strpos($phpCliPath, '/') !== false || strpos($phpCliPath, '\\') !== false) {
	if (is_executable($phpCliPath)) {
		$diagOk[] = $langs->trans("LemonFacturXDiagPhpCliOk").' : '.dol_escape_htmltag($phpCliPath);
	} else {
		$diagErrors[] = ['msg' => $langs->trans("LemonFacturXDiagPhpCliNotFound", dol_escape_htmltag($phpCliPath)), 'fix' => '/custom/lemonfacturx/admin/setup.php'];
	}
}

// Multidevise : avertissement informatif (les factures en devise étrangère sont ignorées)
if (isModEnabled('multicurrency')) {
	$diagOk[] = $langs->trans("LemonFacturXDiagMulticurrencyNote");
}

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans("LemonFacturXDiagResults").'</td></tr>';

foreach ($diagOk as $ok) {
	print '<tr class="oddeven"><td><span style="color: green;">&#10004;</span> '.$ok.'</td><td></td></tr>';
}
foreach ($diagErrors as $err) {
	print '<tr class="oddeven"><td><span style="color: red;">&#10008;</span> <strong>'.$err['msg'].'</strong></td>';
	print '<td><a href="'.DOL_URL_ROOT.$err['fix'].'">'.$langs->trans("LemonFacturXDiagFixLink").'</a></td></tr>';
}

if (empty($diagErrors)) {
	print '<tr class="oddeven"><td colspan="2"><span style="color: green;"><strong>'.$langs->trans("LemonFacturXDiagAllOk").'</strong></span></td></tr>';
}

print '</table>';

// === Bloc "À propos de Lemon" — vitrine éditeur ===
print '<div style="margin:30px 0;padding:20px 25px;border:1px solid #e0e0e0;border-left:4px solid #FFD21F;border-radius:6px;background:linear-gradient(135deg,#fffef7 0%,#fafafa 100%);">';
print '<h3 style="margin:0 0 10px 0;color:#333;">'.$langs->trans("LemonFacturXAboutTitle").'</h3>';
print '<p style="margin:0 0 12px 0;color:#555;">'.$langs->trans("LemonFacturXAboutIntro").'</p>';
print '<ul style="margin:0 0 15px 20px;color:#555;">';
for ($i = 1; $i <= 5; $i++) {
	print '<li><strong>'.$langs->trans("LemonFacturXAboutSvc".$i."Title").'</strong> : '.$langs->trans("LemonFacturXAboutSvc".$i."Desc").'</li>';
}
print '</ul>';
print '<p style="margin:0;">';
print '<a href="https://hellolemon.fr" target="_blank" rel="noopener" class="butAction" style="text-decoration:none;">'.$langs->trans("LemonFacturXAboutCTA").'</a>';
print ' <span style="color:#999;margin-left:15px;">'.$langs->trans("LemonFacturXAboutLocation").'</span>';
print '</p>';
print '</div>';

llxFooter();
$db->close();
