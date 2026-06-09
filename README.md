# LemonFacturX

**Version 3.0.0** — Module Dolibarr pour la génération automatique de factures **Factur-X EN16931** (PDF/A-3 avec XML CrossIndustryInvoice embarqué).

Chaque facture client générée dans Dolibarr est automatiquement convertie au format Factur-X, conforme aux règles **BR-FR** (norme XP Z12-012 V1.2.0) pour la facturation électronique française.

Développé et maintenu par [Lemon](https://hellolemon.fr), agence web et communication à Clermont-Ferrand, spécialisée dans Dolibarr, WordPress et la facturation électronique.

## Prérequis

- **Dolibarr** 16.0+ (testé sur 22.0.x) — vérifié à l'activation (`need_dolibarr_version`)
- **PHP** 8.1+ (testé sur 8.2/8.4) — vérifié à l'activation (`phpmin`)
- **Fonction `exec()`** activée (subprocess d'injection PDF) — vérifiée par le diagnostic
- **Constante Dolibarr** `MAIN_PDF_FORCE_FONT` = `pdfahelvetica` (polices embarquées, requis PDF/A-3) — vérifiée par le diagnostic et signalée en warning à chaque génération si absente

## Installation

1. **Télécharger l'archive de la dernière release** sur
   [github.com/hello-lemon/module-dolibarr-lemonfacturx/releases/latest](https://github.com/hello-lemon/module-dolibarr-lemonfacturx/releases/latest).

   Récupérer l'asset `lemonfacturx-vX.Y.Z.zip` attaché à la release (et **non** le
   bouton "Download ZIP" du code source — voir l'avertissement plus bas).

2. Décompresser et copier le dossier `lemonfacturx/` dans le répertoire custom de Dolibarr :

   ```bash
   unzip lemonfacturx-vX.Y.Z.zip
   cp -r lemonfacturx/ /var/www/html/custom/
   chown -R www-data:www-data /var/www/html/custom/lemonfacturx
   ```

3. Activer le module : **Accueil > Configuration > Modules**
4. Configurer via **Accueil > Configuration > Modules > LemonFacturX** :
   - Compte bancaire (IBAN/BIC)
   - Moyen de paiement par défaut (virement, virement SEPA, prélèvement SEPA, prélèvement)
   - Identifiant légal BT-30/BT-47 (SIRET 0009 par défaut)
   - Exigibilité TVA (BT-8 : débits / encaissements), cadre de facturation (BT-23)
   - Mode de gestion d'erreur (best-effort / strict), contrôle des règles métier
   - Éventuellement chemin PHP CLI, chemin veraPDF et mentions légales
5. Poser `MAIN_PDF_FORCE_FONT = pdfahelvetica` via **Accueil > Configuration > Divers**
6. Vérifier le **diagnostic** en bas de la page de configuration du module (coches vertes = OK)

> **Attention** — N'utilisez pas le bouton "Download ZIP" de la page d'accueil du dépôt
> (le code source brut). Cette archive se décompresse en `module-dolibarr-lemonfacturx-main/`
> au lieu de `lemonfacturx/`, ce qui casse l'installation Dolibarr (erreur *"You requested
> a website or a page that does not exists"* en ouvrant la page de configuration du module).
> Téléchargez l'asset ZIP de la release, ou clonez directement avec `git clone`
> (cf. section [Mise à jour](#mise-à-jour)).

## Mise à jour

```bash
# Sauvegarder l'ancienne version (au cas où)
cp -r /var/www/html/custom/lemonfacturx /var/www/html/custom/lemonfacturx.bak

# Récupérer la nouvelle version
git clone https://github.com/hello-lemon/module-dolibarr-lemonfacturx.git /tmp/lemonfacturx-new
rm -rf /var/www/html/custom/lemonfacturx
mv /tmp/lemonfacturx-new /var/www/html/custom/lemonfacturx
chown -R www-data:www-data /var/www/html/custom/lemonfacturx
```

Dolibarr ne notifie pas automatiquement des mises à jour d'un module custom ; la page de configuration du module affiche en revanche un bandeau quand une release plus récente est publiée sur GitHub (check 24h, cache en DB). Consulter la section **Changelog** en bas de ce README pour connaître les changements et migrations éventuelles.

## Architecture

```
lemonfacturx/
├── core/modules/modLemonFacturX.class.php   # Descripteur module (n° 210000)
├── core/lib/
│   ├── lemonfacturx.lib.php                 # Générateur XML EN16931
│   └── lemonfacturx_rules.php               # Validateur règles métier (BR-*)
├── class/
│   ├── actions_lemonfacturx.class.php       # Hooks afterPDFCreation + invoicecard
│   └── api_lemonfacturx.class.php           # API REST (xml / status)
├── scripts/
│   ├── inject_facturx.php                   # Injection PDF (subprocess, CLI only)
│   └── export_facturx_batch.php             # Export par lot des XML embarqués
├── admin/setup.php                          # Page de configuration + diagnostic
├── langs/fr_FR + en_US/lemonfacturx.lang    # Traductions
├── tests/
│   ├── unit-tests.php                       # Tests standalone (sans Dolibarr, CI)
│   └── run-tests.php                        # Tests d'intégration (fixtures demo/)
├── docs/LIMITATIONS.md                      # Cas non traités et pourquoi
└── vendor/                                  # Lib atgp/factur-x v3.3.0 + dépendances
```

## Fonctionnement

Le module se branche sur le hook `afterPDFCreation` (contexte `pdfgeneration`). À chaque génération de PDF facture client :

1. **Contrôle du périmètre** : multidevise et données impossibles → refus propre (PDF classique conservé)
2. **Vérification** des infos obligatoires (vendeur, acheteur, IBAN, police PDF/A) — warnings consolidés
3. **Génération du XML** CrossIndustryInvoice EN16931 avec les données de la facture Dolibarr
4. **Validation interne** : well-formed + XSD EN16931 + **règles métier BR-\*** (sous-ensemble Schematron en PHP)
5. **Injection** du XML dans le PDF via la lib `atgp/factur-x` (subprocess séparé, écriture atomique, `AFRelationship=Alternative`)
6. **Post-validation veraPDF** optionnelle (PDF/A-3b)

Sur la **fiche facture** (facture validée), deux boutons :
- **Vérifier Factur-X** : extrait le XML embarqué du PDF et le revalide (XSD + règles métier) — à utiliser avant envoi
- **Régénérer Factur-X** : régénère le PDF (et donc l'injection) — utile après une mise à jour du module ou une correction de données

### Sécurité

- Scripts CLI (`scripts/`, `tests/`, `demo/`) protégés par `PHP_SAPI === 'cli'` **et** `.htaccess` `Require all denied`
- `exec()` vérifié avant appel, binaire PHP CLI configurable via `LEMONFACTURX_PHP_CLI_PATH`, chemin validé par regex et `is_executable()` si absolu
- Écriture **atomique** du PDF par le subprocess (fichier temporaire + `rename()`)
- Validation XML interne avant injection PDF (well-formed + XSD EN16931 + règles métier)
- Mode `LEMONFACTURX_STRICT_MODE` : choisir fail-open (best-effort) vs fail-closed (strict)
- CSRF sur le POST admin et sur les actions de la fiche facture (`currentToken()`)
- API REST : droits `facture->lire` + `_checkAccessToResource()` ; boutons fiche : droits `lire`/`creer`
- Un seul appel HTTP sortant : check de version GitHub toutes les 24h (cache en DB, échecs inclus)

Modèle de menace, protections détaillées et processus de signalement : voir [SECURITY.md](SECURITY.md). Contact disclosure : **hello@hellolemon.fr**.

## Données mappées (Dolibarr → Factur-X)

| Champ Factur-X | Source Dolibarr |
|---|---|
| BT-1 Invoice ID | `$invoice->ref` |
| BT-2 Issue date | `$invoice->date` |
| BT-3 Type code | 380 / 381 (avoir) / 384 (rectificative) / 386 (acompte) |
| BT-8 VAT due date code | `LEMONFACTURX_VAT_DUE_DATE_TYPE` (5 débits / 72 encaissements, omis si vide) |
| BT-9 Due date | `$invoice->date_lim_reglement` |
| BT-10 Buyer reference | `$invoice->ref_client` (code service / n° engagement Chorus Pro) |
| BT-13 Order reference | Réf. de la première commande client liée |
| BT-23 Business process | `LEMONFACTURX_BT23_PROCESS` (A1, B1, S1..., omis si vide) |
| BT-25/BG-3 Preceding invoice | `fk_facture_source` (avoir/rectificative) + acomptes imputés |
| Seller / Buyer | `$mysoc` / `$invoice->thirdparty` |
| BT-30/BT-47 Legal ID | `idprof2`, schéma configurable (SIRET 0009 par défaut) |
| BT-31/BT-32 Tax registration | `tva_intra`, ou SIREN `schemeID="FC"` (franchise en base) |
| BT-34/BT-49 Endpoint | SIREN `schemeID="0225"` (annuaire PPF), repli email `EM` |
| BT-72 Delivery date | `$invoice->delivery_date` si renseignée (forcée pour l'intracom K) |
| BT-73/74 (BG-14) Period | min/max des dates de service des lignes |
| BT-80 ShipTo country | Pays acheteur (émis pour la catégorie K, BR-IC-12) |
| BT-89/90/91 Direct debit | RUM (RIB par défaut du tiers), ICS (`PRELEVEMENT_ICS`), IBAN débiteur — moyen 59 |
| BG-21 Document allowances | Lignes Dolibarr à montant négatif (remises fixes) |
| BT-113 TotalPrepaidAmount | `$invoice->getSumDepositsUsed()` si acompte imputé |
| BT-121 VATEX | VATEX-FR-FRANCHISE / VATEX-EU-IC / VATEX-EU-AE / VATEX-EU-G |
| BT-129 unitCode | Mappé depuis `$line->fk_unit` vers UN/ECE Rec 20 |
| BT-146 Unit price | `total_ht/qty`, jusqu'à 4 décimales |
| BT-151 CategoryCode | Calculé selon contexte (S / K / AE / G / E) |
| IBAN / BIC | Compte bancaire Dolibarr sélectionné |

### Types de facture supportés

| Cas Dolibarr | TypeCode EN16931 | Mapping |
|---|---|---|
| Facture standard | **380** | Commercial invoice |
| `TYPE_REPLACEMENT` | **384** | Corrected invoice + référence BG-3 à la facture remplacée |
| `TYPE_CREDIT_NOTE` | **381** | Credit note — **montants émis en positif** (BR-27), BG-3 vers la facture d'origine |
| `TYPE_DEPOSIT` | **386** | Prepayment / advance invoice (acompte) |
| `TYPE_SITUATION` | 380 + warning | Support partiel, voir [docs/LIMITATIONS.md](docs/LIMITATIONS.md) |

**Convention avoirs** : Dolibarr stocke des totaux négatifs ; EN16931 exige des montants positifs sur un 381. Depuis la 3.0.0, tous les montants d'un avoir sont inversés (`DuePayableAmount` = total positif, sans écrêtage à zéro — BR-CO-16) et la facture d'origine est référencée en BG-3 (mention obligatoire FR). Un avoir créé sans facture d'origine liée génère un warning.

Une facture finale qui impute un acompte écrit `TotalPrepaidAmount` (BT-113), ajuste `DuePayableAmount` et référence la facture d'acompte en BG-3.

### Catégories TVA (BT-151)

| CategoryCode | VATEX (BT-121) | Cas déclenchant |
|---|---|---|
| **S** | — | TVA > 0 |
| **K** | VATEX-EU-IC | Acheteur UE hors FR avec TVA intra + TVA 0 + ligne **bien** (`product_type` 0) — avec ShipTo (BT-80) et date de livraison (BR-IC-11/12) |
| **AE** | VATEX-EU-AE | Acheteur UE hors FR avec TVA intra + TVA 0 + ligne **service** (`product_type` 1) — art. 196 directive 2006/112/CE |
| **G** | VATEX-EU-G | Acheteur hors UE + TVA 0 |
| **E** | VATEX-FR-FRANCHISE | Émetteur en franchise en base (293 B CGI) — SIREN publié en identifiant fiscal `FC` |
| **E** | — | TVA 0 par défaut (exonération sans base légale déterminable, motif générique) |

Les catégories exonérées génèrent systématiquement un `ExemptionReason` lisible et, quand la base légale est déterminable, un code `ExemptionReasonCode` VATEX.

**Cas non couverts** (autoliquidation domestique AE FR→FR, codes O/Z/L/M, etc.) : voir [docs/LIMITATIONS.md](docs/LIMITATIONS.md), qui documente chaque cas non traité et le pourquoi.

### Remises et arrondis

- **Remises fixes** (lignes Dolibarr à montant négatif) : converties en remises document **BG-21** (`SpecifiedTradeAllowanceCharge` + BT-107) — une ligne à prix négatif violerait BR-27. Les remises en % restent diluées dans les prix nets (conforme).
- **Arrondis** : la ventilation TVA est calculée par (catégorie, taux) puis **réconciliée** avec les totaux de la facture (l'écart d'arrondi éventuel est imputé sur la catégorie principale) ; tous les totaux BG-22 sont recalculés de bas en haut pour garantir les règles BR-CO-10/11/13/14/15/16/17, y compris sur les factures à nombreuses lignes.

### Mapping unités UN/ECE

Les quantités de ligne utilisent le code UN/ECE Rec 20 correspondant à l'unité Dolibarr (`llx_c_units.short_label`) :

| Dolibarr | UN/ECE | | Dolibarr | UN/ECE |
|---|---|---|---|---|
| h | HUR | | kg | KGM |
| d | DAY | | l | LTR |
| min | MIN | | m | MTR |
| week | WEE | | m² (`m2`) | MTK |
| month | MON | | m³ (`m3`) | MTQ |
| p, pc, pcs, u | C62 | | km | KMT |

Si l'unité n'est pas mappée ou si `fk_unit` n'est pas renseigné, le code `C62` (pièce) est utilisé en fallback. Les quantités sont émises avec jusqu'à 4 décimales.

### Mentions légales FR (BR-FR-05)

Le XML inclut automatiquement les notes obligatoires :
- **PMD** : pénalités de retard (3x taux d'intérêt légal, art. L.441-10)
- **PMT** : indemnité forfaitaire de recouvrement (40 €)
- **AAB** : escompte pour paiement anticipé

## Constantes du module

Toutes sont configurables via l'écran d'administration du module (**Accueil > Configuration > Modules > LemonFacturX**).

| Constante | Type | Défaut | Description |
|---|---|---|---|
| `LEMONFACTURX_ENABLED` | int | 1 | Activer/désactiver la conversion |
| `LEMONFACTURX_BANK_ACCOUNT` | int | 0 | ID du compte bancaire Dolibarr |
| `LEMONFACTURX_PAYMENT_MEANS` | string | 30 | Code UNTDID 4461 : 30 virement, 58 virement SEPA, 59 prélèvement SEPA, 49 prélèvement |
| `LEMONFACTURX_ENDPOINT_SCHEME` | string | 0225 | Schéma de l'endpoint BT-34/BT-49 (0225 SIREN annuaire, 0002, 0009) |
| `LEMONFACTURX_LEGAL_ID_SCHEME` | string | siret0009 | Identifiant légal BT-30/BT-47 : `siret0009` (ISO 6523, Chorus OK), `siren0002`, `siret0002` (héritage 2.1.x) |
| `LEMONFACTURX_VAT_DUE_DATE_TYPE` | string | *(vide)* | BT-8 : `5` débits, `72` encaissements, vide = omis |
| `LEMONFACTURX_BT23_PROCESS` | string | *(vide)* | BT-23 cadre de facturation (A1 Chorus B2G, B1/S1/S2 réforme), vide = omis |
| `LEMONFACTURX_STRICT_MODE` | int | 0 | 0 = best-effort (défaut), 1 = strict (voir ci-dessous) |
| `LEMONFACTURX_BR_CHECK` | int | 1 | Contrôle interne des règles métier EN16931 avant injection |
| `LEMONFACTURX_PHP_CLI_PATH` | string | php | Chemin vers le binaire PHP CLI (voir note ci-dessous) |
| `LEMONFACTURX_VERAPDF_PATH` | string | *(vide)* | Chemin veraPDF : post-validation PDF/A-3b de chaque PDF généré (non bloquant) |
| `LEMONFACTURX_NOTE_PMD/PMT/AAB` | text | mentions FR | Mentions légales BR-FR-05 |

> **Note PHP CLI** : Le subprocess d'injection utilise `php` par défaut. Sur les serveurs avec plusieurs versions de PHP, ou si `php` n'est pas dans le PATH, configurer `LEMONFACTURX_PHP_CLI_PATH` avec le chemin complet (ex: `/usr/bin/php8.2`). Ne **pas** utiliser `PHP_BINARY` : en contexte php-fpm, cette constante pointe vers le binaire fpm et non le CLI.

> **Note prélèvement SEPA (59)** : le module publie l'ICS créancier (constante Dolibarr `PRELEVEMENT_ICS`, BT-90), la RUM du mandat (RIB par défaut du tiers, BT-89) et l'IBAN débiteur (BT-91). Des warnings signalent les données manquantes.

### Mode strict vs best-effort

Par défaut le module est en **best-effort** : si le XML Factur-X est invalide ou si l'injection PDF échoue, un warning est affiché à l'utilisateur et le PDF classique (sans Factur-X embarqué) est conservé. Les erreurs sont loguées dans `syslog` avec le tag `LemonFacturX`.

En **mode strict** (`LEMONFACTURX_STRICT_MODE=1`), la même situation retourne une erreur bloquante visible, et les violations de règles métier (BR-\*) deviennent bloquantes. **Limite assumée** : le hook intervenant après la création du PDF par Dolibarr, le PDF classique déjà généré reste sur le disque même en strict — utiliser « Vérifier Factur-X » avant envoi pour contrôler un fichier.

### Validation interne

Avant injection PDF, le module valide systématiquement le XML :

1. **Well-formed** : `DOMDocument::loadXML()`
2. **XSD EN16931** : `DOMDocument::schemaValidate()` contre le XSD embarqué
3. **Règles métier** (`LEMONFACTURX_BR_CHECK`, défaut activé) : sous-ensemble des règles Schematron EN16931 vérifié en PHP — règles de calcul BR-CO-10..17, BR-27 (prix négatifs), BR-61 (IBAN), BR-16, BR-IC-02/11/12 (intracom), BR-AE-02, motifs d'exonération BR-\*-10, BR-CO-25/26, BR-09/11

Le Schematron officiel complet (XSLT 2.0) n'est pas exécutable en PHP : pour une validation exhaustive, utiliser un validateur externe — voir [docs/LIMITATIONS.md](docs/LIMITATIONS.md).

## API REST

Avec le module API REST Dolibarr activé (clé API utilisateur, droits factures) :

| Endpoint | Description |
|---|---|
| `GET /api/index.php/lemonfacturx/invoice/{id}/xml` | XML Factur-X regénéré + warnings + violations BR |
| `GET /api/index.php/lemonfacturx/invoice/{id}/status` | PDF présent ? XML embarqué ? violations BR du XML embarqué |

## Export par lot

```bash
php scripts/export_facturx_batch.php /chemin/export [2026]
```

Extrait le XML Factur-X embarqué de toutes les factures validées (de l'année si précisée) vers `<ref>.xml`, avec rapport `OK / NO_PDF / NO_XML` — audit, archivage, ou dépôt manuel sur une plateforme.

## Dépendances embarquées

Le dossier `vendor/` contient les libs nécessaires (pas de Composer requis sur le serveur) :

- `atgp/factur-x` v3.3.0 — génération PDF Factur-X
- `setasign/fpdi` v2.6.6 — lecture/écriture PDF
- `setasign/fpdf` 1.8.6 — moteur PDF (utilisé par atgp, **pas** par Dolibarr)
- `smalot/pdfparser` v2.12.5 — parsing PDF
- `symfony/polyfill-mbstring` — compatibilité mbstring

## Conformité PDF/A-3

La conformité PDF/A-3 est assurée par :
- **Polices embarquées** : constante Dolibarr `MAIN_PDF_FORCE_FONT=pdfahelvetica` — désormais **vérifiée** par le diagnostic et par un warning à la génération
- **AFRelationship `Alternative`** : conforme à la spec Factur-X pour le profil EN16931 (corrigé en 3.0.0, `Data` auparavant)
- **Annotations /F flag** : patch appliqué dans `vendor/setasign/fpdf/fpdf.php` (ajout `/F 4` aux liens)
- **Profil ICC sRGB** + **métadonnées XMP** : gérés par la lib `atgp/factur-x`
- **Post-validation veraPDF** optionnelle (`LEMONFACTURX_VERAPDF_PATH`) pour détecter les modèles PDF custom non conformes

> **Note** : si un module tiers (ex: milestone/jalons) hardcode la police `'Helvetica'`, il faudra le patcher pour utiliser `pdf_getPDFFont($outputlangs)`.

## Limitations et cas non traités

Chaque cas non traité (multidevise, taxes locales, situations BTP, autofacturation, AE domestique, connecteur PDP, annuaire, Order-X...) est documenté avec son comportement et la raison du choix dans **[docs/LIMITATIONS.md](docs/LIMITATIONS.md)**.

## Validation et tests

Validation externe via [B2Brouter Factur-X Validator](https://www.b2brouter.net/fr/factur-x-validator/) ou le validateur FNFE-MPE.

### Tests unitaires standalone (CI)

`tests/unit-tests.php` s'exécute **sans Dolibarr** (stubs embarqués) : 18 scénarios / 100+ assertions couvrant avoirs, remises BG-21, intracom K/AE, export, franchise, stress d'arrondis 50 lignes, acomptes, prélèvement SEPA, multidevise, formats. Chaque XML généré est validé **XSD + règles métier**.

```bash
php tests/unit-tests.php
```

Exécutés automatiquement par la CI GitHub (`.github/workflows/ci.yml`) sur chaque push/PR, et avant chaque build de release.

### Tests d'intégration

`tests/run-tests.php` couvre les 10 cas de fixtures (`demo/fixtures.php`) contre un Dolibarr réel : TypeCode, CategoryCode, unitCode, blocs optionnels, montants, validation XSD.

```bash
php tests/run-tests.php   # exit 0 = OK, 1 = échec
```

## Changelog

### 3.0.0 (juin 2026)

Refonte de conformité majeure — **lire les changements de comportement avant mise à jour**.

**Corrections de conformité (bloquantes auparavant)** :
- **Avoirs (381)** : montants désormais émis en **positif** (BR-27) avec `DuePayableAmount` exact (BR-CO-16 — l'écrêtage à zéro produisait des avoirs rejetés par les validateurs Schematron) + référence BG-3 à la facture d'origine.
- **AFRelationship `Alternative`** au lieu de `Data` (exigé par la spec Factur-X pour le profil EN16931 ; `Data` était signalé en erreur par Mustang/FNFE).
- **Remises fixes** : converties en remises document BG-21 (les lignes à prix négatif violaient BR-27).
- **Intracom (K)** : pays de livraison ShipTo (BR-IC-12) et date de livraison (BR-IC-11) émis ; distinction **K (biens) / AE (services art. 196)** par `product_type`.
- **BR-61** : bloc moyen de paiement omis si virement sans IBAN configuré (au lieu d'un XML rejeté).
- **Ventilation TVA par (catégorie, taux)** + réconciliation des arrondis avec les totaux facture (BR-CO-14/17) ; totaux BG-22 recalculés de bas en haut (BR-CO-10..16).
- **Multidevise** : détectée et refusée proprement (le XML divergeait silencieusement du PDF).

**Changements de comportement** :
- **BT-30/BT-47** : identifiant légal par défaut **SIRET sous schemeID 0009** (conforme ISO 6523, accepté Chorus Pro). L'ancien comportement (SIRET sous 0002) reste disponible : `LEMONFACTURX_LEGAL_ID_SCHEME=siret0002`.
- **Libellés moyens de paiement corrigés** : 58 = **virement** SEPA (et non prélèvement) ; nouveau code 59 = prélèvement SEPA (avec ICS/RUM/IBAN débiteur BT-89/90/91). **Vérifier votre réglage si vous aviez choisi « 58 - Prélèvement SEPA »**.
- **BT-72** : date de livraison réelle (`delivery_date`) ou bloc omis — la date d'émission n'est plus forgée en date de livraison (sauf repli intracom).
- Quantités et prix unitaires émis avec jusqu'à 4 décimales.

**Nouvelles données émises** : BT-8 (TVA débits/encaissements, config), BT-10 (`ref_client`), BT-13 (commande liée), BT-23 (cadre de facturation, config), BG-3 (factures antérieures : avoirs, rectificatives 384, acomptes imputés), BG-14 (période depuis les dates de service), BT-121 (codes VATEX), BT-89/90/91 (prélèvement).

**Outillage** :
- Validateur interne de **règles métier EN16931** (sous-ensemble Schematron en PHP) avant injection — bloquant en mode strict.
- Boutons **« Vérifier Factur-X »** / **« Régénérer Factur-X »** sur la fiche facture.
- **API REST** (`/lemonfacturx/invoice/{id}/xml` et `/status`) et **export par lot** (`scripts/export_facturx_batch.php`).
- Post-validation **veraPDF** optionnelle ; diagnostic enrichi (`MAIN_PDF_FORCE_FONT`, `exec()`, binaire PHP CLI, note multidevise).
- Suite de **tests unitaires standalone** (sans Dolibarr) + **CI GitHub** (lint + tests sur chaque push/PR, et avant chaque release).
- Traduction **en_US** complète ; messages du hook et des contrôles internationalisés.

**Robustesse et sécurité** :
- Écriture **atomique** du PDF dans le subprocess (un disque plein ne peut plus tronquer le PDF) ; retours d'écriture vérifiés ; `catch \Throwable`.
- Garde CLI sur `demo/*` et `tests/*` (les fixtures créaient un admin de démo accessibles en HTTP si le dépôt était cloné sous la racine web) + `.htaccess` de refus sur `demo/`, `tests/`, `scripts/`.
- Actions GitHub épinglées par SHA ; cache des échecs du check de version (page admin ne rame plus si GitHub est injoignable) ; garde `curl_init` ; filtre `entity` sur les comptes bancaires (multicompany) et les contacts.
- Prérequis matérialisés dans le descripteur (`phpmin` 8.1, `need_dolibarr_version` 16).
- Fonctions globales préfixées (`xmlEncode`/`formatAmount` → `lemonfacturx_xml_encode`/`lemonfacturx_format_amount`).

**Migration** : aucune migration DB, mais **désactiver puis réactiver le module** après mise à jour pour enregistrer le nouveau hook `invoicecard` (boutons de la fiche facture). Vérifier ensuite : (1) le réglage moyen de paiement si « 58 » était choisi pour du prélèvement → passer à 59 ; (2) si vos factures Chorus Pro passaient avec le SIRET sous 0002 et que votre plateforme est tatillonne, `LEMONFACTURX_LEGAL_ID_SCHEME` permet de revenir à l'ancien comportement ; (3) régénérer les avoirs récents non transmis pour bénéficier du correctif.

### 2.1.2 (juin 2026)

Correctif Chorus Pro — identifiant légal **SIRET** (et non SIREN) dans `SpecifiedLegalOrganization` :

- **`<ram:SpecifiedLegalOrganization>/ID` (BT-30 vendeur / BT-47 acheteur)** : émet désormais le **SIRET complet (14 chiffres)** au lieu du SIREN (9 chiffres), `schemeID="0002"` conservé. Chorus Pro identifie les structures par leur SIRET et rejetait un SIREN à 9 chiffres. Le fichier restait valide EN16931, d'où le passage des validateurs Factur-X mais le rejet à la transmission Chorus Pro.
- **Indépendant de l'adressage de routage** : l'endpoint BT-34/BT-49 (`schemeID="0225"`, introduit en 2.1.0) continue de porter le SIREN.
- **Diagnostic** : alerte si le SIRET émetteur (BT-30) ou acheteur (BT-47) ne fait pas 14 chiffres.

### 2.1.1 (mai 2026)

- **Franchise en base TVA** : le diagnostic ne signale plus la TVA intracommunautaire manquante comme une erreur pour une société non assujettie (293 B CGI).

### 2.1.0 (mai 2026)

- **Endpoint BT-34 / BT-49** : SIREN avec `schemeID="0225"` (annuaire PPF) au lieu de l'email — requis par le routage du réseau des Plateformes Agréées. Schéma configurable (`LEMONFACTURX_ENDPOINT_SCHEME`), repli email pour les tiers sans SIREN.

### 2.0.2 (mai 2026)

- **Compatibilité Windows** ([#4](https://github.com/hello-lemon/module-dolibarr-lemonfacturx/issues/4), [PR #5](https://github.com/hello-lemon/module-dolibarr-lemonfacturx/pull/5) de [@Charlymd](https://github.com/Charlymd)) : XML temporaire dans `DOL_DATA_ROOT/facturx/temp/`, regex `LEMONFACTURX_PHP_CLI_PATH` étendue.
- **Franchise en base TVA** ([#6](https://github.com/hello-lemon/module-dolibarr-lemonfacturx/issues/6)) : catégorie `E` (au lieu de `O`), SIREN publié en `SpecifiedTaxRegistration schemeID="FC"` (BR-CO-26/BR-E-09).

### 2.0.1 (mai 2026)

- Boutons **Corriger** du diagnostic ciblés par type d'erreur ; check des modules Dolibarr requis.

### 1.1.1 (avril 2026)

Maintenance des dépendances vendored : `atgp/factur-x` v3.3.0, `smalot/pdfparser` v2.12.5, `setasign/fpdf` 1.8.6 (patch `/F 4` réappliqué), `setasign/fpdi` v2.6.6, `symfony/polyfill-mbstring` v1.36.0.

### 1.1.0 (avril 2026)

Module distribué publiquement sur GitHub : acomptes (386, `TotalPrepaidAmount`), CategoryCode contextuel, mapping unités UN/ECE, validation XSD interne, mode strict, demo/ + tests/.

### 1.0.0

Version initiale : génération XML EN16931, injection PDF/A-3, conformité B2Brouter sur le cas standard.

## Licence

Ce module est distribué sous licence [GPLv3](https://www.gnu.org/licenses/gpl-3.0.html) — Copyright (C) 2026 [SASU Lemon](https://hellolemon.fr).

## À propos de Lemon

[Lemon](https://hellolemon.fr) est une agence web et communication basée à Clermont-Ferrand, fondée en 2012. Nous accompagnons TPE, PME et indépendants bien au-delà du simple site web :

- **Déploiement et hébergement Dolibarr** : installation, migration, paramétrage métier, formation de vos équipes
- **Modules Dolibarr sur mesure** : CRM, pointeuse NFC, facturation électronique, intégrations API, automatisations — on développe le module qui manque à votre ERP
- **Facturation électronique** : mise en conformité Factur-X EN16931, raccordement aux Plateformes Agréées (PA/PDP), accompagnement réforme 2026-2027
- **IA au service des pros** : extraction automatique de factures fournisseurs, rapprochement bancaire, génération de contenus, assistants métier — on met l'IA au travail pour vous faire gagner du temps
- **Sites web** : WordPress, Astro, Symfony — performance, SEO, éco-conception
- **Communication & print** : identité visuelle, impression, fabrication (laser, 3D)

Un projet Dolibarr, une idée d'automatisation, un besoin IA ? [Parlons-en](https://hellolemon.fr) — Clermont-Ferrand (63).
