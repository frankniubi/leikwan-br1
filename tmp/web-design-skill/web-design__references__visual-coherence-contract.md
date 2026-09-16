# Visual Coherence Contract

Detailed rules for typography, visual grammar, token mapping, asset style, composition, focal hierarchy, section roles, image/text relationships, motion, responsive continuity, brand drift, and coherence review.

## Contents

- [Build a Visual System](#8-build-a-visual-system)
- [Typography System](#81-typography-system)
- [Visual Grammar / Style Consistency](#82-visual-grammar--style-consistency)
- [Token Map](#83-token-map)
- [Asset Style Contract](#84-asset-style-contract)
- [Composition Consistency](#85-composition-consistency)
- [Focal Hierarchy Contract](#86-focal-hierarchy-contract)
- [Section Role Model](#87-section-role-model)
- [Image / Text Relationship](#88-image--text-relationship)
- [Motion and Interaction Language](#89-motion-and-interaction-language)
- [Responsive Design Continuity](#810-responsive-design-continuity)
- [Brand Drift Detection](#811-brand-drift-detection)
- [Visual Coherence Review](#812-visual-coherence-review)

## 8. Build a Visual System

Create a small coherent system rather than styling each element independently.

At minimum establish:

- Type scale and hierarchy.
- Spacing rhythm.
- Container and alignment rules.
- Color roles.
- Border/radius/shadow rules.
- Reusable component grammar.
- Image treatment rules.
- Interaction/state rules.
- Responsive behavior.

Use components because repeated semantic patterns exist, not because every piece of content deserves a card.

A page can use cards, gradients, pills, shadows, or glass surfaces, but only when the product and art direction justify them.

### 8.1 Typography System

Typography is a system, not a per-section styling choice.

Define a deliberate typographic grammar before implementation:

- Display / hero.
- Heading levels.
- Body.
- Label / eyebrow / metadata.
- Numeric / price / metric treatment.
- Chinese / Latin font pairing when multilingual content exists.
- Allowed weights.
- Type scale.
- Line-height.
- Letter-spacing.
- Maximum headline line count when composition depends on wrapping.
- Chinese punctuation and line-break behavior when relevant.
- Mobile typography overrides.
- Tabular or mono numerals when metrics require alignment.
- Local optical exceptions for specific glyphs or labels when verified by rendered comparison.

Do not change typographic personality section by section merely to create novelty.

Do not use global tracking to compensate for one narrow or wide glyph. Scope the correction to the affected glyph or label, record it as an explicit exception, and recheck wrapping and responsive evidence.

When a reference uses typography as a major visual mass, preserve its scale, line breaks, contrast, and density as part of composition rather than treating type as content placed afterward.

#### 8.1.1 Typography Feasibility and Google Fonts

Use this source order:

1. Required existing or licensed brand font.
2. Google Fonts as the first public searchable source.
3. Other verified licensed web or local fonts.
4. Fixed clean title graphic for immutable display lettering.
5. Controlled vector glyphs or a QA-checked raster atlas when no font can reproduce the target.

For Google Fonts candidates, verify the official family metadata or rendered CSS response rather than relying on the specimen name alone. Record family, source, license, category, available styles/weights, variable axes, script subsets, and loading method. A Latin display family does not provide a CJK solution unless its glyph coverage is confirmed.

Shortlist no more than three candidates. Render the exact hero title, representative body text, `A–Z`, `a–z`, `0–9`, punctuation, metrics/units, and required multilingual text. Compare candidates against the reference for glyph skeleton, width, stroke weight, terminals, counters, cap height, spacing, line breaks, and optical density.

Lock explicit font roles and fallbacks in the Token Map, for example display Latin, display CJK, body, label, mono/data, and metric. Include source evidence and the chosen loading path. Use `font-display` deliberately and avoid requesting unused families or weights.

For image-led visual exploration, generate the scene without production text and composite verified live typography into the target. Treat image-generated letters as reference-only. If fixed lettering must remain an image, preserve semantic meaning with an accessible live-text equivalent and prevent duplicate accessible names.

### 8.2 Visual Grammar / Style Consistency

Define the reusable visual language of the page:

- Radius grammar.
- Border and stroke grammar.
- Shadow grammar.
- Surface hierarchy.
- Button shape family.
- Card family.
- Icon grammar.
- Illustration grammar.
- Photography treatment.
- Highlight / emphasis style.
- Divider / line language.
- Accent-color usage.
- Texture / grain / glow rules when used.

Individual sections may vary in composition, but they must still look like the same brand and the same design system.

Treat repeated dividers, borders, frames, and content clearances as semantic motifs. Comparable sections should share color, weight, spacing, and construction tokens unless the approved target gives one section a different role.

Do not let one page drift from editorial photography to glassmorphism to dashboard cards to hand-drawn illustration without an explicit unifying concept.

### 8.3 Token Map

The four decomposition maps remain **Block / Layer / Text / Geometry**.

In addition, maintain a cross-cutting **Token Map** that translates visual decisions into reusable implementation variables. It is not a fifth decomposition map; it is the coherence contract shared by all four maps.

At minimum define when relevant:

- Color tokens.
- Typography tokens.
- Spacing rhythm.
- Container widths and gutters.
- Radius tokens.
- Border / stroke tokens.
- Shadow tokens.
- Z-index layers.
- Motion duration and easing.
- Breakpoint logic.
- Image treatment variables.

Prefer semantic tokens such as `--surface-primary`, `--accent-live`, `--text-muted`, `--radius-card`, or equivalent framework tokens over unrelated one-off values.

A reference may contain small visual variation, but implementation should resolve that variation into the smallest coherent token system that preserves the design.

### 8.4 Asset Style Contract

When multiple assets are generated, edited, extracted, or sourced independently, define an **Asset Style Contract** before producing replacements.

The contract may specify:

- Asset medium: photo / illustration / 3D / UI composite / icon / texture.
- Camera angle and perspective.
- Lighting direction and softness.
- Color temperature.
- Saturation and contrast.
- Depth of field.
- Subject scale.
- Crop behavior.
- Edge treatment / cutout quality.
- Background transparency or background behavior.
- Visual age / cultural register / brand mood.
- Rendering detail level.

Every locally regenerated asset must inherit this contract unless the approved target clearly requires a deliberate exception.

Do not allow separately generated assets to become visually incompatible merely because each looks good in isolation.

### 8.5 Composition Consistency

Maintain a page-level composition grammar across sections. Track:

- Primary alignment axes.
- Asymmetry logic.
- Whitespace rhythm.
- Section density pattern.
- Full-bleed versus contained behavior.
- Overlap rules.
- Edge-entry / edge-exit behavior.
- Repeated spatial motifs.

A bold hero followed by mechanically centered equal-card sections usually indicates composition drift.

Different sections may have different jobs and densities, but their spatial logic should feel intentionally related.

### 8.6 Focal Hierarchy Contract

For every important viewport, explicitly identify:

1. Primary focal point.
2. Supporting focal point.
3. Primary CTA.
4. Tertiary information cluster.

Treat visual attention as a limited budget. Human faces, saturated color, arrows, large numbers, motion, floating UI, large icons, and oversized display type all consume attention.

If several high-attention elements compete simultaneously, reduce, relocate, desaturate, simplify, or sequence them unless deliberate visual competition is part of the art direction.

### 8.7 Section Role Model

For important marketing or narrative pages, every major section should have a clear role in the page journey, for example:

- Hero — attract + explain.
- Proof — reduce doubt.
- Services / product — clarify the offer.
- Process — explain how it works.
- Pricing — reduce purchase friction.
- FAQ — resolve objections.
- CTA — convert.

A section should not become visually dominant merely because it can. Its visual intensity should match its narrative role.

Do not make every section shout at equal volume.

### 8.8 Image / Text Relationship

For each visually important block, identify its relationship mode:

- **Text-led** — typography is primary; imagery supports it.
- **Image-led** — imagery is primary; text explains or anchors it.
- **Balanced** — text and image share comparable visual weight.
- **Integrated** — text and imagery overlap or operate as one composition.

Do not default every section to a generic 50/50 text-image split.

Preserve the chosen relationship during responsive translation even when exact geometry changes.

### 8.9 Motion and Interaction Language

Define one interaction personality for the product rather than inventing motion component by component.

When relevant define:

- Hover response.
- Press / active feedback.
- Focus behavior.
- Scroll reveal.
- Parallax or depth response.
- Floating / ambient motion.
- Counter / metric animation.
- Marquee behavior.
- Pointer response.
- Transition duration and easing.

Maintain a **motion budget**. Not every element should animate. Motion must reinforce hierarchy, state, or product character rather than compensate for weak static composition.

### 8.10 Responsive Design Continuity

Responsive design may change geometry aggressively, but preserve the relationships that define the brand and composition.

Identify which relationships must survive across viewports, such as:

- Primary focal point remains primary.
- Supporting image remains supporting rather than becoming dominant.
- Accent logic remains recognizable.
- Typography personality remains consistent.
- Card / surface grammar remains consistent.
- Interaction character remains consistent.
- Narrative order remains coherent.

Responsive fidelity is **continuity**, not literal shrinking.

### 8.11 Brand Drift Detection

Every newly generated section, component, or asset should be checked against the established visual system.

Look for drift in:

- Font family or typography personality.
- Type scale and weight usage.
- Icon style.
- Photography / illustration mood.
- Color temperature and saturation.
- Radius and border language.
- Shadow / depth language.
- Spacing rhythm.
- Motion personality.
- Asset rendering style.

If a new element is individually attractive but does not look like it belongs to the same product, treat that as a defect.

### 8.12 Visual Coherence Review

During final visual QA, assess coherence independently from reference fidelity.

Evaluate:

- Typography coherence.
- Asset coherence.
- Composition coherence.
- Color coherence.
- Surface / shape coherence.
- Interaction / motion coherence.
- Responsive coherence.

An internal qualitative score such as `strong / acceptable / weak` is sufficient; numeric scoring is optional.

A page can match a screenshot locally yet still fail globally if its independently implemented pieces no longer feel like one visual system.

---
