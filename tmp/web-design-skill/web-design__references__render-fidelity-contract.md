# Implementation Fidelity and Render QA

Detailed rules for HTML fidelity, rendering, screenshot reconstruction, mismatch classes, dominant-mismatch repair, generic-AI detection, and completion gates.

## Contents

- [HTML Fidelity Rules](#16-html-fidelity-rules)
- [Render the Real Result](#17-render-the-real-result)
- [Screenshot-Based Reconstruction Review](#18-screenshot-based-reconstruction-review)
- [Geometry Integrity Gate](#181-geometry-integrity-gate)
- [Critique Mismatch Classes](#19-critique-mismatch-classes)
- [Fix Only Dominant Mismatches](#20-fix-only-dominant-mismatches)
- [Final Re-comparison Gate](#201-final-re-comparison-gate)
- [Generic AI UI Detection](#23-generic-ai-ui-detection)
- [Completion Gate](#26-completion-gate)

## 16. HTML Fidelity Rules

When implementing from an approved visual concept:

- Do not "rationalize" the design into a generic template.
- Do not normalize asymmetry merely because code is easier.
- Do not turn custom compositions into equal-width card grids.
- Do not replace intentional overlap with safe vertical stacking unless a responsive composition requires it.
- Do not remove tension and rhythm through excessive uniform spacing.
- Do not simplify a strong image-to-type relationship into independent left/right columns if the reference relies on overlap or shared composition.
- Do not use placeholder imagery when a specific approved visual asset is required.

The goal is not "a reasonable approximation".

The goal is the closest faithful reconstruction that still respects implementation integrity.

---

## 17. Render the Real Result

Visual review must evaluate rendered output, not source code alone.

When rendering or screenshot capability exists:

- Render the actual implementation.
- Use the target viewport.
- Compare directly with the approved reference.
- Check desktop and mobile when responsiveness matters.
- Re-render after meaningful fixes.

If rendering tools are unavailable, perform a structural visual review and state the limitation only when it materially affects confidence.

---

## 18. Screenshot-Based Reconstruction Review

Compare the render against the approved target in this order:

1. Canvas and major section proportions.
2. Focal point and hierarchy.
3. Text-to-image balance.
4. Main alignment anchors.
5. Typography scale, weight, wrapping, and line-height.
6. Negative space and spacing rhythm.
7. Large background masses and surfaces.
8. Imagery scale, crop, and visual weight.
9. Floating overlays and secondary clusters.
10. Component rhythm.
11. Typography / asset / surface coherence across sections.
12. Brand drift introduced by regenerated assets or newly implemented components.
13. Borders, radius, shadows, icons, and micro details.

Do not start with tiny CSS details when macro composition is still wrong.

---

## 18.1 Geometry Integrity Gate

Run after all required viewport renders and before completion. Use rendered DOM measurements when available; otherwise mark geometry evidence as visual-only and disclose the limitation.

Verify:

- **Reference coverage** — expected versus actual count, role, placement, transforms, and responsive state for every salient repeated motif.
- **Containment** — text, tables, notes, controls, and media remain inside their intended frame or section unless overflow is explicitly approved.
- **Clearance** — content-to-frame, table-to-note, header-to-HUD, and footer-to-decoration spacing follows the locked geometry/token rules.
- **Connectivity** — borders, frame segments, corners, and shared lines form the intended continuous or disconnected topology.
- **Alignment** — elements that share anchors or baselines use the same coordinate system.
- **Collision** — decorative and interactive clusters do not overlap or compete illegibly at required viewports.
- **Cross-section consistency** — repeated divider, border, and spacing motifs use the same semantic tokens unless a section has an approved exception.

Collision-free output is not automatically coherent. Split crowded clusters into semantic zones and check attention hierarchy when several controls, status labels, and decorative elements occupy one region.

Do not pass from source inspection, lint, type checks, or a single screenshot alone. Record measured boxes, overlays, reference-coverage rows, or explicit visual evidence for the required viewports.

---

## 19. Critique Mismatch Classes

Evaluate rendered output across these mismatch classes:

- Art-direction mismatch.
- Composition mismatch.
- Geometry mismatch.
- Assembly/topology mismatch.
- Containment/clearance mismatch.
- Collision/cluster mismatch.
- Typography mismatch.
- Hierarchy mismatch.
- Visual-weight mismatch.
- Spacing/rhythm mismatch.
- Color/contrast mismatch.
- Shape/surface mismatch.
- Asset/imagery mismatch.
- Brand mismatch.
- Responsive mismatch.
- Interaction/state mismatch.
- Reference-fidelity mismatch.
- Token / visual-grammar drift.
- Asset-style drift.
- Cross-section coherence mismatch.
- Generic-AI-pattern contamination.

---

## 20. Fix Only Dominant Mismatches

On each refinement iteration, identify and fix **1 to 3 major mismatches maximum**.

Choose the mismatches with the largest perceptual impact, not the easiest CSS edits.

Preferred order:

1. Wrong art direction or global concept.
2. Macro composition / geometry / proportions.
3. Focal point / visual weight.
4. Typography / hierarchy.
5. Spacing / alignment.
6. Color / surfaces / imagery.
7. Component details / micro-interaction.

Do not scatter one revision across many unrelated details.

Re-render after each meaningful iteration when possible.

---

## 20.1 Final Re-comparison Gate

Run a separate closure pass for the latest visual state, whether or not the first comparison required a mismatch fix. This is not the first target comparison and not a summary of previous iterations. Use fresh renders produced after the latest visual change at every required viewport.

Perform these passes in order:

1. **Whole-page regression** — compare overall hierarchy, composition, section proportions, imagery, responsive continuity, and every previously repaired area against the approved target.
2. **Typography closure** — verify family, weight, size, width, tracking, per-glyph optical exceptions, line-height, wrapping, baselines, clipping, overflow, and cross-viewport consistency where multiple viewports are required.
3. **Border closure** — verify color, opacity, thickness, radius, corner joints, continuous/disconnected topology, frame insets, divider roles, clipping, and accidental double or missing lines.
4. **Spacing closure** — verify section rhythm, grouping, repeated gaps, content-to-frame clearances, table/note spacing, edge insets, optical spacing, and comparable-section consistency.

Record side-by-side, overlay, measured-box, or equivalent evidence that identifies the latest render and target. Also record the manifest target ID, visual revision, timezone-aware capture time, required viewports, and all four closure checks. Lint, type checks, source inspection, an earlier screenshot, or a statement that the page looks finished is not closure evidence.

If this pass finds a material mismatch, reopen mismatch repair, render, Geometry Integrity, visual comparison, and Final Re-comparison. Any later visual, content, font, asset, or responsive change invalidates the pass. Complete only when remaining differences are low-impact or explicitly accepted by the user.

Treat missing or stale latest-state render evidence as a hard completion failure. Required renders and Final Re-comparison cannot be marked `not-required`. If rendering is unavailable, keep Final Re-comparison `blocked`, record the unverified capability in reason/evidence, and state that the design is not complete.

---

## 23. Generic AI UI Detection

Treat these as warning signals, not absolute bans:

- Repeated rounded cards used as the default container for everything.
- Uniform 2/3/4-column card grids with equal visual weight.
- Purple/blue gradients chosen without brand rationale.
- Excessive pills, badges, soft shadows, glass panels, or glowing borders.
- Centered hero + eyebrow + gradient headline + two buttons as an automatic homepage formula.
- Icon + heading + paragraph repeated mechanically across all features.
- Every section wrapped in an identical box.
- Overly uniform spacing that removes rhythm and tension.
- Weak typographic contrast compensated for by more containers.
- Decorative blobs, meshes, stars, or shapes with no conceptual role.
- Dashboard aesthetics applied to products that are not dashboards.
- Mobile layouts produced only by vertically stacking desktop cards.
- Generic stock-photo placement that does not participate in the composition.
- Visual effects added because they look "modern" rather than because they support the product or narrative.

When several appear without product-specific justification, stop polishing and reconsider the art direction or composition.

---

## 26. Completion Gate

Do not call the design finished until all materially relevant conditions pass:

- The machine completion gate passes when the validator and manifest are available; missing `final-recomparison` is a hard stop.

- The page has a recognizable design intent.
- Visual hierarchy works without explanation.
- Typography follows a coherent system rather than section-by-section improvisation.
- Layout rhythm is intentional rather than uniformly spaced.
- Components share a coherent visual grammar.
- Token usage is stable enough that the implementation can evolve without style drift.
- Independently generated assets still satisfy the Asset Style Contract.
- No material brand drift is visible across sections.
- The approved visual target has been decomposed sufficiently for faithful implementation.
- Significant elements satisfy their Assembly Contract and reference-coverage expectations.
- Content containment, clearances, connectivity, alignment, and collisions pass Geometry Integrity at required viewports.
- Final Re-comparison uses latest-state renders, even when no fix loop occurred, and explicitly passes whole-page, typography, border, and spacing closure at required viewports.
- The persistent Design Element Sheet and Asset Manifest reflect accepted production corrections.
- Important copy remains semantically correct and live when appropriate.
- Generated assets are clean, purposeful, and stable.
- No dominant reference mismatch remains.
- Mobile is composed, not merely stacked.
- The primary action and business purpose remain clear.
- Generic AI UI patterns are absent unless genuinely appropriate.
- The implementation still fits the user's technical constraints.
- The page has been judged from rendered output when rendering tools are available.
- No completion statement contradicts a `blocked`, stale, missing, failed, or otherwise unverified Final Re-comparison.

---
