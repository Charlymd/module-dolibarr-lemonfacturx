# Politique de sécurité — LemonFacturX

Ce document décrit le modèle de menace du module LemonFacturX, les protections en place, les limitations assumées, et le processus de signalement responsable d'une faille.

## Signaler une vulnérabilité

Merci de **ne pas** ouvrir d'issue publique pour une faille de sécurité. Écrivez à :

**hello@hellolemon.fr**

Précisez :

- Version du module concernée (ou commit SHA)
- Description de la vulnérabilité et impact estimé
- Étapes de reproduction minimales
- Éventuelle preuve de concept

Nous nous engageons à :

- Accuser réception sous 72 heures
- Vous tenir informé de l'avancement de l'analyse
- Mentionner votre contribution (si vous le souhaitez) une fois le correctif publié
- Appliquer un délai de divulgation coordonnée de 90 jours maximum avant publication publique du détail

Merci d'éviter toute action qui pourrait dégrader un service en production, accéder à des données tierces, ou exploiter une faille au-delà du strict nécessaire pour la démontrer.

## Modèle de menace

LemonFacturX est un module Dolibarr qui convertit automatiquement les PDF factures clients au format Factur-X EN16931 (PDF/A-3 avec XML CII embarqué). Il s'exécute **à l'intérieur** d'une instance Dolibarr authentifiée, branché sur le hook `afterPDFCreation`. Le modèle de menace est celui d'une application métier en intranet.

### Rôles

| Rôle | Accès | Confiance |
|---|---|---|
| Administrateur Dolibarr | Configuration du module, y compris `LEMONFACTURX_PHP_CLI_PATH` | **Confiance forte**. Un admin compromis implique de toute façon une compromission totale de Dolibarr. |
| Utilisateur Dolibarr avec droit de générer un PDF facture | Déclenche l'injection Factur-X via le hook standard | Confiance interne. |
| Utilisateur anonyme (hors Dolibarr) | Aucun accès | Non concerné : le module n'expose aucun endpoint public. |

### Surface exposée

- **Hook `afterPDFCreation`** : exécuté dans le contexte d'une génération PDF facture (utilisateur authentifié)
- **Hooks `invoicecard`** (boutons « Vérifier / Régénérer Factur-X ») : réservés aux utilisateurs authentifiés avec droits factures (`lire` / `creer`), protégés par token CSRF (`currentToken()`)
- **Page de configuration admin** : `admin/setup.php`, réservée aux admins via `accessforbidden()` + protection CSRF sur le POST de mise à jour
- **API REST** : `class/api_lemonfacturx.class.php`, exposée uniquement si le module API REST Dolibarr est activé ; clé API requise, droits `facture->lire` + `_checkAccessToResource()` vérifiés
- **Scripts CLI** : `scripts/inject_facturx.php`, `scripts/export_facturx_batch.php`, `tests/*.php`, `demo/*.php` — tous protégés contre l'accès HTTP direct par `PHP_SAPI === 'cli'`, et les dossiers `scripts/`, `tests/`, `demo/` portent un `.htaccess` `Require all denied` en défense en profondeur
- **Appel HTTP sortant unique** : vérification de la dernière release GitHub via `api.github.com`, au chargement de la page de configuration admin, avec cache 24h (succès **et** échecs) et timeout 5s (aucune donnée locale envoyée, uniquement une requête `GET` anonyme)
- **Binaire externe optionnel** : veraPDF (`LEMONFACTURX_VERAPDF_PATH`, constante admin), invoqué via `escapeshellarg()` + `is_executable()`
- **Aucun endpoint web exposé publiquement**

### Ce qui est **hors** modèle de menace

