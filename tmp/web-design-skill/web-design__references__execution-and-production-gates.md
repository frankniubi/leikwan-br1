# Execution TODO and Asset Production Gates

Use these rules for FULL design tasks and for any reference-to-code task where skipped stages would create visual drift.

## Contents

- [Scope and content contract](#scope-and-content-contract)
- [Locked Execution TODO](#locked-execution-todo)
- [Typography Feasibility Gate](#typography-feasibility-gate)
- [Design Element Sheet](#design-element-sheet)
- [Assembly Contract](#assembly-contract)
- [Production-medium rules](#production-medium-rules)
- [Asset hygiene and local correction](#asset-hygiene-and-local-correction)
- [Asset Manifest](#asset-manifest)
- [Machine-readable gate contract](#machine-readable-gate-contract)
- [Gate invalidation](#gate-invalidation)

## Scope and content contract

Before visual work, record:

- Public page job and intended audience action.
- Internal tools or workflows that must not become public UI.
- Content model and reading order.
- Approved technical and deployment scope.

Reopen downstream work if this contract changes.

## Locked Execution TODO

Emit the TODO immediately after discussion converges. Do not wait for the user to request it.

Use these states only:

- `pending`
- `in-progress`
- `done`
- `blocked`
- `user-decision`
- `not-required`

Keep exactly one item `in-progress` while work remains. Use zero `in-progress` items only after every required completion item passes. For every item record:

- Deliverable.
- Completion condition.
- Evidence.
- Blocking decision when applicable.

An assertion such as "maps locked" or "design complete" is not evidence. Evidence identifies an artifact, target approval, render, comparison, test, or explicit accepted exception. A `not-required` item needs both a reason and waiver/decision evidence; the agent cannot silently waive its own gate. When a capability is unavailable, use `blocked` as the TODO status and record the unverified capability in reason/evidence; `unverified` is not a TODO status.

When the user changes scope or a locked design input, reopen that item and every dependent downstream item.

## Typography Feasibility Gate

Pass before approving a typography-dependent visual target.

Required evidence:

- Existing brand-font decision, or a Google Fonts-first search with no more than three candidates.
- Exact-string specimens for hero/display, body, `A–Z`, `a–z`, `0–9`, punctuation, metrics/units, and required scripts.
- Verified source, license, weights/styles/axes, subsets, glyph coverage, loading method, and fallback stack.
- Explicit Latin/CJK pairing when multilingual content exists.
- Rendered comparison against the approved reference or art direction.
- Token Map and Asset Manifest entries for the selected production font or justified graphic/glyph fallback.

Do not pass because a candidate merely renders or feels close. If the source is unavailable, coverage is incomplete, or the font changes composition-critical wrapping, keep the gate pending and do not approve the dependent target.

## Design Element Sheet

For image/reference-to-code work, create a separate implementation sheet when the target contains custom visual language or lacks clean source assets.

Include only relevant groups:

- Typography specimen: locked feasibility decision, reference-versus-production comparison, verified font, `A–Z`, `a–z`, `0–9`, punctuation, representative multilingual text, display/body/label/numeric roles.
- Icons and controls.
- Arrows, dividers, strokes, corners, frames, brackets, HUD parts, and badges.
- Patterns, textures, grain, grids, glows, and decorative motifs.
- Complex illustration, photo, 3D, or hero assets that require isolated generation.

Sheet rules:

- Use a clean grid and stable IDs such as `TYPE-01`, `ICON-01`, `LINE-01`, and `FRAME-01`.
- Keep elements isolated with safe margins and no accidental overlap.
- Put labels outside extraction areas.
- Use transparent or neutral backgrounds as appropriate.
- Avoid perspective and scene lighting unless they are part of the approved asset contract.
- Use sufficient resolution for the intended placement.
- Keep the sheet available as an inspection surface throughout implementation and QA.
- Synchronize accepted crop, edge cleanup, optical correction, role, construction, or assembly changes back to the sheet and Asset Manifest.

The sheet is a specification and extraction source, not automatically the production asset library.

## Assembly Contract

Create this contract after the Design Element Sheet and before final implementation. It supplements the Layer and Geometry Maps; it is not a fifth decomposition map.

For each significant element or cluster record:

- Stable ID, semantic role, and scope. Do not reuse one ID for visually similar elements with different jobs.
- Expected instance count and placement coverage.
- Master-versus-derived instances and allowed mirror, rotation, crop, or scale transforms.
- Topology: continuous paths, segment connections, intersections, attachment points, and shared coordinates.
- Parent, z-order, alignment anchors, and content safe areas.
- Collision constraints against header, footer, content, controls, and other decoration.
- Desktop/mobile differences and any viewport-specific omission or rearrangement.

Prefer one CSS/SVG master with deterministic transforms for regular mirrored or rotated geometry. Do not generate multiple near-identical assets when transforms preserve the approved result. Conversely, do not reuse one element merely because it looks similar when its visual role, scale, or connection behavior differs.

Pass only when every salient repeated or connected motif in the approved target has an explicit implementation relationship. An isolated inventory of corners, lines, icons, or HUD parts is not sufficient evidence.

## Production-medium rules

- Keep semantic copy, labels, metrics, buttons, and exact text as live HTML.
- Render typography specimens from verified fonts whenever possible.
- Treat image-generated letters as reference-only until verified or converted into controlled vector/font assets.
- Prefer CSS or SVG for simple icons, lines, frames, corners, and geometric patterns.
- Use PNG/WebP/AVIF for complex raster imagery and textures.
- Export every required production asset as an individual file or implementation token.

## Asset hygiene and local correction

- Inspect alpha edges, residual pixels, unintended backgrounds, baked-in text/UI, and safe margins at intended display scale.
- Distinguish a source-asset defect from a treatment defect such as an overly dark overlay, opacity, blend mode, filter, or crop.
- Repair the smallest non-destructive layer first. Preserve a locked source when CSS/SVG treatment is the actual problem.
- Scope optical typography corrections to the affected glyph or label. Do not alter global tracking because one numeral or glyph is unusually narrow.
- Record accepted corrections in the Token/Text/Geometry Map as appropriate and synchronize the Element Sheet and Asset Manifest before relocking.

## Asset Manifest

Record for every significant element:

- Stable ID and category.
- Source and source version.
- Implementation medium.
- Output path or token.
- Intended placement and crop behavior.
- QA evidence.
- State: `pending`, `approved`, `locked`, `rejected`, or `not-required`.

Do not pass the Asset Production Gate while a significant visible layer is unresolved.

## Machine-readable gate contract

Use `assets/design-gates.template.json` schema version 2. Set:

- `required_viewports` to the viewports that the completed design must support.
- `target_id` to the approved visual target or explicit existing target.
- `visual_revision` to the latest visual source/build revision.
- `latest_visual_change_at` to a timezone-aware ISO 8601 timestamp.

Every item requires `deliverable`, `completion_condition`, status, and evidence. Before implementation, all implementation prerequisites must pass and `implementation` must be the single `in-progress` item. At completion, no item remains `in-progress`.

Required viewport renders, `implementation`, `mobile-composition` when mobile is required, `geometry-integrity`, `visual-comparison`, `final-recomparison`, and `completion-review` are non-waivable. `mismatch-repair` may be `not-required` only when the latest comparison proves no material mismatch and records that decision.

For each required viewport, record structured render evidence:

```json
{
  "evidence": [
    {
      "kind": "render",
      "viewport": "desktop",
      "artifact": "artifacts/render-desktop.png",
      "visual_revision": "git:abc123",
      "captured_at": "2026-08-24T20:00:00+08:00"
    }
  ]
}
```

Record subsequent closure stages in chronological order. Geometry Integrity requires `kind: geometry-integrity`, the current `visual_revision`, all required viewports, a timezone-aware `captured_at`, and checks for `reference-coverage`, `containment`, `clearance`, `connectivity`, `alignment`, and `collisions`. Visual comparison requires `kind: visual-comparison`, the current `target_id` and `visual_revision`, all required viewports, and a capture time after Geometry Integrity.

Record Final Re-comparison with structured evidence matching the manifest:

```json
{
  "evidence": [
    {
      "kind": "final-recomparison",
      "target_id": "approved-target-v1",
      "visual_revision": "git:abc123",
      "captured_at": "2026-08-24T20:05:00+08:00",
      "viewports": ["desktop", "mobile"],
      "checks": ["whole-page-regression", "typography", "borders", "spacing"]
    }
  ]
}
```

After Final Re-comparison, record completion review with `kind: completion-review`, the same `target_id` and `visual_revision`, and a timezone-aware `captured_at` not earlier than Final Re-comparison.

The validator rejects renders older than `latest_visual_change_at`, closure stages recorded out of order, missing completion conditions, mismatched target/revision/viewports, and `not-required` on non-waivable gates.

## Gate invalidation

Reopen dependent items when any of these change:

- Page job or content model.
- Approved Visual Target.
- Typography or asset style.
- Major composition or geometry.
- Assembly topology, instance count, shared anchors, safe areas, or responsive relationships.
- Responsive strategy.
- Locked asset source, crop, role, or placement.

After invalidation, do not continue from the old downstream status.
