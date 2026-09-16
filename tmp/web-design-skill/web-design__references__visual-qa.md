# Visual QA Rubric

Use this reference after rendering a design or when comparing implementation to a supplied target.

## Contents

- [Core principle](#core-principle)
- [Mismatch taxonomy](#mismatch-taxonomy)
- [Iteration protocol](#iteration-protocol)
- [Final closure protocol](#final-closure-protocol)
- [Completion questions](#completion-questions)

## Core principle

Review perceptually, from largest impact to smallest. Do not spend an iteration correcting icon offsets while the hero proportions, typography, or page rhythm are wrong.

Each iteration should fix at most **1–3 dominant mismatches**.

## Mismatch taxonomy

### Art-direction mismatch

Symptoms:
- The interface is competent but belongs to the wrong visual world.
- Brand personality is missing.
- The design relies on generic SaaS/UI conventions instead of a product-specific thesis.

Typical action:
- Revisit the art direction, not local CSS.

### Composition mismatch

Check:
- First-viewport balance.
- Section heights.
- Column proportions.
- Alignment anchors.
- Focal points.
- Negative-space distribution.
- Image/text relationship.

Composition errors usually outrank all decorative errors.

### Typography mismatch

Check:
- Font category/personality.
- Display/body relationship.
- Size ratios.
- Weight.
- Width.
- Tracking.
- Line-height.
- Line wrapping.
- Measure.
- Optical alignment.
- Per-glyph width and spacing in repeated numerals or identifiers.

A different line break in the hero can shift the entire composition.

If one glyph is unusually narrow or wide, test a local optical correction on that glyph or label. Do not change global tracking unless the entire text run is wrong.

### Hierarchy mismatch

Ask:
- Can the intended reading order be understood in 2–3 seconds?
- Are secondary elements visually quieter?
- Is the primary CTA actually dominant where it should be?
- Are boxes compensating for weak hierarchy?

### Spacing/rhythm mismatch

Check:
- Local grouping.
- Section separation.
- Alignment consistency.
- Uneven accidental gaps.
- Excessively uniform spacing.
- Optical spacing around large type and images.
- Content-to-frame and table-to-note clearances.
- Consistency of repeated divider/border spacing across comparable sections.

### Color/contrast mismatch

Check:
- Canvas and surface relationships.
- Text contrast.
- Accent saturation and usage frequency.
- Dark/light balance.
- Semantic state consistency.

### Surface/shape mismatch

Check:
- Radius consistency by semantic role.
- Border weight.
- Shadow logic.
- Panel treatment.
- Divider style.

### Asset/imagery mismatch

Check:
- Crop.
- Scale.
- Subject placement.
- Image tone.
- Illustration style.
- Icon family.
- Whether assets actually reinforce the art direction.
- Transparent-edge contamination, residual pixels, and unsafe crop margins.

### Assembly/geometry integrity mismatch

Check:

- Expected versus actual instance count for corners, frames, HUD parts, dividers, and other repeated motifs.
- Master/derived transforms and role-specific IDs.
- Continuous versus disconnected line topology.
- Shared anchors, baselines, and coordinate systems.
- Content containment and measurable safe areas.
- Decoration collisions with header, footer, controls, and content.
- Desktop/mobile assembly differences.

An element can be individually correct and still be assembled incorrectly. Compare relationships, not only isolated assets.

### Brand mismatch

Check:
- Recognizable brand cues.
- Existing identity elements.
- Tone of typography and imagery.
- Color usage.
- Product-specific conventions.

### Responsive mismatch

Check each target viewport independently:
- Reading order.
- Hero height.
- Navigation.
- Type scale.
- Overflow.
- Tap targets.
- Image crop.
- Density.
- Content that becomes too long when stacked.

### Interaction/state mismatch

Check:
- Hover/focus/active/selected/disabled states.
- Motion behavior.
- Expansion/collapse.
- Loading/empty/error states when relevant.
- Whether interaction matches the visual grammar.

### Reference fidelity mismatch

When a target exists, compare:
- Large geometry first.
- Typography second.
- Spacing third.
- Surface/color fourth.
- Detail last.

Do not chase pixel-level detail while macro ratios remain incorrect.

### Generic-AI-pattern contamination

Look for clusters of these signals:
- Cardification.
- Excessive rounded corners.
- Purple/blue gradients without rationale.
- Uniform feature grids.
- Generic icon-heading-copy modules.
- Decorative glow/mesh/blob effects.
- Identical section rhythm.
- Centered-everything marketing layout.
- Weak typography.
- Mobile = stacked desktop cards.

One pattern may be correct. Several together without a strong product reason indicate design drift.

## Iteration protocol

1. View the whole page before zooming into details.
2. Confirm reference coverage and Geometry Integrity for repeated or connected motifs.
3. Name the single biggest perceptual problem.
4. Name at most two additional high-impact problems.
5. Change only what is necessary to address those problems.
6. Re-render.
7. Re-rank from scratch rather than blindly continuing the old checklist.

Do not maintain a long accumulating list of micro-fixes across iterations; priorities change after major corrections.

## Final closure protocol

After the latest visual state is available, discard any assumption that earlier comparison evidence is still current. Render every required viewport again and perform one complete target-to-render review, even when the first comparison found no material mismatch and no repair loop occurred.

Require explicit evidence for:

- **Typography:** font, weight, size, tracking, optical exceptions, line-height, wrapping, baseline, clipping, and overflow.
- **Borders:** stroke color/opacity/weight, radius, corner joins, topology, frame inset, divider role, missing lines, and accidental double lines.
- **Spacing:** local grouping, section rhythm, repeated gaps, content-to-frame clearance, tables/notes, edge insets, and cross-section consistency.
- **Regression:** previously fixed areas remain correct and no local fix introduced a new mismatch elsewhere.

If anything changes after this closure render, invalidate the final review and repeat it. Do not mark completion from lint/typecheck results or pre-fix screenshots.

If fresh latest-state rendering is unavailable, do not close the task. Keep the closure pass `blocked`, record the unverified capability in reason/evidence, lead with "not complete" in the user's language, and identify the exact render/comparison evidence still required. A limitation disclosure is not a waiver, and Final Re-comparison cannot be `not-required`.

## Completion questions

- Does the page have a specific visual point of view?
- Does the page composition serve the content rather than merely contain it?
- Does typography carry hierarchy before cards, borders, or color are added?
- Does mobile preserve the design idea?
- If a reference exists, are the remaining differences minor rather than structural?
- Was the final comparison performed for the latest visual state, whether or not a fix occurred, with typography, borders, and spacing explicitly rechecked?
- Is the implementation maintainable enough that fidelity will survive future changes?
