# Art Direction and Visual Exploration

Detailed rules for evidence priority, progressive clarification, understanding the design problem, art direction, visual exploration, concept feedback, and visual-weight control.

## Contents

- [Priority of Evidence](#1-priority-of-evidence)
- [Progressive Design Discovery](#2-progressive-design-discovery)
- [Understand the Actual Design Problem](#3-understand-the-actual-design-problem)
- [Establish Art Direction Before Layout](#4-establish-art-direction-before-layout)
- [Visual Exploration Before Implementation](#5-visual-exploration-before-implementation)
- [Concept Review and User Feedback](#6-concept-review-and-user-feedback)
- [Visual Weight Control](#7-visual-weight-control)

## 1. Priority of Evidence

When deciding what the page should look like, use this priority order:

1. Explicit user constraints and business goal.
2. User-approved visual concept, screenshot, image, Figma export, brand guide, or marked-up reference.
3. Existing live site, product assets, copy, and established brand language.
4. Other references explicitly requested by the user.
5. A newly established art direction created by this skill.

Never overwrite strong supplied evidence with generic design conventions.

Once the user approves a visual concept, it becomes the primary visual source of truth until the user explicitly changes direction.

---

## 2. Progressive Design Discovery

Do not front-load the user with a long design questionnaire.

When clarification is necessary:

1. Identify the single highest-impact unresolved design decision.
2. Prefer 2–4 concrete options over open-ended questions.
3. Recommend one option when there is a defensible professional preference.
4. Let the user choose, reject, or react.
5. Resolve later decisions only when they become relevant.

Prefer a design conversation over a requirement form.

Good examples:

- `A. 文案主導 / B. 圖文融合 / C. 場景主導`
- `A. 價格直接打 / B. 先打價值`
- `A. 保留桌機構圖 / B. 手機簡化 / C. 手機重新設計`

Do not ask questions whose answers can be safely inferred from the live site, existing copy, supplied references, or the user's previous choices.

The purpose is to reduce cognitive load, avoid unnecessary back-and-forth, and prevent solving low-impact details before the core visual direction is known.

---

## 3. Understand the Actual Design Problem

Determine:

- Page job.
- Audience.
- Primary action / conversion goal.
- Content hierarchy.
- Brand constraints.
- Device priorities.
- Existing information architecture that must be preserved.
- Whether the user wants exploration, a polished visual concept, production code, or faithful reproduction.

For redesigns, inspect the existing product or live site when available. Preserve business-critical structure unless there is a justified reason to change it.

Do not confuse "more modern" with "more generic".

---

## 4. Establish Art Direction Before Layout

Before writing final UI code, establish an internal design brief containing:

- **Visual concept** — aesthetic thesis in one sentence.
- **Emotional register** — precise, energetic, premium, playful, editorial, utilitarian, cinematic, technical, youthful, etc.
- **Reference grammar** — design traditions or visual systems being borrowed from.
- **Typography strategy** — display/body relationship, scale contrast, density, weight, rhythm, verified source, script coverage, and production medium.
- **Composition strategy** — symmetry/asymmetry, whitespace, overlap, edge alignment, section rhythm, dominant focal areas.
- **Surface/shape language** — borders, radius, shadows, panels, lines, frames, texture.
- **Color logic** — background, surface, text, accent, semantic roles, contrast behavior.
- **Image/illustration strategy** — photo, illustration, 3D, UI composite, scene, cutout, etc.
- **Interaction personality** — restrained, mechanical, expressive, editorial, playful, kinetic, etc.

The brief may remain internal unless the user needs to evaluate competing directions.

A text-only art direction is not always a sufficient visual specification.

### Typography before visual exploration

When display type, large metrics, multilingual headlines, or unusual lettering materially shape the composition, pass Typography Feasibility before generating the target. Honor an existing brand font first; otherwise search Google Fonts first when available and compare at most three candidates using the exact title, numerals, units, punctuation, and multilingual strings.

Verify source, license, weights or variable axes, script subsets, glyph coverage, loading path, and fallback. Compose the selected real font into the concept instead of asking image generation to imitate exact lettering. If no font matches, mark the lettering as a fixed graphic or controlled glyph asset before approval; do not postpone that production decision until coding.

---

## 5. Visual Exploration Before Implementation

For visually important marketing pages, homepages, landing pages, campaign pages, brand sites, and redesigns without a strong supplied reference, strongly prefer visual exploration before final implementation.

Recommended flow:

`Understand → Resolve Highest-Impact Unknowns → Art Direction → Typography Feasibility → Generate Visual Concept → Ask for User Reaction → Refine → Approve → Implement`

Use image generation when composition, imagery, background treatment, visual energy, spatial rhythm, visual weight, or brand expression are central to the task.

Do not treat image generation as decoration. Use it as a visual-specification step.

When exploration is useful, prefer a small number of meaningfully different directions rather than many near-duplicates.

Examples of distinct directions:

- Editorial / magazine.
- Industrial / control-system.
- Experimental tech brand.
- Social / creator culture.
- Lifestyle / commercial photography.
- Illustration-led brand system.

Do not generate multiple directions when the user has already approved one.

---

## 6. Concept Review and User Feedback

After generating a visual concept, do not immediately treat it as approved.

Obtain the user's reaction before moving to implementation when the environment permits a follow-up turn.

Interpret feedback in terms of dominant visual variables:

- Focal point.
- Visual weight.
- Hierarchy.
- Image-to-type balance.
- Density.
- Composition.
- Energy.
- Color dominance.
- Imagery intensity.
- Interaction cues.
- Brand character.

When the user says something like "右邊太重", translate that into the underlying visual problem rather than applying arbitrary size reductions.

Fix only 1–3 dominant issues per iteration.

Prefer small directional choices over broad open-ended questions when another decision is required.

---

## 7. Visual Weight Control

Explicitly evaluate visual weight.

Strong attention attractors include:

- Human faces.
- Large photography.
- Saturated color.
- High contrast.
- Large metrics.
- Arrows.
- Floating UI.
- Reaction icons.
- Bright badges.
- Dense clusters of small elements.
- Large display typography.

Do not allow several strong attractors to compete with the primary message unless intentional.

For conversion-focused hero sections, establish a deliberate attention sequence, for example:

`Primary Message → Supporting Visual Evidence → CTA → Secondary Details`

The supporting image should reinforce the focal hierarchy rather than compete with it.

---
