# LemonFacturX

[![Dernière version](https://img.shields.io/github/v/release/hello-lemon/module-dolibarr-lemonfacturx?label=version&sort=semver)](https://github.com/hello-lemon/module-dolibarr-lemonfacturx/releases/latest)

Module Dolibarr pour la génération automatique de factures **Factur-X EN16931** (PDF/A-3 avec XML CrossIndustryInvoice embarqué).

Chaque facture client générée dans Dolibarr est automatiquement convertie au format Factur-X, conforme aux règles **BR-FR** (norme XP Z12-012, édition juin 2026) pour la facturation électronique française.

Développé et maintenu par [Lemon](https://hellolemon.fr), agence web et communication à Clermont-Ferrand, spécialisée dans Dolibarr, WordPress et la facturation électronique.

## Prérequis

- **Dolibarr** 19.0 → 23.x — vérifié à l'activation (`need_dolibarr_version`)
- **PHP** 8.1+ (testé sur 8.2/8.4) — vérifié à l'activation (`phpmin`)
- **Fonction `exec()`** : utilisée par défaut pour l'injection PDF (sous-process PHP isolé, le chemin le plus robuste). **Pas strictement obligatoire** : si `exec()` est désactivé (`disable_functions`, hébergements mutualisés durcis), le module bascule automatiquement en injection **in-process**. Le mode est réglable (`LEMONFACTURX_INJECTION_MODE` : `auto` / `inprocess` / `subprocess`) — voir la section Injection.
- **Une police PDF à glyphes embarqués** (nécessaire à la validité PDF/A-3). Pas de constante à poser à la main : le module fournit un **sélecteur « Police du PDF généré »** dans sa config, qui marque chaque police ✓ (embarquée, conforme) ou ⚠ (base-14, non conforme). `pdfahelvetica` est proposée par défaut.

## Installation

1. **Télécharger l'archive de la dernière release** sur
   [github.com/hello-lemon/module-dolibarr-lemonfacturx/releases/latest](https://github.com/hello-lemon/module-dolibarr-lemonfacturx/releases/latest).

   Récupérer l'asset `module_lemonfacturx-X.Y.Z.zip` attaché à la release (et **non** le
   bouton "Download ZIP" du code source — voir l'avertissement plus bas).

2. Décompresser et copier le dossier `lemonfacturx/` dans le répertoire custom de Dolibarr :

   ```bash
   unzip module_lemonfacturx-X.Y.Z.zip
   cp -r lemonfacturx/ /var/www/html/custom/
   chown -R www-data:www-data /var/www/html/custom/lemonfacturx
   ```

3. Activer le module : **Accueil > Configuration > Modules**
4. Configurer via **Accueil > Configuration > Modules > LemonFacturX** :
   - Compte bancaire (IBAN/BIC)
   - Moyen de paiement par défaut (virement, virement SEPA, prélèvement SEPA, prélèvement)
   - Identifiants vendeur/acheteur : SIRET en BT-29/BT-46 (schemeID 0009), SIREN en BT-30/BT-47 (schemeID 0002)
   - Exigibilité TVA (BT-8 : débits / encaissements), cadre de facturation (BT-23)
   - Mode de gestion d'erreur (best-effort / strict), contrôle des règles métier
   - Éventuellement chemin PHP CLI, chemin veraPDF et mentions légales
5. Choisir une **police embarquée** dans le sélecteur **« Police du PDF généré »** de la config du module (`pdfahelvetica` par défaut, marquée ✓)
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

1. **Contrôle du périmètre** : multidevise, taxes locales (localtax) et données impossibles → refus propre (PDF classique conservé)
2. **Vérification** des infos obligatoires (vendeur, acheteur, IBAN, police PDF/A) — warnings consolidés
3. **Génération du XML** CrossIndustryInvoice EN16931 avec les données de la facture Dolibarr
4. **Validation interne** : well-formed + XSD EN16931 + **règles métier BR-\*** (sous-ensemble Schematron en PHP)
5. **Injection** du XML dans le PDF via la lib `atgp/factur-x` (sous-process isolé par défaut, in-process si `exec()` indisponible ; écriture atomique, `AFRelationship=Alternative`)
6. **Post-validation veraPDF** optionnelle (PDF/A-3b)

#### Mode d'injection (`LEMONFACTURX_INJECTION_MODE`)

| Mode | Comportement |
|------|--------------|
| `auto` (défaut) | **Sous-process** si `exec()` est disponible (process PHP isolé, aucun risque de conflit de bibliothèques) ; **in-process** uniquement si `exec()` est désactivé (hébergements mutualisés durcis) |
| `inprocess` | Injection in-process **uniquement** — jamais d'`exec()`. À réserver aux serveurs sans `exec()` : un conflit de versions FPDF/FPDI avec un autre module peut empêcher l'injection (le module détecte ce cas et conserve le PDF classique) |
| `subprocess` | Sous-process PHP CLI **uniquement** (comportement historique ; nécessite `exec()`) |

**Pourquoi le sous-process par défaut** : l'in-process charge la lib d'injection (`setasign/fpdi` + `setasign/fpdf`) dans la requête web, où un autre composant (le `tcpdi` de Dolibarr, un autre module) peut avoir déjà chargé une version **incompatible** de FPDF → erreur fatale de compilation non rattrapable. Le sous-process tourne dans un process PHP vierge, sans conflit possible. Réglable dans **Configuration → bloc « Technique »**.

Sur la **fiche facture** (facture validée), deux boutons :
- **Vérifier Factur-X** : extrait le XML embarqué du PDF et le revalide (XSD + règles métier) — à utiliser avant envoi
- **Régénérer Factur-X** : régénère le PDF (et donc l'injection) — utile après une mise à jour du module ou une correction de données

### Support des modèles ODT (depuis 3.8.0)

Par défaut, l'injection Factur-X ne s'opère que sur les modèles de facture **TCPDF natifs** (sponge / crabe / octopus), via le hook `afterPDFCreation`. Les modèles **ODT** produisent un fichier `.odt` (puis un `.pdf` après conversion) et ne déclenchent que des hooks ODT : sans configuration spécifique, aucune injection n'avait lieu.

Depuis la 3.8.0, le module se branche aussi sur le hook `afterODTCreation` (contexte `odtgeneration`) et injecte le XML dans le PDF issu de la conversion LibreOffice. **Deux prérequis** côté Dolibarr (**Configuration → Divers → Autres**) :

| Constante | Valeur | Rôle |
|-----------|--------|------|
| `MAIN_ODT_AS_PDF` | `libreoffice` | Convertit le `.odt` en `.pdf` à la génération (nécessite **LibreOffice installé** sur le serveur, binaire `soffice` accessible) |
| `MAIN_ODT_AS_PDFA` | `1` | Force l'export en **PDF/A-1** (PDF 1.4). Indispensable : sans ça, LibreOffice produit un PDF ≥ 1.5 (table xref compressée) que la bibliothèque d'injection (FPDI) ne sait pas lire, et l'injection échoue proprement avec un avertissement |

Comportement **best-effort** : si l'un de ces prérequis manque (conversion désactivée, PDF/A non forcé, PDF introuvable, LibreOffice absent), le module **n'injecte pas**, conserve le PDF/ODT classique et affiche un **avertissement clair** indiquant la constante à poser — jamais de fatale ni de PDF corrompu. Le mode strict (`LEMONFACTURX_STRICT_MODE`) transforme cet avertissement en erreur bloquante, comme pour le flux TCPDF.

> **Statut** — la chaîne d'injection a été **validée en interne sur LibreOffice 7.4** (le PDF/A-1 produit par LibreOffice est lu par la bibliothèque d'injection (FPDI), le Factur-X s'y embarque, le handler `afterODTCreation` dérive le bon PDF et injecte) **et en condition réelle** : un utilisateur a confirmé la génération d'une facture via modèle ODT sur son Dolibarr, avec le XML Factur-X présent dans le PDF (Factur-X principal + 2e PDF Chorus). Sur des templates variés (`.odt`/`.ods`), il reste conseillé de vérifier le PDF avec un validateur Factur-X (bouton **Vérifier Factur-X** de la fiche facture, ou un validateur en ligne type FNFE-MPE) pour confirmer le niveau PDF/A-3. Remontez tout problème via [SECURITY.md](SECURITY.md) / hello@hellolemon.fr.
>
> **Activation** : ce nouveau contexte de hook n'est pris en compte qu'après **désactivation/réactivation** du module (la désactivation ne supprime aucune donnée).

### Sécurité

- Scripts CLI (`scripts/`, `tests/`, `demo/`) protégés par `PHP_SAPI === 'cli'` **et** `.htaccess` `Require all denied`
- **`exec()` non obligatoire** : sur un hébergement durci qui le désactive, le module bascule en injection in-process et reste fonctionnel (compatibilité mutualisés). Quand le sous-process est utilisé (cas par défaut), la commande est protégée : `escapeshellarg()` sur tous les tokens, binaire PHP CLI configurable via `LEMONFACTURX_PHP_CLI_PATH`, validé par regex et `is_executable()` si absolu
- Écriture **atomique** du PDF (fichier temporaire + `rename()`), quel que soit le mode
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
| BT-8 VAT due date code | Régime TVA Dolibarr (`TAX_MODE`) : 5 débits / 72 encaissements, omis si indéterminé |
| BT-9 Due date | `$invoice->date_lim_reglement` |
| BT-10 Buyer reference | `$invoice->ref_client` (code service / n° engagement Chorus Pro) |
| BT-13 Order reference | Réf. de la première commande client liée |
| BT-23 Business process | Cadre de facturation choisi **par facture** (profil Chorus Pro : `A1` par défaut), omis hors Chorus Pro |
| BT-25/BG-3 Preceding invoice | `fk_facture_source` (avoir/rectificative) + acomptes imputés |
| Seller / Buyer | `$mysoc` / `$invoice->thirdparty` |
| BT-29/BT-46 ID établissement | `idprof2` → SIRET (14 chiffres) sous schemeID 0009 (ISO 6523) — pour Chorus Pro |
| BT-30/BT-47 ID légal | `idprof2` → SIREN (9 chiffres) sous schemeID 0002 (ISO 6523) — exigé par BR-FR-10 / Plateformes Agréées |
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

### Double circuit B2B (PDP) et B2G (Chorus Pro) — depuis 3.4.0

La réforme française repose sur **deux réseaux distincts et permanents** qui exigent des choses opposées dans le même champ `SpecifiedLegalOrganization/ID` :
- **PDP / B2B** (réseau des Plateformes Agréées) : **SIREN** (9 chiffres), conforme EN16931 / règle BR-FR-10 ;
- **Chorus Pro / B2G** (secteur public) : **SIRET** (14 chiffres), clé de routage de son annuaire.

Un même XML ne peut pas satisfaire les deux. LemonFacturX résout ça **sans jamais dégrader le PDF principal** :

- Le **PDF de la facture reste le Factur-X standard EN16931** (profil PDP, SIREN) — c'est l'objet du module, toujours conforme.
- Quand la facture relève du secteur public, le module génère **en plus** un second fichier **`{ref}-CHORUS.pdf`** dans la liste des documents, au profil Chorus Pro (SIRET-14 dans `SpecifiedLegalOrganization` + champs BT-10/12/13). Vous déposez celui-ci sur Chorus Pro, l'autre part sur votre PDP.

**Onglet « Chorus Pro »** : les paramètres Chorus se règlent dans un onglet dédié sur la fiche facture (ils n'encombrent pas l'onglet « Données complémentaires »).

**Déclenchement du PDF Chorus** (un seul signal suffit) :
1. cocher **« Facture Chorus Pro »** dans l'onglet Chorus Pro de la facture ;
2. renseigner un des champs Chorus (code service / n° engagement / n° marché) ;
3. automatiquement si le SIRET de l'acheteur est celui de l'État central (`110002011…`).

**Champs Chorus** (onglet Chorus Pro, repris dans le XML Chorus) :
| Champ | Code EN16931 | Élément CII |
|---|---|---|
| Cadre de facturation (24 valeurs A1–A25) | BT-23 | `BusinessProcessSpecifiedDocumentContextParameter` |
| Code service exécutant | BT-10 | `BuyerReference` |
| N° d'engagement juridique | BT-13 | `BuyerOrderReferencedDocument` |
| N° de marché | BT-12 | `ContractReferencedDocument` |

Le **cadre de facturation** (BT-23) est obligatoire pour Chorus et se choisit par facture (A1 = dépôt fournisseur par défaut ; A9/A10 sous-traitance, A12+ cotraitance, A3 frais de justice, etc.).

**Menu d'actions** : les actions Factur-X de la fiche facture sont regroupées dans un menu déroulant **« Factur-X ▾ »** (Vérifier / Régénérer / Générer le PDF Chorus).

> ⚠️ Le PDF Chorus corrige le **format**. Le dépôt réussit seulement si l'émetteur et la structure publique destinataire sont **réellement raccordés sur Chorus Pro** (code service, n° d'engagement valides). Le XML ne crée pas le raccordement.
>
> **Mise à jour depuis < 3.4.0** : désactiver puis réactiver le module pour créer les extrafields Chorus sur les factures (aucune donnée supprimée).

### Mentions légales FR (BR-FR-05)

Le XML inclut automatiquement les notes obligatoires :
- **PMD** : pénalités de retard (3x taux d'intérêt légal, art. L.441-10)
- **PMT** : indemnité forfaitaire de recouvrement (40 €)
- **AAB** : escompte pour paiement anticipé

## Constantes du module

Toutes sont configurables via l'écran d'administration du module (**Accueil > Configuration > Modules > LemonFacturX**).

| Constante | Type | Défaut | Description |
|---|---|---|---|
| `LEMONFACTURX_BANK_ACCOUNT` | int | 0 | ID du compte bancaire Dolibarr |
| `LEMONFACTURX_PAYMENT_MEANS` | string | 30 | Code UNTDID 4461 : 30 virement, 58 virement SEPA, 59 prélèvement SEPA, 49 prélèvement |
| `LEMONFACTURX_STRICT_MODE` | int | 0 | 0 = best-effort (défaut), 1 = strict (voir ci-dessous) |
| `LEMONFACTURX_BR_CHECK` | int | 1 | Contrôle interne des règles métier EN16931 avant injection |
| `LEMONFACTURX_INJECTION_MODE` | string | auto | Mode d'injection : `auto` (in-process + repli sous-process), `inprocess` (sans exec), `subprocess` (exec uniquement) |
| `LEMONFACTURX_PHP_CLI_PATH` | string | *(vide)* | Chemin du binaire PHP CLI, utilisé **uniquement** par le mode `subprocess` (vide = auto-détection ; voir note ci-dessous) |
| `LEMONFACTURX_VERAPDF_PATH` | string | *(vide)* | Chemin veraPDF : post-validation PDF/A-3b de chaque PDF généré (non bloquant) |
| `LEMONFACTURX_ENDPOINT_SUFFIX_SELLER` | string | *(vide)* | Suffixe ajouté au SIREN vendeur dans l'endpoint électronique BT-34 (ex `_Status`) — exigé par certaines PA ; vide = SIREN nu |
| `LEMONFACTURX_NOTE_PMD/PMT/AAB` | text | mentions FR | Mentions légales BR-FR-05 (le texte par défaut s'applique si le champ est laissé vide) |
| `LEMONFACTURX_NOTES_IN_FOOTER` | int | 0 | Recopier les mentions BR-FR-05 dans le pied de facture (`INVOICE_FREE_TEXT`) |
| `LEMONFACTURX_NOTES_OVERWRITE` | int | 0 | Recopie pied de facture : écraser la mention Dolibarr existante (1) au lieu d'ajouter nos mentions à la suite (0) |
| `LEMONFACTURX_CHORUS_ENABLED` | int | 0 | Activer les fonctionnalités Chorus Pro (onglet, menu, 2ᵉ PDF) — opt-in |

> **Note PHP CLI** : pertinente **uniquement en mode `subprocess`** (l'injection par défaut est in-process et n'utilise aucun binaire externe). Dans ce mode, le module auto-détecte le bon binaire PHP CLI. Sur les serveurs avec plusieurs versions de PHP, ou si l'auto-détection échoue, configurer `LEMONFACTURX_PHP_CLI_PATH` avec le chemin complet (ex: `/usr/bin/php8.2`). Ne **pas** utiliser `PHP_BINARY` : en contexte php-fpm, cette constante pointe vers le binaire fpm et non le CLI.

> **Note prélèvement SEPA (59)** : le module publie l'ICS créancier (constante Dolibarr `PRELEVEMENT_ICS`, BT-90), la RUM du mandat (RIB par défaut du tiers, BT-89) et l'IBAN débiteur (BT-91). Des warnings signalent les données manquantes.

### Mode strict vs best-effort

Par défaut le module est en **best-effort** : si le XML Factur-X est invalide ou si l'injection PDF échoue, un warning est affiché à l'utilisateur et le PDF classique (sans Factur-X embarqué) est conservé. Les erreurs sont loguées dans `syslog` avec le tag `LemonFacturX`.

En **mode strict** (`LEMONFACTURX_STRICT_MODE=1`), la même situation retourne une erreur bloquante visible, et les violations de règles métier (BR-\*) deviennent bloquantes. **Limite assumée** : le hook intervenant après la création du PDF par Dolibarr, le PDF classique déjà généré reste sur le disque même en strict — utiliser « Vérifier Factur-X » avant envoi pour contrôler un fichier.

### Validation interne

Avant injection PDF, le module valide systématiquement le XML :

1. **Well-formed** : `DOMDocument::loadXML()`
2. **XSD EN16931** : `DOMDocument::schemaValidate()` contre le XSD embarqué
3. **Règles métier** (`LEMONFACTURX_BR_CHECK`, défaut activé) : sous-ensemble des règles Schematron EN16931 vérifié en PHP — règles de calcul BR-CO-10..17, BR-27 (prix négatifs), BR-61 (IBAN), BR-16, BR-IC-02/11/12 (intracom), BR-AE-02, motifs d'exonération BR-\*-10, BR-CO-25/26, BR-09/11 — **plus le bloc de règles France BR-FR** (voir section suivante)

Le Schematron officiel complet (XSLT 2.0) n'est pas exécutable en PHP : pour une validation exhaustive, utiliser un validateur externe — voir [docs/LIMITATIONS.md](docs/LIMITATIONS.md).

### Conformité XP Z12-012 (règles France)

Règles du socle réforme française contrôlées ou émises par le module. Le bloc de règles BR-FR du validateur interne ne s'applique que si le **vendeur est établi en France** (métropole + DOM assimilés, cf. BR-FR-MAP-14) : Factur-X est aussi utilisé hors de France (ZUGFeRD) et le socle réforme n'est pas opposable à un vendeur étranger — les règles EN16931, elles, restent universelles.

| Règle | Objet | Traitement |
|---|---|---|
| BR-FR-01 / BR-FR-02 | Identifiant de facture (BT-1) : 35 caractères max, alphanumériques + `- + _ /` (espace interdit) | Violation bloquante (validateur) + warning amont |
| BR-FR-04 | Type de document (BT-3) : liste fermée FR (380, 381, 384, 386, avoirs/rectificatives déclinés…) | Violation bloquante (allowlist défensive) |
| BR-FR-05 / BR-FR-06 | Notes PMD, PMT, AAB (pénalités, indemnité 40 €, escompte), une seule fois chacune | Émises automatiquement, surchargeables |
| BR-FR-07 / BR-FR-20 / BR-FR-31 | Note **BAR** qualifiant le traitement attendu : `B2B` (e-invoicing, cas nominal), `B2BINT` (acheteur assujetti hors France), `B2C` (acheteur non assujetti, **y compris établi hors France** — choix assumé : la liste fermée de BR-FR-20, révision juillet 2025, ne comporte pas de valeur « B2C international ») — DOM assimilés à la France (BR-FR-MAP-14) | Émise automatiquement (profil PDP), une seule occurrence |
| BR-FR-08 | Cadre de facturation (BT-23) : socle `B1`/`S1`/`M1` selon la nature des lignes (cas nominal : dépôt par le fournisseur), `B4`/`S4`/`M4` pour une facture définitive après acompte (jamais sur l'acompte lui-même — BR-FR-CO-08) ; cadres AIFE `A1..A25` par facture en profil Chorus B2G | Émis automatiquement |
| BR-FR-09 | Cohérence SIRET/SIREN (vendeur et acheteur) : SIRET à 14 chiffres dont les 9 premiers = SIREN | Violation bloquante + warning amont |
| BR-FR-16 | Taux de TVA vs liste française fermée (métropole + DOM) | **Warning uniquement** — un taux légitime n'est jamais bloqué |
| BR-FR-CO-04 / BR-FR-CO-05 | Rectificative (384…) : exactement **une** référence à la facture d'origine (BT-25) avec sa date — le générateur ne référence alors QUE la facture remplacée en BG-3 (les acomptes imputés restent portés par BT-113) ; avoir (381…) : au moins une référence datée | Violation bloquante (+ warning amont si la facture d'origine n'est pas liée) |
| BR-FR-23 / BR-FR-25 | Adresse électronique 0225 : alphanumériques + `- _ .` uniquement (le suffixe vendeur configuré est sanitisé à l'émission), 125 caractères max | Violation bloquante (charset) + warning (longueur) |
| BR-FR-MAP-03 / MAP-29 | Exigibilité TVA (BT-8) : `5` = débits (seule valeur CII attendue par le PPF), `72` = encaissements, dérivée de `TAX_MODE` | Émis automatiquement |

Rappel du fonctionnement : les « violations bloquantes » n'interrompent la génération qu'en mode strict (`LEMONFACTURX_STRICT_MODE=1`) ; en best-effort elles sont consolidées dans le message d'avertissement et le syslog.

**Limitations assumées** :
- le profil **EXTENDED-CTC-FR n'est pas émis** (le module produit du EN16931) — cible envisagée pour 2027 ; la variante « référence en ligne » d'un avoir (EXT-FR-FE-136, BR-FR-CO-05) est donc sans objet ;
- la **construction des flux 1 / 10.1** vers le PPF (troncatures BR-FR-MAP-\*, transcodage des codes pays DOM…) est **déléguée à la Plateforme Agréée** : le module fournit le Factur-X source ;
- BR-FR-10/11 (SIREN présent et actif dans l'annuaire PPF) nécessitent l'accès à l'annuaire : pré-check couvert par LemonSuperPDP, pas par ce module.

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
- **Polices embarquées** : choisies via le sélecteur **« Police du PDF généré »** du module (`pdfahelvetica` par défaut) ; le diagnostic vérifie qu'une police embarquée est bien posée. Sans police embarquée (Helvetica base-14), le PDF/A-3 est invalide même si le rendu paraît correct.
- **AFRelationship `Alternative`** : conforme à la spec Factur-X pour le profil EN16931 (corrigé en 3.0.0, `Data` auparavant)
- **Annotations /F flag** : patch appliqué dans `vendor/setasign/fpdf/fpdf.php` (ajout `/F 4` aux liens)
- **Profil ICC sRGB** + **métadonnées XMP** : gérés par la lib `atgp/factur-x`
- **Post-validation veraPDF** optionnelle (`LEMONFACTURX_VERAPDF_PATH`) pour détecter les modèles PDF custom non conformes

> **Note** : si un module tiers (ex: milestone/jalons) hardcode la police `'Helvetica'`, il faudra le patcher pour utiliser `pdf_getPDFFont($outputlangs)`.

## Limitations et cas non traités

Chaque cas non traité (multidevise, taxes locales, situations BTP, autofacturation, AE domestique, connecteur PDP, annuaire, Order-X...) est documenté avec son comportement et la raison du choix dans **[docs/LIMITATIONS.md](docs/LIMITATIONS.md)**.

## Validation et tests

Validation externe via [B2Brouter Factur-X Validator](https://www.b2brouter.net/fr/factur-x-validator/) ou le validateur FNFE-MPE.

### Validateurs en ligne : faux positifs connus

Tous les validateurs en ligne n'embarquent pas la même version des schémas et listes de codes. Un fichier **conforme aux artefacts officiels actuels** peut être rejeté par un validateur resté sur des artefacts ~2021. Deux rejets à tort identifiés (juin 2026, fichier contre-validé par xmllint/XSD 1.08 + veraPDF 146/146 + second validateur) :

| Rejet affiché | Cause côté validateur | Réalité |
|---|---|---|
| XSD : « `InvoiceReferencedDocument`… not expected. Expected is (`ReceivableSpecifiedTradeAccountingAccount`) » | XSD de l'ère ZUGFeRD 2.1.1 : `InvoiceReferencedDocument` sans `maxOccurs` → 1 seul autorisé. Le 2e (facture finale avec plusieurs acomptes/avoirs imputés) le fait échouer | BG-3 est **0..n** dans EN16931 ; le XSD Factur-X 1.08 (embarqué ici) le déclare `maxOccurs="unbounded"` |
| « [BR-CL-25] L'identifiant du schéma… DOIT appartenir à la liste de codes CEF EAS » sur l'endpoint `0225` | Liste EAS des [artefacts de validation](https://github.com/ConnectingEurope/eInvoicing-EN16931) ≤ 1.3.9 (oct. 2021), qui s'arrête à `0220` | `0225` (adresse électronique SIREN, annuaire de la réforme FR) figure dans la liste EAS actuelle |

En cas de doute, faire foi : validation XSD contre les schémas Factur-X 1.08 embarqués (`xmllint --schema`), artefacts Schematron **à jour** de la Commission européenne, et veraPDF pour le PDF/A-3. Ne pas dégrader le XML (retirer le BG-3 multiple, changer `0225`) pour satisfaire un validateur périmé.

### Tests unitaires standalone (CI)

`tests/unit-tests.php` s'exécute **sans Dolibarr** (stubs embarqués) : 28 scénarios / 170+ assertions couvrant avoirs, remises BG-21, intracom K/AE, export, franchise, stress d'arrondis 50 lignes, acomptes, prélèvement SEPA, multidevise, formats, et les règles France XP Z12-012 (BT-1, cohérence SIREN/SIRET, BG-3 des avoirs/rectificatives — dont la 384 avec acompte imputé, BT-23 socle, note BAR, sanitisation d'endpoint, taux de TVA, non-application du bloc BR-FR à un vendeur étranger). Chaque XML généré est validé **XSD + règles métier**.

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

### 3.9.1 (juillet 2026)

`ActionsLemonFacturX::verifyInvoicePdf()` passe **publique** : elle est appelée par l'onglet « Facturation électronique » de LemonSuperPDP (bouton « Vérifier la Factur-X ») pour afficher le résultat sans quitter l'onglet. Aucun autre changement.

### 3.9.0 (juillet 2026)

**Mise en conformité XP Z12-012 (édition juin 2026) — socle minimum de la réforme.**
- **Bloc de règles France au validateur** : BR-FR-01/02 (BT-1 ≤ 35 caractères, charset strict sans espace), BR-FR-04 (liste fermée des types de document BT-3), BR-FR-09 (cohérence SIRET/SIREN vendeur et acheteur), BR-FR-23 (charset des adresses électroniques en schemeID 0225).
- **Références obligatoires** (BR-FR-CO-04/05) : un avoir sans référence à la facture d'origine est désormais **bloqué** (plus un simple warning) ; une rectificative (384) exige exactement une référence datée — et n'agrège plus les acomptes imputés dans BG-3 (bug corrigé).
- **BT-23 cadre de facturation** émis pour le profil PDP/B2B (B1/S1/M1 déduit des lignes, x4 pour une facture définitive après acomptes) — le chemin Chorus Pro B2G (codes AIFE) est inchangé.
- **Note BAR** (BR-FR-20) : qualification du traitement attendue par les plateformes (B2B / B2BINT / B2C) émise dans le XML, déduite du profil du tiers.
- Sanitisation du suffixe d'endpoint vendeur + borne de longueur (BR-FR-23/25) ; BT-8 vérifié contre la codelist (mapping 5/72 déjà correct, documentation enrichie).
- **+50 tests unitaires** (177 au total) couvrant toutes les nouvelles règles.
- Limitation assumée documentée : profil EXTENDED-CTC-FR (multi-vendeurs, sous-lignes) non émis — cible 2027 ; la construction des flux 1/10.1 relève de la Plateforme Agréée.

### 3.8.0 (juin 2026)

**Nouveau : support des modèles de facture ODT.** Le module se branche désormais aussi sur le hook `afterODTCreation` (contexte `odtgeneration`) et injecte le Factur-X dans le PDF issu d'un modèle ODT converti par LibreOffice (Factur-X principal + 2e PDF Chorus le cas échéant). Prérequis : `MAIN_ODT_AS_PDF = libreoffice` + `MAIN_ODT_AS_PDFA = 1` (sinon le PDF produit est ≥ 1.5 / xref compressé, illisible par FPDI). Code 100 % best-effort : toute condition manquante dégrade proprement avec un avertissement explicite, sans fatale ni PDF corrompu. Validé en interne (LibreOffice 7.4) et en condition réelle (génération ODT confirmée sur un Dolibarr utilisateur) ; voir la section « Support des modèles ODT ». **Réactiver le module** pour que le nouveau contexte de hook soit pris en compte.

### 3.7.3 (juin 2026)

**Correctif de conformité EN16931.** L'identifiant SIRET du vendeur (BT-29) et de l'acheteur (BT-46) était émis dans `ram:ID schemeID="0009"` au niveau du `TradeParty`. Or EN16931 ne lit le `schemeID` que sur `ram:GlobalID` : sur `ram:ID` l'attribut est « not used in the given context » et faisait **échouer la validation Schematron** (constaté avec le validateur FNFE-MPE — « Fully Valid : NO »). L'identifiant qualifié passe désormais dans `ram:GlobalID schemeID="0009"`. Le SIREN en `SpecifiedLegalOrganization` (BT-30, `schemeID="0002"`) et le profil Chorus Pro ne changent pas. Régénérer les factures concernées pour bénéficier du correctif.

### 3.7.2 (juin 2026)

**Correctif API REST.** L'API n'expose plus la classe core `Facture` dans son spec (`@return object` au lieu de `@return Facture`) : le `@return Facture` faisait planter en **HTTP 500** la génération de `/explorer/swagger.json` dès que le module était actif (Restler tentait de modéliser toute la classe core). Les appels API authentifiés directs n'étaient pas touchés — seul l'explorer / le spec OpenAPI.

### 3.7.1 (juin 2026)

**Correctif de régression (à appliquer).** La 3.7.0 avait fait de l'injection in-process le mode par défaut, ce qui déclenche une erreur fatale `Declaration of FpdfTplTrait::setPageFormat must be compatible with TCPDF::setPageFormat` sur un Dolibarr standard : `pdf_getInstance()` charge le moteur `tcpdi` (`class FPDF extends TCPDF`) à chaque génération, et la lib d'injection FPDI en hérite alors → conflit de signature, génération de facture cassée. L'injection repasse par un **sous-process PHP isolé** dès qu'`exec()` est disponible (process vierge, aucun conflit possible — comportement d'avant la 3.7.0). L'in-process n'est tenté que si `exec()` est désactivé, avec un **garde-fou** qui conserve le PDF classique au lieu de planter, et un **diagnostic** qui indique la marche à suivre (activer `exec()`, ou poser `MAIN_DISABLE_TCPDI=1`).

### 3.7.0 (juin 2026)

Mode d'injection réglable (`LEMONFACTURX_INJECTION_MODE`) avec injection in-process sans `exec()`. **Ne pas utiliser tel quel — corrigé en 3.7.1** (conflit FPDF/TCPDF via le moteur `tcpdi` de Dolibarr).

### 3.6.3 (juin 2026)

Sélecteur de **police PDF** dans la configuration (compatibilité Factur-X indiquée pour chaque police) ; mentions légales BR-FR-05 centrées sur le PDF.

### 3.6.2 (juin 2026)

Recopie des mentions légales BR-FR-05 dans le pied de facture (`INVOICE_FREE_TEXT`) avec option « écraser » ; bouton pour reporter les champs Chorus dans la note publique de la facture.

### 3.6.1 (juin 2026)

Correctif : les champs Chorus n'apparaissent plus comme un bloc parasite dans le corps du PDF sous **Dolibarr 23** (`printable=0` forcé sur les extrafields Chorus).

### 3.6.0 (juin 2026)

Compatibilité **Dolibarr 23** (onglet et hooks Chorus), meilleures performances sur les factures en brouillon, suffixe d'endpoint vendeur (BT-34) pour l'adressage des Plateformes Agréées.

### 3.5.1 (juin 2026)

Correctif de la détection du binaire PHP CLI sous **Windows**.

### 3.5.0 (juin 2026)

Réglages simplifiés et **auto-détection du binaire PHP CLI** (plus de configuration manuelle dans le cas courant).

### 3.4.0 (juin 2026)

Support **Chorus Pro (B2G)** : génération d'un second PDF dédié au profil Chorus, sans jamais modifier le Factur-X standard.

### 3.2.1 (juin 2026)

SIREN lu depuis le champ SIREN de Dolibarr (`idprof1`) plutôt que dérivé du SIRET.

### 3.2.0 (juin 2026)

**SIRET et SIREN dans deux champs distincts** (SIRET → `ram:ID` 0009 BT-29/46, SIREN → `SpecifiedLegalOrganization` 0002 BT-30/47) — corrige le rejet au dépôt sur les Plateformes Agréées (BR-FR-10).

### 3.1.0 (juin 2026)

Mentions légales BR-FR-05 rendues visibles sur le PDF en un clic depuis la configuration.

### 3.0.3 (juin 2026)

Avertissement SIREN/routage : critère « non-assujetti à la TVA » complété pour ne plus signaler à tort certains tiers.

### 3.0.2 (juin 2026)

Correction en un clic de `MAIN_PDF_FORCE_FONT` depuis le diagnostic.

### 3.0.1 (juin 2026)

Plus d'avertissement SIREN pour les clients particuliers (B2C).

### 3.0.0 (juin 2026)

Refonte de conformité majeure — **lire les changements de comportement avant mise à jour**.

**Corrections de conformité (bloquantes auparavant)** :
- **Avoirs (381)** : montants désormais émis en **positif** (BR-27) avec `DuePayableAmount` exact (BR-CO-16 — l'écrêtage à zéro produisait des avoirs rejetés par les validateurs Schematron) + référence BG-3 à la facture d'origine.
- **AFRelationship `Alternative`** au lieu de `Data` (exigé par la spec Factur-X pour le profil EN16931 ; `Data` était signalé en erreur par Mustang/FNFE).
- **Remises fixes** : converties en remises document BG-21 (les lignes à prix négatif violaient BR-27).
- **Intracom (K)** : pays de livraison ShipTo (BR-IC-12) et date de livraison (BR-IC-11) émis ; distinction **K (biens) / AE (services art. 196)** par `product_type`.
- **BR-61** : bloc moyen de paiement omis si virement sans IBAN configuré (au lieu d'un XML rejeté).
- **Ventilation TVA par (catégorie, taux)** + réconciliation des arrondis avec les totaux facture (BR-CO-14/17) ; totaux BG-22 recalculés de bas en haut (BR-CO-10..16).
- **Multidevise et taxes locales** : détectées et refusées proprement (le XML divergeait silencieusement du PDF visible).
- **SIREN/SIRET réservés aux tiers français** : l'identifiant local d'un tiers étranger (HRB allemand...) n'est plus publié sous un scheme SIREN/SIRET — repli email pour l'endpoint.

**Changements de comportement** :
- **Deux identifiants dans deux champs distincts (depuis 3.2.0)** : le **SIRET** (établissement, 14 chiffres) va dans `ram:ID` schemeID 0009 (BT-29/BT-46), et le **SIREN** (entité légale, 9 chiffres) dans `SpecifiedLegalOrganization/ram:ID` schemeID 0002 (BT-30/BT-47). Jusqu'en 3.1.x le module mettait à tort le SIRET dans `SpecifiedLegalOrganization`, ce qui faisait échouer la règle **BR-FR-10** (« SIREN du vendeur obligatoire, exactement 9 chiffres ») et le dépôt sur les Plateformes Agréées (« l'entreprise liée à la session ne correspond pas au vendeur »). Chorus Pro conserve son SIRET via BT-29/BT-46. Aucun réglage nécessaire : chaque identifiant à sa place.
- **Mentions légales BR-FR-05 (PMD/PMT/AAB)** : le texte par défaut s'applique désormais réellement quand le champ est laissé vide (auparavant les constantes créées vides à l'activation produisaient des mentions vides dans le XML).
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

**Migration** : aucune migration DB, mais **désactiver puis réactiver le module** après mise à jour pour enregistrer le nouveau hook `invoicecard` (boutons de la fiche facture). Vérifier ensuite : (1) le réglage moyen de paiement si « 58 » était choisi pour du prélèvement → passer à 59 ; (2) régénérer les avoirs récents non transmis pour bénéficier du correctif. L'identifiant légal BT-30/BT-47 est désormais toujours SIRET/0009 (plus de réglage).

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
