# Limitations et cas non traités

Ce document liste les cas que LemonFacturX **ne traite pas** (ou ne traite que
partiellement), le comportement observé, et la raison du choix. Il fait foi en
cas de doute sur le périmètre du module.

Légende comportement :
- **Refus propre** : la facture n'est pas convertie, le PDF classique est conservé, un message explique pourquoi (bloquant en mode strict).
- **Warning** : la facture est convertie, mais un avertissement signale la limite.
- **Silencieux** : le cas est traité avec une approximation documentée ici.

## Cas métier

### Multidevise (BT-5 ≠ devise société)
**Comportement : refus propre.**
Dolibarr ne stocke la ventilation TVA qu'en devise société. Émettre le XML en
devise société alors que le PDF visible est en devise étrangère violerait le
principe hybride Factur-X (le lisible doit refléter le structuré). Un support
complet exigerait les montants `multicurrency_total_*` par ligne, le
`TaxCurrencyCode` (BT-6) et le double `TaxTotalAmount` — la fiabilité des
montants multidevise ligne à ligne dans Dolibarr (arrondis de conversion)
ne permet pas de garantir les règles BR-CO-* aujourd'hui. Le diagnostic admin
affiche une note si le module Multidevise est actif.

### Taxes locales (localtax1/localtax2)
**Comportement : refus propre.**
EN16931 ne représente que la TVA. Les taxes parafiscales locales (IRPF
espagnol, RECARGO, etc.) ne sont pas mappables : le total du XML divergerait
du TTC visible sur le PDF — même principe de refus que le multidevise. Hors
périmètre France métropolitaine, qui est la cible du module.

### Factures de situation (BTP)
**Comportement : warning, émission en TypeCode 380.**
Les lignes Dolibarr de situation portent des pourcentages cumulés et des
retenues de garantie dont le mapping EN16931 (BG-14 par situation, chaînage
BG-3 vers les situations précédentes, retenue en BT-115 vs charge) n'est pas
normalisé de façon stable dans les spécifications externes actuelles. Les
montants émis sont ceux de la facture Dolibarr (delta de la situation), ce qui
est généralement correct, mais la conformité n'est pas garantie — d'où le
warning. Support complet prévu quand le cas d'usage « facture de travaux »
des spécifications PPF/PDP sera figé.

### Autofacturation (TypeCode 389)
**Comportement : non traité (émis comme une facture normale).**
Dolibarr ne porte pas de notion d'autofacturation sur les factures clients.
Le cas exige l'inversion des rôles vendeur/acheteur et un cadre de
facturation dédié — sans donnée source fiable, toute déduction serait fausse.

