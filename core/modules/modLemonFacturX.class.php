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
		$this->version = '3.6.2';
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

		// NB : la conversion est active dès que le module l'est (plus de constante
		// LEMONFACTURX_ENABLED). L'identifiant légal (auto par profil), l'exigibilité
		// TVA BT-8 (lue depuis TAX_MODE Dolibarr), le cadre BT-23 (par facture) et le
		// schéma d'endpoint (0225 figé) ne sont plus des réglages.
		$this->const = array(
			array('LEMONFACTURX_BANK_ACCOUNT', 'int', '0', 'ID du compte bancaire pour IBAN/BIC', 1, 'current', 0),
			array('LEMONFACTURX_PAYMENT_MEANS', 'chaine', '30', 'Code moyen de paiement UNTDID 4461 (30=virement, 58=virement SEPA, 59=prélèvement SEPA, 49=prélèvement)', 1, 'current', 0),
			array('LEMONFACTURX_STRICT_MODE', 'int', '0', 'Mode erreur : 0 = best-effort, 1 = strict bloquant', 1, 'current', 0),
			array('LEMONFACTURX_BR_CHECK', 'int', '1', 'Contrôle interne des règles métier EN16931 (BR-*) avant injection', 1, 'current', 0),
			array('LEMONFACTURX_PHP_CLI_PATH', 'chaine', '', 'Surcharge manuelle du binaire PHP CLI (vide = auto-détection)', 1, 'current', 0),
			array('LEMONFACTURX_VERAPDF_PATH', 'chaine', '', 'Chemin du binaire veraPDF pour post-validation PDF/A-3 (optionnel)', 1, 'current', 0),
			array('LEMONFACTURX_ENDPOINT_SUFFIX_SELLER', 'chaine', '', 'Suffixe ajouté au SIREN vendeur dans l\'adresse électronique BT-34 (ex _Status) — exigé par certaines PA ; vide = SIREN nu', 1, 'current', 0),
			array('LEMONFACTURX_NOTE_PMD', 'chaine', '', 'Mention légale pénalités de retard (BR-FR-05, default appliqué si vide)', 1, 'current', 0),
			array('LEMONFACTURX_NOTE_PMT', 'chaine', '', 'Mention légale indemnité de recouvrement (default appliqué si vide)', 1, 'current', 0),
			array('LEMONFACTURX_NOTE_AAB', 'chaine', '', 'Mention légale escompte anticipé (default appliqué si vide)', 1, 'current', 0),
			array('LEMONFACTURX_NOTES_IN_FOOTER', 'int', '0', 'Recopier les mentions BR-FR-05 dans le pied de facture (INVOICE_FREE_TEXT)', 1, 'current', 0),
			array('LEMONFACTURX_NOTES_OVERWRITE', 'int', '0', 'Recopie : écraser la mention Dolibarr existante (1) au lieu d\'ajouter nos mentions à la suite (0)', 1, 'current', 0),
			array('LEMONFACTURX_CHORUS_ENABLED', 'int', '0', 'Activer les fonctionnalités Chorus Pro (onglet, menu, 2e PDF) — opt-in', 1, 'current', 0),
		);

		if (!isset($conf->lemonfacturx) || !isset($conf->lemonfacturx->enabled)) {
			$conf->lemonfacturx = new stdClass();
			$conf->lemonfacturx->enabled = 0;
		}

		$this->rights = array();
		$this->menu = array();

		// Onglet dédié « Chorus Pro » sur la fiche facture (regroupe les champs
		// Chorus, masqués de l'affichage extrafields standard via list=0).
		// NB : le type d'onglet pour les factures est « invoice » (et non
		// « facture ») — c'est ce que passe facture_prepare_head() à
		// complete_head_from_modules(). Avec « facture » l'onglet n'apparaît jamais.
		// La condition (5e champ) est évaluée au RUNTIME : l'onglet n'apparaît que
		// si les fonctionnalités Chorus sont activées (opt-in, sans réactivation).
		// NB : utiliser getDolGlobalInt() et NON $conf->global->XXX — le dol_eval
		// durci de Dolibarr 23 n'évalue plus la syntaxe $conf->global->XXX (la
		// condition tombe à faux et l'onglet disparaît). getDolGlobalInt() passe.
		$this->tabs = array(
			'invoice:+lemonfacturxchorus:LemonFacturXChorusTab:lemonfacturx@lemonfacturx:getDolGlobalInt("LEMONFACTURX_CHORUS_ENABLED"):/lemonfacturx/chorus_tab.php?id=__ID__',
		);
	}

	/**
	 * Activation du module : crée les extrafields Chorus Pro sur les factures.
	 *
	 * @param string $options Options d'activation
	 * @return int 1 si OK, 0 si KO
	 */
	public function init($options = '')
	{
		$result = $this->_init(array(), $options);
		$this->createChorusExtraFields();
		$this->fixChorusExtraFieldsDisplay();
		return $result;
	}

	/**
	 * Désactivation : ne supprime PAS les extrafields (remove() est appelé à
	 * chaque désactivation, pas qu'à la désinstallation — on ne touche jamais
	 * aux données saisies par l'utilisateur).
	 *
	 * @param string $options Options de désactivation
	 * @return int 1 si OK, 0 si KO
	 */
	public function remove($options = '')
	{
		return $this->_remove(array(), $options);
	}

	/**
	 * Crée (si absents) les extrafields de la fiche facture pour le profil
	 * Chorus Pro : case à cocher de forçage + code service (BT-10), n° engagement
	 * (BT-13), n° marché (BT-12). Idempotent et non bloquant.
	 */
	private function createChorusExtraFields()
	{
		require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
		require_once dol_buildpath('/lemonfacturx/core/lib/lemonfacturx.lib.php');

		$extrafields = new ExtraFields($this->db);
		$extrafields->fetch_name_optionals_label('facture');

		$cadres = lemonfacturx_chorus_frameworks();

		// attrname, label (clé de trad), type, taille (varchar) OU param (select), position
		$defs = array(
			array('lfxchorus',     'LemonFacturXEfChorus',      'boolean', '',                          1010),
			array('lfxcadre',      'LemonFacturXEfCadre',       'select',  array('options' => $cadres), 1011),
			array('lfxservicecode','LemonFacturXEfServiceCode', 'varchar', '255',                       1012),
			array('lfxengagement', 'LemonFacturXEfEngagement',  'varchar', '255',                       1013),
			array('lfxmarche',     'LemonFacturXEfMarche',      'varchar', '255',                       1014),
		);
		foreach ($defs as $d) {
			if (!empty($extrafields->attributes['facture']['label'][$d[0]])) {
				continue; // déjà présent
			}
			$size  = is_array($d[3]) ? '' : $d[3];   // taille (chaîne) pour varchar
			$param = is_array($d[3]) ? $d[3] : '';   // tableau d'options pour select
			// Signature : (attrname, label, type, pos, size, elementtype, unique,
			// required, default, param, alwayseditable, perms, list, ...). list='0'
			// = masqué de l'affichage standard : ces champs ne s'éditent QUE dans
			// l'onglet « Chorus Pro » dédié.
			$extrafields->addExtraField(
				$d[0], $d[1], $d[2], $d[4], $size, 'facture',
				0, 0, '', $param, 1, '', '0', '', '', '',
				'lemonfacturx@lemonfacturx', '$conf->lemonfacturx->enabled'
			);
		}
	}

	/**
	 * Force printable=0 et list=0 sur les extrafields Chorus.
	 *
	 * Ces champs sont internes (édités uniquement dans l'onglet « Chorus Pro »)
	 * et ne doivent JAMAIS s'imprimer sur le PDF. Or Dolibarr 23 crée les
	 * extrafields avec printable!=0 et son modèle PDF (pdf_sponge →
	 * getExtrafieldsInHtml) imprime dans la NOTE du PDF tout extrafield dont
	 * printable vaut 1 ou 2 → un bloc de libellés Chorus vides apparaissait dans
	 * la description de la facture (signalé sur Dolibarr 23.0.1).
	 *
	 * On force donc printable=0 (et list=0) de façon idempotente : exécuté à
	 * chaque activation, ça corrige aussi les installations déjà créées de
	 * travers. Inoffensif sur Dolibarr ≤ 22 (déjà à 0).
	 */
	private function fixChorusExtraFieldsDisplay()
	{
		require_once dol_buildpath('/lemonfacturx/core/lib/lemonfacturx.lib.php');
		lemonfacturx_fix_chorus_extrafields_display($this->db);
	}
}
