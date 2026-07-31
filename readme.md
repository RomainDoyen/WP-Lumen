<p align="center">
  <img src="assets/admin/icons/lumen-mark.svg" alt="Lumen" width="96" height="96" />
</p>

<h1 align="center">Lumen</h1>

<p align="center">
  <strong>Plugin WordPress</strong> — optimise la médiathèque (WebP / AVIF / JPEG)<br />
  et génère un pack SEO prêt à coller (alts, JSON-LD, Gutenberg).
</p>

<p align="center">
  <img alt="Version" src="https://img.shields.io/badge/version-1.2.12-e879f9?style=flat-square" />
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
| **Bulk** | Traitement de la médiathèque existante, progression & logs |
| **Icônes** | Kit PNG 16→512, ZIP, favicons injectés dans le `<head>` |
| **IA** | Suggestion optionnelle via Mistral Vision |
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
5. (Optionnel) lancer **Lumen → Bulk** pour les images déjà présentes

### Depuis les sources

```bash
# Copier le dossier dans les plugins
cp -r lumen-wp /chemin/vers/wp-content/plugins/lumen-wp
```

Le ZIP / le dossier doit s’appeler `lumen-wp` et contenir `lumen.php` à la racine du plugin.

---

## Utilisation rapide

1. **Réglages** — formats, qualités (%), remplacement original, auto-upload, clé Mistral
2. **Bulk** — traiter la médiathèque (forcer / Mistral en option)
3. **Icônes** — déposer un logo → kit PNG + favicons site
4. **Médias** — fiche image → pack SEO, copier Gutenberg / JSON-LD, re-traiter

---

## Checklist manuelle

- [ ] Upload d’un JPEG → variantes + meta SEO + statut `ok`
- [ ] Bulk ~20 images → progression, erreurs isolées
- [ ] AVIF coché sans support serveur → notice, WebP/JPEG OK
- [ ] Mode « garder original » vs « remplacer »
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
│   ├── Seo.php
│   ├── Pack.php
│   ├── Icon_Kit.php
│   ├── Hooks.php
│   └── Admin/                # Dashboard, Bulk, Icons, Settings, …
└── readme.md
```

---

## Changelog

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
