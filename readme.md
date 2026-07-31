=== Lumen ===
Contributors: lumen
Tags: images, webp, avif, seo, media, optimization
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.2.0
License: UNLICENSED

Optimise les images de la médiathèque (WebP / AVIF / JPEG) et génère un pack SEO (alts, JSON-LD, Gutenberg).

== Description ==

Lumen (plugin WordPress) :

* Compression et conversion (WebP, AVIF si supporté, JPEG fallback)
* Tailles natives WordPress : full, large (1024), medium_large (768), medium (300), thumbnail (150 crop)
* SEO images : alts SEO / WCAG / court, titre, légende, description
* Suggestion IA optionnelle via Mistral Vision
* JSON-LD ImageObject + snippet Gutenberg `<picture>` à copier
* Traitement automatique à l'upload + bulk pour l'existant

Prérequis serveur : extension Imagick (recommandé) ou GD. Le support AVIF dépend de l'hébergeur.

== Installation ==

1. Copier le dossier `lumen-wp` dans `wp-content/plugins/`
2. Activer le plugin « Lumen » dans Extensions
3. Configurer les formats et options sous le menu **Lumen → Réglages**
4. (Optionnel) Lancer **Lumen → Bulk** pour les images existantes

== Checklist manuelle ==

1. Upload d'un JPEG → variantes générées + meta SEO + statut `ok`
2. Bulk sur ~20 images → progression, erreurs isolées
3. AVIF coché sans support serveur → notice, WebP/JPEG OK
4. Mode « garder original » vs « remplacer »
5. Copier Gutenberg / JSON-LD depuis la fiche média
6. Clé Mistral invalide ou rate limit → règles locales conservées

== Changelog ==

= 1.2.0 =
* Dashboard d’accueil, navigation interne, moins de flash au chargement entre pages

= 1.1.0 =
* Kit d’icônes (16→512 PNG + ZIP) et favicons site injectés dans le `<head>`

= 1.0.1 =
* Design admin aligné sur l’app Electron (logo, thème sombre magenta, typo Outfit / Space Grotesk)

= 1.0.0 =
* Première version plugin : optimizer, SEO, pack, bulk, réglages
