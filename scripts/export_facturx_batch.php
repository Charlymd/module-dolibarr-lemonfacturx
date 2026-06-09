<?php
/*
 * Copyright (C) 2026 SASU LEMON <https://hellolemon.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Export par lot des XML Factur-X embarqués dans les PDF des factures
 * clients validées (audit, archivage, dépôt manuel sur une plateforme).
 *
 * Usage : php scripts/export_facturx_batch.php <dossier_destination> [annee]
 *
 * Pour chaque facture validée (de l'année si précisée), extrait le XML
 * Factur-X du PDF principal vers <destination>/<ref>.xml et affiche un
 * rapport (OK / NO_PDF / NO_XML).
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(403);
	die('CLI only');
}

if ($argc < 2) {
	fwrite(STDERR, "Usage: php export_facturx_batch.php <dest_dir> [year]\n");
	exit(2);
}
$destDir = rtrim($argv[1], '/');
$year = isset($argv[2]) ? (int) $argv[2] : 0;

if (!is_dir($destDir) && !mkdir($destDir, 0750, true)) {
	fwrite(STDERR, "ERROR: destination non accessible : $destDir\n");
	exit(2);
}

// Localisation de master.inc.php : 2 niveaux au-dessus du module, ou chemins standards
$candidates = [
	__DIR__.'/../../../master.inc.php',
	'/var/www/dolibarr/htdocs/master.inc.php',
	'/var/www/html/master.inc.php',
];
$loaded = false;
foreach ($candidates as $c) {
	if (file_exists($c)) {
		require_once $c;
		$loaded = true;
		break;
	}
}
if (!$loaded) {
	fwrite(STDERR, "ERROR: master.inc.php introuvable. Lancer depuis un Dolibarr installé.\n");
	exit(2);
}

require_once __DIR__.'/../core/lib/lemonfacturx.lib.php';

$sql = "SELECT rowid, ref, datef, entity, last_main_doc FROM ".MAIN_DB_PREFIX."facture"
	." WHERE fk_statut >= 1 AND entity IN (".getEntity('invoice').")";
if ($year > 0) {
	// Borne par dates plutôt que YEAR() : portable MySQL/MariaDB/PostgreSQL
	$sql .= " AND datef >= '".((int) $year)."-01-01' AND datef <= '".((int) $year)."-12-31'";
}
$sql .= " ORDER BY datef ASC, rowid ASC";

$res = $db->query($sql);
if (!$res) {
	fwrite(STDERR, "ERROR: requête factures échouée : ".$db->lasterror()."\n");
	exit(2);
}

$counts = ['OK' => 0, 'NO_PDF' => 0, 'NO_XML' => 0];

echo "=== LemonFacturX - Export batch des XML Factur-X ===\n";
echo "Destination : $destDir".($year > 0 ? " | Année : $year" : '')."\n\n";

while ($obj = $db->fetch_object($res)) {
	$ref = $obj->ref;
	// Entité de la facture (et non l'entité courante) : en multicompany avec
	// partage, le PDF vit dans le répertoire documents de SON entité.
	$pdfPath = lemonfacturx_invoice_pdf_path($ref, (int) $obj->entity, (string) ($obj->last_main_doc ?? ''));

	if ($pdfPath === null) {
		$counts['NO_PDF']++;
		printf("%-20s NO_PDF\n", $ref);
		continue;
	}

	$xml = lemonfacturx_extract_xml_from_pdf($pdfPath);
	if ($xml === null) {
		$counts['NO_XML']++;
		printf("%-20s NO_XML\n", $ref);
		continue;
	}

	$safeRef = dol_sanitizeFileName($ref);
	if (file_put_contents($destDir.'/'.$safeRef.'.xml', $xml) === false) {
		fwrite(STDERR, "ERROR: écriture impossible pour $ref\n");
		exit(2);
	}
	$counts['OK']++;
	printf("%-20s OK\n", $ref);
}

echo "\n=== Résultat ===\n";
printf("Exportés : %d | Sans PDF : %d | Sans XML Factur-X : %d\n", $counts['OK'], $counts['NO_PDF'], $counts['NO_XML']);
echo "Astuce : les factures NO_XML peuvent être régénérées via le bouton « Régénérer Factur-X » de la fiche facture.\n";
exit(0);