### Acomptes : TVA de l'acompte sur la facture finale
**Comportement : déduction TTC uniquement (BT-113), warning absent.**
La facture finale déduit l'acompte via `TotalPrepaidAmount` (BT-113) et
référence la facture d'acompte (BG-3). La ventilation TVA de la finale reste
celle de la facture complète : la TVA déjà acquittée sur l'acompte n'est pas
isolée (le schéma « lignes négatives par taux » préconisé par certaines
plateformes n'est pas généralisable depuis les données Dolibarr). C'est le
comportement de la majorité des implémentations Factur-X actuelles ; voir
`docs/spec-acomptes.md`.

### Exonérations françaises spécifiques (BT-121)
**Comportement : partiel.**
Codes VATEX émis : `VATEX-FR-FRANCHISE` (franchise 293 B), `VATEX-EU-IC`
(livraison intracom), `VATEX-EU-AE` (autoliquidation services), `VATEX-EU-G`
(export). Pour une ligne FR à TVA 0 hors franchise (formation 261-4, presse,
DOM 296ter...), le module émet `E` avec le motif générique « Exonéré de TVA »
**sans** code VATEX : la base légale ne peut pas être devinée depuis les
données Dolibarr. Préciser le motif réel dans la description de ligne, ou
demander l'ajout d'un mapping par produit si le besoin est récurrent.

### Autoliquidation domestique (sous-traitance BTP, art. 283-2 nonies CGI)
**Comportement : non détecté automatiquement.**
Une ligne FR→FR à TVA 0 tombe en catégorie `E` générique. La qualification AE
domestique exigerait un flag par ligne ou par tiers (extrafield) que le module
ne crée pas pour éviter de modifier le schéma de données. L'AE est en revanche
correctement émis pour les **services intracommunautaires** (détection par
`product_type` + TVA intra acheteur).

### Catégories O, Z, L, M
**Comportement : non émises.**
`O` (hors champ) : jamais produit — la franchise 293 B est une exonération
(`E`), pas un hors-champ. `Z` (taux zéro) : sans objet en droit français.
`L`/`M` (Canaries/Ceuta) : hors périmètre France.

### Retenue de garantie, escompte conditionnel structuré (BT-20/SKONTO)
**Comportement : texte libre uniquement.**
Les conditions d'escompte sont portées par la note AAB (BR-FR-05). La
structuration SKONTO est une convention EXTENDED/allemande, hors profil
EN16931 strict.

## Transmission et réforme 2026-2027

### Connecteur PDP/PPF (dépôt, statuts de cycle de vie)
**Non traité.** Le module produit le **format** (Factur-X EN16931 + données
socle BT-23/BT-8/SIREN 0225). La **transmission** (API de dépôt, accusés,
statuts rejetée/refusée/encaissée) est spécifique à chaque Plateforme Agréée
et exige des credentials — c'est un module compagnon à part entière, pas une
évolution de celui-ci. Contact : hello@hellolemon.fr (raccordement PA/PDP).

### Annuaire (vérification du SIREN destinataire)
**Non traité.** L'API de l'annuaire n'est accessible qu'aux plateformes
immatriculées. Le module vérifie en revanche la présence et la longueur du
SIREN/SIRET acheteur (warnings + diagnostic).

### Type d'opération (livraison de biens / prestation / mixte)
**Non traité.** Donnée du socle réforme sans BT dédié dans le profil EN16931
(portée par les flux PPF, pas par le XML CII). Sera ajoutée quand le cadre
de facturation B2B (BT-23) deviendra obligatoire avec une sémantique figée.

### Code routage / service destinataire infra-SIREN
**Partiel.** Le routage par service (Chorus Pro, grands comptes) passe par
**BT-10 BuyerReference**, mappé depuis le champ standard « Référence client »
(`ref_client`) de la facture — par facture, sans extrafield. L'adressage
électronique BT-34/BT-49 est en revanche figé sur le SIREN (`schemeID="0225"`,
annuaire PPF) : pas de routage infra-SIREN par SIRET d'établissement.
Pas de champ dédié par tiers : choix assumé de ne pas créer d'extrafields.

## Technique

### Validation Schematron officielle
**Partiel (par conception).** Le Schematron EN16931 officiel est du XSLT 2.0,
inexécutable avec l'extension XSL de PHP (XSLT 1.0). Le module embarque à la
place un validateur PHP couvrant les règles de calcul et de cohérence les plus
contrôlées (BR-CO-10..17, BR-27, BR-61, BR-16, BR-IC-02/11/12, BR-AE-02,
BR-*-08/10, BR-CO-25/26, BR-09/11). Pour une validation exhaustive ponctuelle,
utiliser un validateur externe (B2Brouter, FNFE, Mustang) — le XML s'exporte
via l'API ou `scripts/export_facturx_batch.php`. Attention aux validateurs en
ligne basés sur des artefacts périmés (~2021) : faux positifs connus sur le
BG-3 multiple et l'endpoint `0225` — voir « Validateurs en ligne : faux
positifs connus » dans le README.

### PDF/A-3 garanti
**Partiel.** Le module force ce qu'il contrôle (XMP, AFRelationship
`Alternative`, ICC sRGB, `/F 4`) et **vérifie** désormais `MAIN_PDF_FORCE_FONT`
(diagnostic + warning). Mais un modèle PDF custom (images CMJN, polices non
embarquées d'un module tiers) peut casser la conformité : la post-validation
optionnelle veraPDF (`LEMONFACTURX_VERAPDF_PATH`) est là pour le détecter.

### Mode strict ≠ suppression du PDF
Le hook `afterPDFCreation` intervient **après** la création du PDF classique
par Dolibarr. En mode strict, une erreur bloque le retour utilisateur mais le
PDF (sans Factur-X) reste sur le disque et pourrait être envoyé manuellement.
Supprimer le fichier serait destructif (c'est le document de l'utilisateur) ;
le bouton « Vérifier Factur-X » permet de contrôler avant envoi.

### Badge Factur-X dans la liste des factures
**Non traité.** L'ajout d'une colonne dans les listes Dolibarr nécessite des
hooks de liste intrusifs (`printFieldListTitle`/`Value`) coûteux (ouverture de
chaque PDF pour vérifier l'attachement). La vérification est disponible à la
demande : bouton fiche facture, API `/status`, export batch (rapport NO_XML).

### Order-X (devis / commandes)
**Non traité.** Order-X est une norme distincte (profils et XSD différents).
Hors périmètre facturation électronique 2026.
