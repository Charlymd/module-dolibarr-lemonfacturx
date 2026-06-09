<?php
/*
 * Copyright (C) 2026 SASU LEMON <https://hellolemon.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Module descriptor for LemonFacturX
 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

class modLemonFacturX extends DolibarrModules
{
	public function __construct($db)
	{
		global $conf;

		$this->db = $db;
		$this->numero = 210000;
		$this->rights_class = 'lemonfacturx';
		$this->family = "financial";
		$this->module_position = 90;
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = "Génération automatique de factures Factur-X EN16931";
		$this->descriptionlong = "Injecte un XML CrossIndustryInvoice EN16931 dans chaque PDF facture client généré, pour conformité Factur-X.";
		$this->version = '3.0.0';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'bill';
		$this->editor_name = 'Lemon';
		$this->editor_url = 'https://hellolemon.fr';

		// Prérequis vérifiés à l'activation. Dolibarr 19 minimum : le module
		// utilise GETPOSTINT() (introduit en 19.0) ; testé sur Dolibarr 22 / PHP 8.2.
		$this->phpmin = array(8, 1);
		$this->need_dolibarr_version = array(19, 0);

		$this->module_parts = array(
			'triggers' => 0,
			'login' => 0,
			'substitutions' => 0,
			'menus' => 0,
			'theme' => 0,
			'tpl' => 0,
			'barcode' => 0,
			'models' => 0,
			'hooks' => array(
				'pdfgeneration',
				'invoicecard',
			),
		);

		$this->dirs = array();
		$this->config_page_url = array('setup.php@lemonfacturx');

		$this->depends = array();
		$this->requiredby = array();
		$this->conflictwith = array();
		$this->langfiles = array("lemonfacturx@lemonfacturx");

		$this->const = array(
			array('LEMONFACTURX_ENABLED', 'int', '1', 'Activer la conversion Factur-X', 1, 'current', 0),
			array('LEMONFACTURX_BANK_ACCOUNT', 'int', '0', 'ID du compte bancaire pour IBAN/BIC', 1, 'current', 0),
			array('LEMONFACTURX_PAYMENT_MEANS', 'chaine', '30', 'Code moyen de paiement UNTDID 4461 (30=virement, 58=virement SEPA, 59=prélèvement SEPA, 49=prélèvement)', 1, 'current', 0),
			array('LEMONFACTURX_STRICT_MODE', 'int', '0', 'Mode erreur : 0 = best-effort, 1 = strict bloquant', 1, 'current', 0),
			array('LEMONFACTURX_BR_CHECK', 'int', '1', 'Contrôle interne des règles métier EN16931 (BR-*) avant injection', 1, 'current', 0),
			array('LEMONFACTURX_LEGAL_ID_SCHEME', 'chaine', 'siret0009', 'Identifiant légal BT-30/BT-47 : siret0009 (défaut), siren0002, siret0002 (héritage 2.1.x)', 1, 'current', 0),
			array('LEMONFACTURX_VAT_DUE_DATE_TYPE', 'chaine', '', 'BT-8 exigibilité TVA : vide (omis), 5 = débits, 72 = encaissements', 1, 'current', 0),
			array('LEMONFACTURX_BT23_PROCESS', 'chaine', '', 'BT-23 cadre de facturation (A1 Chorus Pro B2G, B1/S1/S2 réforme FR...), omis si vide', 1, 'current', 0),
			array('LEMONFACTURX_PHP_CLI_PATH', 'chaine', 'php', 'Chemin du binaire PHP CLI pour subprocess injection', 1, 'current', 0),
			array('LEMONFACTURX_VERAPDF_PATH', 'chaine', '', 'Chemin du binaire veraPDF pour post-validation PDF/A-3 (optionnel)', 1, 'current', 0),
			array('LEMONFACTURX_NOTE_PMD', 'chaine', '', 'Mention légale pénalités de retard (BR-FR-05, default appliqué si vide)', 1, 'current', 0),
			array('LEMONFACTURX_NOTE_PMT', 'chaine', '', 'Mention légale indemnité de recouvrement (default appliqué si vide)', 1, 'current', 0),
			array('LEMONFACTURX_NOTE_AAB', 'chaine', '', 'Mention légale escompte anticipé (default appliqué si vide)', 1, 'current', 0),
		);

		if (!isset($conf->lemonfacturx) || !isset($conf->lemonfacturx->enabled)) {
			$conf->lemonfacturx = new stdClass();
			$conf->lemonfacturx->enabled = 0;
		}

		$this->rights = array();
		$this->menu = array();
	}
}
