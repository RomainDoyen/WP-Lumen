<p align="center">
  <img src="assets/admin/icons/lumen-mark.svg" alt="Lumen" width="96" height="96" />
</p>

<h1 align="center">Lumen</h1>

<p align="center">
  <strong>Plugin WordPress</strong> — optimise la médiathèque (WebP / AVIF / JPEG)<br />
  et génère un pack SEO prêt à coller (alts, JSON-LD, Gutenberg).
</p>

<p align="center">
  <img alt="Version" src="https://img.shields.io/badge/version-1.3.14-e879f9?style=flat-square" />
  <img alt="WordPress" src="https://img.shields.io/badge/WordPress-6.0%2B-21759b?style=flat-square" />
  <img alt="PHP" src="https://img.shields.io/badge/PHP-7.4%2B-777bb4?style=flat-square" />
  <img alt="License" src="https://img.shields.io/badge/license-UNLICENSED-0c0a09?style=flat-square" />
</p>

---

## Fonctionnalités

| Domaine | Détail |
| --- | --- |
| **Optimisation** | Conversion WebP / AVIF (si supporté) / JPEG, tailles natives WP |
| **Original** | Remplacement prioritaire WebP → AVIF → JPEG, ou sidecars |
| **SEO** | Alts SEO / WCAG / court, titre, légende, description |
| **Pack** | JSON-LD `ImageObject` + snippet Gutenberg `<picture>` |
| **Traitement** | File d’attente en arrière-plan (pause / reprise, continue sans onglet) |
| **Restauration** | Sauvegarde de l’original avant remplacement + bouton fiche média |
| **Outils** | Nettoyage des variantes, état du traitement, avancer manuellement |
| **Icônes** | Kit PNG 16→512, ZIP, favicons injectés dans le `<head>` |
| **IA** | Vision multi-fournisseur : Mistral, OpenAI, Anthropic, Gemini + compteur local |
| **Admin** | Dashboard, navigation, modales succès/échec, UI responsive |

### Tailles générées

`full` · `large` (1024) · `medium_large` (768) · `medium` (300) · `thumbnail` (150, crop)

### Défauts utiles

- Formats : **WebP + JPEG**
- Remplacer l’original : **activé**
- Auto à l’upload : **désactivé** (bulk recommandé pour l’existant)
- Qualités : WebP **82 %** · JPEG **85 %** · AVIF **65 %**

---

## Prérequis

- WordPress **6.0+**
- PHP **7.4+**
- **Imagick** (recommandé) ou **GD**
- Support AVIF selon l’hébergeur

---

## Installation

### Depuis une release GitHub

1. Télécharger `lumen-wp.zip` depuis la [release](https://github.com/RomainDoyen/WP-Lumen/releases)
2. WordPress → **Extensions → Ajouter → Téléverser**
3. Activer **Lumen**
4. Configurer **Lumen → Réglages**
5. (Optionnel) lancer **Lumen → Traitement** pour les images déjà présentes

### Depuis les sources

```bash
# Copier le dossier dans les plugins
cp -r lumen-wp /chemin/vers/wp-content/plugins/lumen-wp
```

Le ZIP / le dossier doit s’appeler `lumen-wp` et contenir `lumen.php` à la racine du plugin.

### Build du ZIP (sans `.git`)

```bash
# Depuis la racine du repo (fichiers commités uniquement)
./bin/build-zip.sh
# → dist/lumen-wp.zip
```

À chaque tag `v*` poussé sur GitHub, le workflow **Release ZIP** publie automatiquement `lumen-wp.zip` sur la release.

---

## Utilisation rapide

1. **Réglages** — formats, qualités (%), remplacement original, auto-upload, clé Mistral
2. **Traitement** — traiter la médiathèque (reprise des déjà OK / IA en option)
3. **Icônes** — déposer un logo → kit PNG + favicons site
4. **Médias** — fiche image → pack SEO, copier Gutenberg / JSON-LD, re-traiter / restaurer
5. **Outils** — nettoyage des variantes, état du traitement, avancer manuellement

---

## Checklist manuelle

- [ ] Upload d’un JPEG → variantes + meta SEO + statut `ok`
- [ ] Traitement ~20 images → progression, erreurs isolées
- [ ] AVIF coché sans support serveur → notice, WebP/JPEG OK
- [ ] Mode « garder original » vs « remplacer »
- [ ] Remplacer original → sauvegarde + restauration fiche média
- [ ] Outils → nettoyage variantes (aperçu + lancement)
- [ ] Traitement / Outils → « Avancer maintenant » si bloqué
- [ ] Copier Gutenberg / JSON-LD depuis la fiche média
- [ ] Clé Mistral invalide ou rate limit → règles locales conservées
- [ ] Kit d’icônes → ZIP + favicons dans le `<head>`

---

## Structure

```text
lumen-wp/
├── lumen.php                 # Bootstrap
├── assets/admin/             # CSS / JS / icônes
├── includes/
│   ├── Plugin.php
│   ├── Optimizer.php
│   ├── Original_Backup.php
│   ├── Cleanup.php
│   ├── Seo.php
│   ├── Pack.php
│   ├── Icon_Kit.php
│   ├── Hooks.php
│   └── Admin/                # Dashboard, Bulk, Tools, Icons, Settings, …
└── readme.md
```

---

## Changelog

### 1.3.14

- Modèles IA : catalogue Lumen + actualisation API Vision (select whitelisté, cache 12 h)

### 1.3.11

- Historique léger des traitements (10 runs : dates, totaux, options, auteur, dernières erreurs)

### 1.3.9

- Aperçu nettoyage en cartes (images / variantes / sauvegardes)
- Build ZIP sans `.git` (`bin/build-zip.sh` + release GitHub sur tags `v*`)

### 1.3.8

- Vocabulaire admin simplifié (Traitement, Avancer maintenant, Activité…)

### 1.3.7

- Sauvegarde permanente de l’original avant remplacement
- Bouton « Restaurer l’original » sur la fiche média
- Page **Outils** : nettoyage, état du traitement, avance manuelle

### 1.3.0

- Traitement en arrière-plan (démarrage / pause / reprise / arrêt)
- Multi-IA Vision : Mistral, OpenAI, Anthropic, Google Gemini
- Compteur d’usage IA local + budget mensuel optionnel
- Préfixe SEO « Titre du site — … » sur les champs générés

### 1.2.12

- Icône menu admin : silhouette X seule (lisible sur fond WP)

### 1.2.11

- Responsive admin (mobile, tablette, desktop, grand écran)

### 1.2.10

- Séparateur titre / contenu sur les panneaux (Bulk, Réglages)

### 1.2.9

- Alignement pagination (précédent / suivant / numéros)

### 1.2.8

- Pagination compacte « Dernières images traitées » (ellipses, grands volumes)

### 1.2.7

- Indications qualité WebP / JPEG / AVIF en pourcentage

### 1.2.6

- Modale feedback globale succès / échec

### 1.2.0

- Dashboard d’accueil, navigation interne, moins de flash au chargement

### 1.1.0

- Kit d’icônes (16→512 PNG + ZIP) et favicons site

### 1.0.1

- Design admin aligné sur l’app Electron (thème sombre magenta)

### 1.0.0

- Première version : optimizer, SEO, pack, bulk, réglages

---

## Licence

UNLICENSED — usage privé / projet Lumen.

---

<p align="center">
  <img src="assets/admin/icons/lumen-mark.svg" alt="" width="28" height="28" />
  <br />
  <sub>Studio image local pour WordPress</sub>
</p>
