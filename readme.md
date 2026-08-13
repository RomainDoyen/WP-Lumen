
<p align="center">
  <img src="assets/admin/icons/lumen-mark.svg" alt="Lumen" width="96" height="96" />
</p>

<h1 align="center">Lumen</h1>

<p align="center">
  <strong>Plugin WordPress</strong> — optimise les images (WebP / AVIF / JPEG), enrichit les médias<br />
  (images, SVG, PDF, vidéos) et génère un pack SEO (alts, JSON-LD, Gutenberg).
</p>

<p align="center">
  <img alt="Version" src="https://img.shields.io/badge/version-1.3.42-e879f9?style=flat-square" />
  <img alt="WordPress" src="https://img.shields.io/badge/WordPress-6.0%2B-21759b?style=flat-square" />
  <img alt="PHP" src="https://img.shields.io/badge/PHP-7.4%2B-777bb4?style=flat-square" />
  <img alt="License" src="https://img.shields.io/badge/license-UNLICENSED-0c0a09?style=flat-square" />
</p>

---

## Fonctionnalités


| Domaine          | Détail                                                                                |
| ---------------- | ------------------------------------------------------------------------------------- |
| **Optimisation** | Conversion WebP / AVIF (si supporté) / JPEG, tailles natives WP                       |
| **Original**     | Remplacement prioritaire WebP → AVIF → JPEG, ou sidecars ; réécriture des URLs contenu / Elementor |
| **SEO**          | Alts SEO / WCAG / court, titre, légende, description (images, SVG, PDF, vidéos)       |
| **Pack**         | JSON-LD `ImageObject` + snippet Gutenberg `<picture>` (images raster)                 |
| **Traitement**   | File d’attente en arrière-plan (filtres Images / PDF / SVG / Vidéos, pause / reprise) |
| **Restauration** | Sauvegarde de l’original avant remplacement + bouton fiche média                      |
| **Outils**       | Nettoyage, URLs cassées (diagnostic / réécriture), rapports (CSV, Excel, PDF)         |
| **Icônes**       | Kit PNG 16→512, ZIP, favicons injectés dans le `<head>`                               |
| **IA**           | Vision multi-fournisseur : Mistral, OpenAI, Anthropic, Gemini + compteur local        |
| **Apparence**    | Thème admin clair ou sombre                                                           |
| **Admin**        | Dashboard, navigation, modales succès/échec, UI responsive                            |


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
5. (Optionnel) lancer **Lumen → Traitement** pour les médias déjà présents

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

1. **Réglages** — formats, qualités (%), thème clair/sombre, auto-upload, clés IA
2. **Traitement** — médiathèque (Images / PDF / SVG / Vidéos, reprise, IA en option)
3. **Icônes** — déposer un logo → kit PNG + favicons site
4. **Médias** — fiche média → SEO, copier Gutenberg / JSON-LD, re-traiter / restaurer
5. **Outils** — nettoyage des variantes, état du traitement, avancer manuellement

---

## Checklist manuelle

- [ ] Upload d’un JPEG → variantes + meta SEO + statut `ok`
- [ ] SVG → SEO seul (pas d’optimisation)
- [ ] PDF / vidéo → SEO (+ IA si configurée)
- [ ] Traitement ~20 médias → progression, erreurs isolées (liens fiches)
- [ ] AVIF coché sans support serveur → notice, WebP/JPEG OK
- [ ] Mode « garder original » vs « remplacer »
- [ ] Remplacer original → sauvegarde + restauration fiche média
- [ ] Outils → nettoyage variantes (aperçu + lancement)
- [ ] Traitement / Outils → « Avancer maintenant » si bloqué
- [ ] Copier Gutenberg / JSON-LD depuis la fiche média
- [ ] Clé IA invalide ou rate limit → règles locales conservées
- [ ] Thème clair / sombre → contraste OK (boutons, steppers, activité)
- [ ] Kit d’icônes → ZIP + favicons dans le `<head>`

---

## Structure

```text
lumen-wp/
├── lumen.php                 # Bootstrap
├── assets/admin/             # CSS / JS / icônes
├── includes/
│   ├── Plugin.php
│   ├── Media_Types.php
│   ├── Optimizer.php
│   ├── Original_Backup.php
│   ├── Content_Url_Rewriter.php
│   ├── Reports.php
│   ├── Exporters.php
│   ├── Bulk_Queue.php
│   ├── Cleanup.php
│   ├── Seo.php
│   ├── Pack.php
│   ├── Vision_Ai.php
│   ├── Icon_Kit.php
│   ├── Hooks.php
│   └── Admin/                # Dashboard, Bulk, Tools, Icons, Settings, …
└── readme.md
```

---

## Changelog

### 1.3.42

- Outils → URLs cassées : diagnostic plus rapide + feedback / timeout AJAX

### 1.3.41

- Optimisation : gros JPEG/drone/panorama — plafond 4096 px, limites Imagick, moins de « time limit exceeded »

### 1.3.40

- Outils : diagnostiquer les URLs obsolètes + réécriture globale (contenu, Elementor, options)

### 1.3.39

- PDF : correction des accents (WinAnsiEncoding + conversion UTF-8)

### 1.3.38

- PDF rapports : mise en page structurée (bandeau Lumen, KPI, tableaux, pied de page)

### 1.3.37

- Rapports : export audit médiathèque + historique (CSV, Excel, PDF) depuis Outils
- Historique des traitements : capacité portée à 50 runs

### 1.3.36

- Remplacement d’original : réécriture des URLs en dur dans le contenu et Elementor (réglage)
- Restauration : réécriture inverse des URLs

### 1.3.35

- Thème clair : barre de progression + journal d’activité

### 1.3.34

- En-tête admin : « Lumen » + numéro de version

### 1.3.33

- Thème clair : contraste du bilan « OK » (Outils)

### 1.3.32

- Thème clair par défaut

### 1.3.31

- Thème clair : correction bordure des steppers qualité

### 1.3.30

- Thème clair : steppers qualité + pastilles formats (PNG/JPEG/SVG)

### 1.3.29

- Thème clair : cases à cocher lisibles + bouton désactivé contrasté

### 1.3.28

- Thème clair : contraste renforcé (boutons, pastilles, champs, pagination)

### 1.3.27

- Modale : « Ouvrir les réglages » uniquement si l’IA n’est pas configurée

### 1.3.26

- Thème admin clair / sombre (réglage Lumen → Apparence)

### 1.3.25

- SEO local : légende et description remplies depuis le nom de fichier (ex. SVG sans IA)

### 1.3.24

- Erreurs bulk : liste complète avec titre + lien vers la fiche média (Traitement, historique, Dashboard)

### 1.3.23

- Traitement : popup d’info si on coche l’IA Vision sans configuration

### 1.3.22

- Vocabulaire admin unifié autour des « médias » (plus seulement les images)

### 1.3.21

- SVG : SEO seul (pas d’optimisation)
- PDF / vidéos : SEO + IA Vision (aperçu WP, Imagick PDF, ffmpeg vidéo)
- Bulk : cases Images / PDF / SVG / Vidéos

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
  <sub>Lumen — médias, SEO et optimisation pour WordPress</sub>
</p>