- Un administrateur Dolibarr malveillant. Un admin peut déjà tout faire dans Dolibarr (modules custom, `/admin/tools/*`, accès base, `/admin/const.php`). Aucun mécanisme ne protège contre un admin hostile (et ne le peut pas dans l'architecture Dolibarr).
- La sécurité des bibliothèques tierces vendored (`atgp/factur-x`, `setasign/fpdi`, `setasign/fpdf`, `smalot/pdfparser`, `symfony/polyfill-mbstring`). Leur sécurité dépend des mainteneurs amont et des versions embarquées.

## Protections en place

### Exécution d'un subprocess PHP (`exec`)

Le module lance un subprocess CLI pour éviter un conflit de classes entre FPDF (utilisé par `atgp/factur-x`) et TCPDF (utilisé par Dolibarr). Le binaire est configurable via la constante `LEMONFACTURX_PHP_CLI_PATH`.

Protections :

- `escapeshellarg()` est appliqué sur **tous** les tokens de la commande (binaire PHP, script, PDF, fichier XML temporaire). Une valeur piégée dans la constante est quotée, et le shell cherche un binaire avec ce nom littéral qui n'existe pas → `command not found`. Pas de chaînage de commandes possible.
- Validation par regex `^[A-Za-z0-9/._:() \\-]+$` sur `LEMONFACTURX_PHP_CLI_PATH` avant l'appel (`: \ ( )` et espace autorisés pour les chemins Windows). Toute valeur contenant des caractères exotiques (`;`, `&`, `$`, guillemets, etc.) est refusée avec un message d'erreur clair.
- Si le chemin est absolu, `is_executable()` vérifie qu'un exécutable existe effectivement.
- `function_exists('exec')` est testé en amont (certains hébergeurs désactivent `exec`).
- Le script CLI `inject_facturx.php` refuse tout appel via HTTP : `if (php_sapi_name() !== 'cli') { http_response_code(403); die(...); }`

### Manipulation de fichiers

- Le PDF source provient du flux interne Dolibarr (hook `afterPDFCreation`), pas d'un upload direct.
- Les fichiers XML temporaires sont créés via `tempnam(DOL_DATA_ROOT.'/facturx/temp', 'facturx_')` (toujours dans l'`open_basedir` Dolibarr, permissions 0600, nom imprévisible) puis supprimés en bloc `finally` après l'exec.
- L'écriture du PDF Factur-X par le subprocess est **atomique** : écriture dans un fichier `.facturx.tmp` puis `rename()` — un crash, un disque plein ou un kill ne peut pas laisser un PDF tronqué.
- Aucun path fourni par l'utilisateur n'est utilisé en lecture/écriture.

### Génération XML

- Le XML CII est **construit** programmatiquement à partir des objets Dolibarr (`$invoice`, `$mysoc`, `$thirdparty`). Aucune donnée utilisateur n'est injectée en brut : les valeurs sont échappées avec `htmlspecialchars(..., ENT_XML1 | ENT_QUOTES, 'UTF-8')` via les helpers du module.
- Aucun parsing de XML externe n'est effectué dans le chemin critique.

### Validation XML interne avant injection

Depuis la v1.1.0, avant d'écrire le XML sur disque et d'invoquer le subprocess d'injection, le module valide systématiquement :

1. **XML well-formed** via `DOMDocument::loadXML()`. Un XML cassé (cas peu probable puisque la génération est programmatique, mais défense en profondeur) est rejeté avant qu'il atteigne la lib tierce.
2. **Conformité XSD EN16931** via `DOMDocument::schemaValidate()` contre le schéma embarqué dans `vendor/atgp/factur-x/xsd/factur-x/en16931/Factur-X_1.08_EN16931.xsd`. Les erreurs sont loggées dans `dol_syslog` pour diagnostic.

En mode `LEMONFACTURX_STRICT_MODE=1`, ces validations échouées bloquent la génération avec une erreur visible. En mode best-effort (défaut), un warning est affiché et le PDF classique reste disponible (fail-open). Cette option permet de choisir explicitement la politique fail-open vs fail-closed selon le besoin de conformité.

### CSRF de la page admin

Le POST de mise à jour des constantes dans `admin/setup.php` est protégé par vérification du token CSRF standard Dolibarr (`currentToken()`), en plus du check `$user->admin`. Le token est regénéré par Dolibarr à chaque rendu et la comparaison utilise `currentToken()` (valeur de la soumission en cours), pas `newToken()` qui génère le token de la **prochaine** soumission.

### Patch FPDF pour PDF/A-3

La bibliothèque `setasign/fpdf` vendored reçoit un patch pour ajouter le flag `/F 4` aux annotations (conformité PDF/A-3). Ce patch est appliqué au build du vendor et n'introduit pas de vecteur d'attaque.

### Constantes Dolibarr

Toutes les constantes du module sont stockées en clair dans `llx_const` (convention Dolibarr). Aucune n'est un secret.

| Constante | Nature |
|---|---|
| `LEMONFACTURX_ENABLED` | Flag d'activation |
| `LEMONFACTURX_BANK_ACCOUNT` | ID du compte bancaire configuré |
| `LEMONFACTURX_PAYMENT_MEANS` | Code moyen de paiement UNTDID 4461 |
| `LEMONFACTURX_ENDPOINT_SCHEME` | Schéma d'adressage BT-34/BT-49 (0225/0002/0009) |
| `LEMONFACTURX_LEGAL_ID_SCHEME` | Identifiant légal BT-30/BT-47 (siret0009/siren0002/siret0002) |
| `LEMONFACTURX_VAT_DUE_DATE_TYPE` | BT-8 exigibilité TVA (vide/5/72) |
| `LEMONFACTURX_BT23_PROCESS` | BT-23 cadre de facturation |
| `LEMONFACTURX_STRICT_MODE` | Politique erreur (best-effort / strict) |
| `LEMONFACTURX_BR_CHECK` | Contrôle interne des règles métier EN16931 |
| `LEMONFACTURX_PHP_CLI_PATH` | Chemin du binaire PHP (validé par regex) |
| `LEMONFACTURX_VERAPDF_PATH` | Chemin du binaire veraPDF (optionnel, `is_executable()` vérifié) |
| `LEMONFACTURX_NOTE_PMD/PMT/AAB` | Mentions légales BR-FR-05 |
| `LEMONFACTURX_UPDATE_CHECK_CACHE` | JSON cache de la dernière version GitHub (TTL 24h, succès et échecs) |

## Dépendances vendored

Le dossier `vendor/` embarque les bibliothèques suivantes (pas de Composer requis au déploiement) :

| Bibliothèque | Rôle |
|---|---|
| `atgp/factur-x` v3.3.0 | Génération PDF Factur-X |
| `setasign/fpdi` | Lecture/écriture PDF |
| `setasign/fpdf` | Moteur PDF (utilisé par atgp, patch `/F 4` appliqué) |
| `smalot/pdfparser` | Parsing PDF |
| `symfony/polyfill-mbstring` | Compatibilité mbstring |

Ces bibliothèques ne sont pas maintenues par Lemon. Leur sécurité dépend des mainteneurs amont. Tout signalement de CVE amont nous sera transmis par les canaux habituels (GitHub, composer audit, etc.) et une nouvelle version vendored sera publiée au besoin.

## Historique des avis

_Aucune vulnérabilité corrigée n'a été publiée à ce jour._

---

Pour toute question sur la sécurité de ce module : hello@hellolemon.fr
