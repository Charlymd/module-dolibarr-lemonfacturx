<?php
/*
 * Copyright (C) 2026 SASU LEMON <https://hellolemon.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * API REST LemonFacturX — exposée via l'API Dolibarr standard
 * (module API REST activé + clé API utilisateur).
 *
 * Endpoints :
 *  GET /lemonfacturx/invoice/{id}/xml     XML Factur-X regénéré depuis la facture
 *  GET /lemonfacturx/invoice/{id}/status  Présence/validité du XML embarqué dans le PDF
 */

use Luracast\Restler\RestException;

require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';

class LemonfacturxApi extends DolibarrApi
{
	public function __construct()
	{
		global $db;
		$this->db = $db;
	}

	/**
	 * Génère et renvoie le XML Factur-X EN16931 d'une facture client.
	 *
	 * Le XML est reconstruit à la volée depuis les données de la facture
	 * (même moteur que l'injection PDF), avec les avertissements de
	 * conformité et les éventuelles violations de règles métier.
	 *
	 * @param int $id ID de la facture
	 * @return array
	 *
	 * @url GET invoice/{id}/xml
	 */
	public function getXml($id)
	{
		global $mysoc;

		$invoice = $this->loadInvoice($id);

		$modulePath = dirname(__DIR__);
		require_once $modulePath.'/core/lib/lemonfacturx.lib.php';
		require_once $modulePath.'/core/lib/lemonfacturx_rules.php';

		$unsupported = lemonfacturx_check_supported($invoice);
		if ($unsupported !== null) {
			throw new RestException(422, 'Unsupported invoice: '.$unsupported);
		}

		$warnings = lemonfacturx_check_mandatory($invoice, $mysoc);
		$buildWarnings = [];
		$xml = lemonfacturx_build_xml($invoice, $mysoc, $buildWarnings);
		$violations = lemonfacturx_validate_business_rules($xml);

		return [
			'ref'        => $invoice->ref,
			'xml'        => $xml,
			'warnings'   => array_merge($warnings, $buildWarnings),
			'violations' => $violations,
		];
	}

	/**
	 * Statut Factur-X du PDF de la facture : XML embarqué présent et conforme ?
	 *
	 * @param int $id ID de la facture
	 * @return array
	 *
	 * @url GET invoice/{id}/status
	 */
	public function getStatus($id)
	{
		global $conf;

		$invoice = $this->loadInvoice($id);

		$modulePath = dirname(__DIR__);
		require_once $modulePath.'/core/lib/lemonfacturx.lib.php';
		require_once $modulePath.'/core/lib/lemonfacturx_rules.php';

		$pdfPath = lemonfacturx_invoice_pdf_path($invoice->ref, $invoice->entity ?? $conf->entity, (string) ($invoice->last_main_doc ?? ''));

		$result = [
			'ref'            => $invoice->ref,
			'pdf_exists'     => false,
			'facturx_found'  => false,
			'br_violations'  => [],
		];
		if ($pdfPath === null) {
			return $result;
		}
		$result['pdf_exists'] = true;

		$xml = lemonfacturx_extract_xml_from_pdf($pdfPath);
		if ($xml === null) {
			return $result;
		}
		$result['facturx_found'] = true;
		$result['br_violations'] = lemonfacturx_validate_business_rules($xml);

		return $result;
	}

	/**
	 * Charge la facture et vérifie les droits de lecture de l'utilisateur API.
	 *
	 * @param int $id
	 * @return Facture
	 * @throws RestException
	 */
	protected function loadInvoice($id)
	{
		if (!DolibarrApiAccess::$user->hasRight('facture', 'lire')) {
			throw new RestException(403, 'Insufficient rights to read invoices');
		}

		$invoice = new Facture($this->db);
		if ($invoice->fetch((int) $id) <= 0) {
			throw new RestException(404, 'Invoice not found');
		}
		if (!DolibarrApi::_checkAccessToResource('facture', $invoice->id)) {
			throw new RestException(403, 'Access to this invoice is not allowed for this API user');
		}
		$invoice->fetch_thirdparty();
		if (empty($invoice->lines)) {
			$invoice->fetch_lines();
		}
		return $invoice;
	}
}
