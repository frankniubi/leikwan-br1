---
name: web-design
description: "Design, redesign, reconstruct, and implement visually polished websites with a visual-first workflow. Use for landing pages, homepages, marketing or product sites, dashboards, screenshot-to-code, image-to-HTML, mockup-to-code, pixel-accurate frontend reconstruction, responsive web design, design-system creation, visual prototypes, frontend visual fidelity, render-based visual QA, and requests to avoid generic AI-generated UI. Lock an evidence-backed execution TODO, verify implementation-ready typography, decompose the approved target into Block/Layer/Text/Geometry maps, lock element assembly, implement semantically, verify rendered geometry, iteratively fix dominant mismatches, then run a fresh latest-state comparison before completion."
license: "MIT"
metadata:
  version: "1.6.1"
  category: "design"
  author: "max0821"
  source: "https://github.com/max0821/web-design-skill"
---

# Web Design

**Version: 1.6.1**

**Author: [max0821](https://github.com/max0821)**  
**Official source: https://github.com/max0821/web-design-skill**  
**License: MIT — commercial use permitted; copyright and license notice must be retained**

> Treat web design as a visual design discipline first and a code-generation task second. A page is unfinished if it merely renders; it must preserve design intent, visual hierarchy, coherence, fidelity, responsive continuity, and implementation integrity.

**Conversation language:** respond in the user's current conversation language unless the user explicitly requests another language. Do not inherit language from examples, metadata, or the default prompt.

## Scope boundary

Do not activate merely because a task contains HTML/CSS. Pure backend, API, infrastructure, data-processing, or non-visual frontend logic is outside this skill unless interface or visual design is also requested.

## Initialization and update check

At the beginning of every design task, before task-specific tool use, design questions, or implementation code:

1. Run the bundled read-only version check once per session when `scripts/check_version.py` is available.
2. Treat `version.json` as the canonical remote version source. Compare it with the currently loaded `metadata.version` using semantic versioning.
3. Emit a concise **Startup Receipt** containing only information confirmed by the current runtime:
   - local loaded Skill name and version;
   - remote version and update status (`current`, `update-available`, `local-ahead`, `remote-unknown`, or `invalid`);
   - references actually read into context;
   - capabilities actually available, required, missing, or unverified;
   - workflow profile, current phase, and first completion gate.
4. If the remote version is newer, tell the user the local and remote versions and ask whether to update. Never update, reinstall, or overwrite the current Skill without explicit user approval.
5. If the remote check fails, continue with the local Skill and mark the remote version as unknown. Never treat a failed check as proof that the local Skill is current.

Never claim that a Skill, reference, tool, file, browser, render, or visual asset is loaded or available unless it is confirmed by the current runtime.

## Workflow profiles and phase gates

Classify the task before the core workflow:

- **FULL** is mandatory for a new landing or marketing page, a new visual direction, screenshot/reference-to-code, brand-heavy work, pixel-accurate work, or any request for polished visual fidelity or responsive visual review.
- **LITE** is allowed only when an Approved Visual Target already exists and the requested change is local, with no new asset, typography, composition, brand, or responsive-direction change.

For **FULL**, pass the Typography Feasibility Gate before approving a typography-dependent visual target. Do not write final implementation code before the Approved Visual Target, required decomposition maps, Design Element Sheet decision, Assembly Contract, Asset Manifest, and required asset locks exist, unless the user explicitly waives a waivable gate. After each gate, update the current phase, Locked Execution TODO, evidence, and next gate in a concise receipt. If a required capability is unavailable, keep the affected TODO item `blocked` and record the unverified capability in its reason/evidence instead of silently skipping it.

## Locked Execution TODO

Immediately after the page job, scope, and current direction are clear enough to act, emit a visible **Locked Execution TODO** without waiting for the user to ask for a plan. Use the runtime's plan capability when available; otherwise maintain the TODO in the conversation. In a writable project, optionally persist the same state from `assets/design-gates.template.json` when continuity or machine validation materially helps.

Each required item must include a deliverable, completion condition, and evidence. Use only `pending`, `in-progress`, `done`, `blocked`, `user-decision`, or `not-required`. Keep exactly one item `in-progress` while work remains; a completed manifest has zero. Never mark an item `done` from commentary alone. Evidence must identify an artifact, user approval, render, comparison, or test result.

Do not enter a downstream phase while a prerequisite is incomplete. If the user changes the page job, scope, Approved Visual Target, typography, asset style, composition, or responsive direction, reopen every affected downstream item. Do not ask permission merely to create or refresh the TODO; ask only when a real design decision remains unresolved.

For FULL tasks the TODO must cover, when relevant: scope/content contract, Typography Feasibility, Approved Visual Target, four maps, Coherence Contract, Design Element Sheet, Assembly Contract, Asset Manifest and locks, implementation, mobile composition when mobile is required, every required viewport render, Geometry Integrity, comparison, dominant-mismatch repair, Final Re-comparison, and completion review.

## Core workflow

`Understand → Progressive Clarification → Lock Scope → Locked TODO → Art Direction → Typography Feasibility → Visual Exploration → User Reaction → Approved Visual Target → Decompose → Coherence Contract → Resolve Assets → Design Element Sheet → Assembly Contract → Asset QA/Lock → Implement → Render → Geometry Integrity → Compare → [Fix 1–3 → Re-render → Geometry Integrity → Compare]* → Final Re-comparison → Complete`

Use the LITE profile only for the narrowly scoped exception above. For FULL tasks, do not skip the stages that stabilize visual intent and implementation fidelity.

## Non-negotiable rules

1. **Evidence priority:** explicit user constraints and approved references outrank generic conventions. Once a visual concept is approved, it becomes the primary visual source of truth until the user changes direction.
2. **Scope/content lock:** distinguish the public page job, internal tools, audience task, content model, and deployment intent before designing the interface.
3. **Visible execution contract:** create and maintain the Locked Execution TODO after discussion converges. Downstream work requires completed prerequisites and evidence.
4. **Progressive clarification:** ask only the highest-impact unresolved design question. Prefer 2–4 concrete options and recommend one when defensible. Never front-load a long questionnaire.
5. **Visual-first when appropriate:** for visually important pages without a strong supplied reference, strongly prefer visual exploration before final HTML. The first concept is not automatically approved.
6. **Attention control:** manage faces, saturated color, arrows, large numbers, floating UI, and oversized type as a limited attention budget. Establish a deliberate focal sequence.
7. **Coherence:** typography, surfaces, assets, composition, motion, and responsive states must belong to one visual language. Maintain a cross-cutting Token Map and Asset Style Contract.
8. **Typography feasibility before target:** when typography materially shapes the composition, verify a real source, glyph coverage, required weights, multilingual pairing, and exact-text rendering before approving the visual target. Do not let generated lettering silently become the production font plan.
9. **Approved target lock:** do not casually regenerate the whole page after approval. Local problems require local fixes.
10. **Element production before code:** for image/reference-to-code work, isolate or reproduce required typography specimens, icons, lines, frames, patterns, textures, and complex imagery before final implementation. Do not wait until the page is coded to discover missing assets.
11. **Assembly before code:** an isolated element inventory is insufficient. Lock each significant element's role, scope, instance count, transforms, connectivity, shared coordinates, z-order, safe area, and responsive behavior before implementation.
12. **Persistent element sheet:** keep the Design Element Sheet inspectable through implementation and QA. Synchronize accepted crop, edge cleanup, optical correction, role, or construction changes back to the sheet and manifest.
13. **Semantic truth vs visual truth:** approved visual target controls appearance; real copy/user/live-site content controls semantic text. Exact text, buttons, metrics, labels, and structural UI should remain live HTML/CSS/SVG when possible.
14. **Render and measure, don't infer:** judge implementation from the real rendered result. When DOM geometry is available, measure containment, clearance, alignment, connectivity, and collisions instead of relying on source code or visual intuition alone.
15. **Dominant mismatch loop:** each refinement pass fixes only 1–3 highest-impact mismatches, then re-renders.
16. **Fresh final comparison:** compare the newest renders against the approved target after the latest visual state, whether or not a mismatch required a fix. Completion requires a dedicated whole-page regression, typography, border, and spacing sweep; lint, type checks, old screenshots, or an earlier comparison cannot substitute. If latest-state render evidence is unavailable or stale, keep Final Re-comparison `blocked`, record the verification limitation in reason/evidence, and never say the task is finished, closed, ready to end, or equivalent.
17. **Mobile is composition:** responsive design preserves focal hierarchy, image/text relationships, typography personality, and brand grammar; it is not desktop stacked vertically.
18. **Preserve architecture:** in an existing project, keep the user's stack, routing, component architecture, conventions, and business-critical structure unless a change is necessary and justified.

## Typography Feasibility Gate

Run this gate before Visual Exploration when typography is a major visual mass, or immediately after loading a supplied reference and before approving its production plan.

1. Honor an existing licensed brand font first. Otherwise search Google Fonts first when web access is available; shortlist no more than three defensible candidates instead of asking the user to browse the whole catalog.
2. Render the exact production strings that control composition, including the real hero title, representative body copy, `A–Z`, `a–z`, `0–9`, punctuation, metrics/units, and relevant multilingual text. Inspect numeral and glyph widths individually when they form aligned labels or repeated identifiers.
3. Verify family name, source URL or local file, license, category, available weights/styles/variable axes, required script subsets, glyph coverage, loading method, and fallback stack. Never infer CJK coverage from a Latin specimen.
4. For multilingual pages, define explicit Latin/CJK role pairing and compare cap height, stroke weight, density, baseline, punctuation, line height, and optical scale.
5. Use the verified font in the initial visual target. Generate imagery separately and composite real typography when image generation cannot guarantee the font; generated letters remain reference-only.
6. If no production font matches, classify the exception explicitly: fixed clean title graphic, controlled vector glyphs, or a QA-checked raster atlas. Keep dynamic and semantic text live whenever possible.
7. Record the locked typography decision in the Token Map and Asset Manifest. When one glyph needs optical correction, scope the adjustment to that exact glyph or label and record it; do not change global tracking to compensate for a local width mismatch. Any later font, weight, tracking, optical exception, line-break, or language change invalidates dependent composition and responsive evidence.

Pass only with a rendered candidate comparison and evidence that the selected typography can be loaded and used in the final implementation. A font that merely feels stylistically similar is not sufficient.

## Approved Visual Target → four maps

After approval, decompose the target into exactly four implementation maps:

- **Block Map** — sections, role, span, layout model, anchors, adjacency, mobile adaptation.
- **Layer Map** — visual layers, parent, position, size, z-order, prominence, structural/decorative role, implementation medium.
- **Text Map** — semantic copy, role, parent, wrapping, emphasis, semantic tag, live-text requirement.
- **Geometry Map** — canvas, section bounds, normalized ratios, insets, column ratios, alignment lines, overlap, crop, negative space.

The **Token Map** is a cross-cutting coherence contract shared by all four maps; it is not a fifth decomposition map.

## Asset Resolution Plan

Classify each significant visual layer as one of:

- live HTML / UI
- CSS / SVG
- existing / cropped asset
- clean local regeneration
- omit / simplify

For generated/replaced assets, define crop/aspect, alpha/background behavior, safe margins, pose, lighting, color, focal point, anchor box, expected placement, and forbidden changes when relevant.

If one approved asset is dirty or unusable, regenerate only the smallest useful local asset. After QA, mark it **LOCKED** and prevent later generations from changing its accepted role, crop, style, or placement without explicit approval.

## Design Element Sheet and Asset Production Gate

For screenshot/reference-to-code or any target with custom visual language, produce a separate **Design Element Sheet** before final implementation. Include the relevant typography specimen, `A–Z`, `a–z`, `0–9`, punctuation, multilingual samples, icons, arrows, dividers, strokes, corners, frames, HUD parts, patterns, textures, and decorative motifs. Use a clean grid, isolated cells, safe margins, stable IDs, and neutral or transparent backgrounds so elements can be inspected and exported independently.

Do not trust image-generated letters or exact UI text as production typography. Carry the locked Typography Feasibility decision into the sheet, show the relevant reference and production candidate together, and render the specimen from the verified font; use generated lettering only as a visual reference or convert verified custom glyphs into a controlled vector/font asset. Prefer HTML for semantic text and CSS/SVG for simple geometry.

Create an **Asset Manifest** mapping every significant element ID to its source, implementation medium, output file or token, intended placement, QA evidence, and state. Export required production assets individually; the sheet alone is not the final asset library. Mark accepted assets `LOCKED`.

Create an **Assembly Contract** after the sheet and before final implementation. This is a cross-cutting relationship contract for the Layer and Geometry Maps, not a fifth decomposition map. For each significant element or cluster, record semantic role and scope, expected instance count, master-versus-derived instances, allowed transforms, connection topology, shared anchors/coordinates, z-order, content safe area, collision constraints, and responsive changes. Visually similar elements with different jobs require different IDs. Prefer one CSS/SVG master plus deterministic transforms for regular mirrored or rotated geometry.

Keep the Element Sheet available as a persistent inspection surface through implementation and QA. If production reveals an accepted crop, transparent-edge cleanup, local optical correction, role clarification, or assembly change, update the sheet and Asset Manifest before relocking it. Inspect alpha edges, contaminated pixels, safe margins, and unintended baked-in text or overlays. First distinguish a source-asset defect from a CSS treatment defect; repair the smallest non-destructive layer instead of replacing a locked source unnecessarily.

Do not enter final implementation until the sheet is produced or explicitly marked `not-required` with evidence, the Assembly Contract resolves all significant relationships, the manifest resolves all significant layers, and required assets pass QA. Do not crop contaminated icons, text, frames, or overlays from a full-page mockup merely because it is convenient; regenerate or reconstruct the smallest clean element.

When filesystem and command execution are available, copy `assets/design-gates.template.json` into the project. Set `required_viewports`, `target_id`, `visual_revision`, and `latest_visual_change_at`; keep every completion condition current. Run `scripts/check_design_gates.py <manifest> --gate implementation` before final implementation and `--gate completion` before declaring completion. Required renders and Final Re-comparison use structured revision/time evidence and cannot be marked `not-required`. If validation is unavailable, enforce the same checks visibly and disclose that machine validation was not run.

## Implementation order

1. Page canvas and section proportions.
2. Primary alignment system.
3. Live typography structure.
4. Background/surface layers.
5. Hero or major visual assets.
6. Overlays and secondary composition details.
7. Service/content sections.
8. CTA/trust/support details.
9. Responsive translation.
10. Render comparison and iterative fixes.

Do not begin with decorative micro-details. Do not switch the user's implementation technology merely to simplify the design task.

## Render QA

After implementation:

1. Render the actual page at the target viewport.
2. Build a reference-coverage check for every salient repeated or connected motif: expected versus actual instance count, role, placement, transforms, connections, and responsive state.
3. Run Geometry Integrity checks for content containment, frame/content clearance, shared alignment, continuous borders or paths, and decorative collisions. Use rendered/DOM measurements when available.
4. Compare hierarchy, geometry, typography, crop, visual weight, color, surfaces, negative space, cross-section motif consistency, and responsive behavior against the approved target/reference.
5. Identify the 1–3 mismatches with the largest perceptual impact.
6. If material mismatches exist, fix only those mismatches, then re-render and repeat. If none exist, record the no-repair decision with comparison evidence.
7. Run **Final Re-comparison** on fresh renders for every `required_viewports` entry, even when no fix loop occurred. Recheck the whole page, then explicitly sweep typography, borders/dividers/frames, and spacing/rhythm against the target and locked tokens.
8. Record structured Render, Geometry Integrity, visual-comparison, Final Re-comparison, and completion-review evidence using the current target ID, visual revision, timezone-aware capture times, required viewports, and required check sets.
9. If the closure pass finds a material mismatch or any later visual change occurs, update the revision/time and reopen repair, render, Geometry Integrity, and Final Re-comparison. If fresh rendering cannot be performed, keep the closure item `blocked` and report the design as incomplete.

Actively reject generic AI patterns when they contradict the art direction: repetitive equal cards, arbitrary gradients, excessive pills, decorative glass surfaces, default centered sections, weak hierarchy, or visually unrelated component styles.

## Reference loading

Load detailed references only when the task requires them:

- Art direction, clarification, visual exploration, feedback, attention: [references/art-direction-and-exploration.md](references/art-direction-and-exploration.md)
- Typography feasibility, Google Fonts candidate checks, visual grammar, tokens, asset style, composition, motion, brand drift: [references/visual-coherence-contract.md](references/visual-coherence-contract.md)
- Macro composition and Approved Visual Target locking: [references/approved-target-and-composition.md](references/approved-target-and-composition.md)
- Four maps, Asset Resolution Plan, local regeneration, asset lock: [references/decomposition-and-assets.md](references/decomposition-and-assets.md)
- Locked TODO, evidence rules, persistent Design Element Sheet, Assembly Contract, Asset Manifest, and invalidation: [references/execution-and-production-gates.md](references/execution-and-production-gates.md)
- HTML fidelity, screenshot comparison, mismatch repair, completion gate: [references/render-fidelity-contract.md](references/render-fidelity-contract.md)
- Mobile translation and interaction: [references/responsive-and-interaction.md](references/responsive-and-interaction.md)
- Compact reusable design-system guidance: [references/design-system.md](references/design-system.md)
- Compact visual-QA checklist: [references/visual-qa.md](references/visual-qa.md)
- Workflow examples: [references/workflow-examples.md](references/workflow-examples.md)

## Output behavior

Start every design task with the Startup Receipt. Match the requested deliverable. If the user asks for visual exploration, produce concepts first. If they ask for an image, produce the visual. If they ask for code from an approved visual, decompose then implement. If they ask for critique, provide prioritized findings instead of silently redesigning.

Do not dump internal maps/checklists into every response. Use them internally unless exposing them helps the user evaluate a decision or continue implementation. The Startup Receipt, Locked Execution TODO, and phase/gate status are required exceptions.

Before using completion language such as `done`, `finished`, `complete`, `ready to close`, or an equivalent in the conversation language, run `check_design_gates.py <manifest> --gate completion` when the script and project manifest are available. A failed gate, a waived non-waivable gate, missing structured Final Re-comparison evidence, or a render older than the latest visual revision/time is a hard stop. Lead with the incomplete status and the exact missing evidence.

## Completion gate

Before declaring completion, verify:

- Startup Receipt was emitted and the local version was identified.
- Remote version status was reported as current, update-available, local-ahead, remote-unknown, or invalid.
- Locked Execution TODO was emitted after scope confirmation, kept current, and every required `done` item has evidence.
- Typography Feasibility passed before visual approval when typography materially affects composition.
- Design intent is clear and appropriate to the page job.
- Approved target/reference still controls the visual direction.
- Required Design Element Sheet and Asset Manifest exist, or their `not-required` state is justified.
- Assembly Contract covers the role, count, transforms, topology, safe areas, and responsive behavior of significant elements.
- Design Element Sheet and Asset Manifest reflect accepted production corrections and remain synchronized.
- Typography, assets, surfaces, composition, and motion are coherent.
- Important copy remains semantic/live.
- Generated assets have not drifted from locked contracts.
- Rendered hierarchy, geometry, crop, visual weight, and negative space are faithful.
- Geometry Integrity passed for reference coverage, containment, clearances, connections, alignment, and collisions at required viewports.
- Final Re-comparison ran for the latest visual state whether or not a fix occurred, used every required viewport, and explicitly passed typography, border, spacing, and full-page regression review.
- Mobile preserves the intended focal sequence and relationships when mobile is a required viewport.
- No dominant mismatch remains unreviewed.
- Existing project architecture and required functionality remain intact.
- Any unavailable or unverified render, browser, asset, or interaction check is explicitly disclosed.
- Machine Gate validation passed when available, or its absence was explicitly disclosed.

If Final Re-comparison is unavailable, stale, failed, or unverified, keep its TODO status `blocked`; the completion gate fails. Disclosure lowers confidence and never waives latest-state visual evidence.

A technically valid page that looks generic is unfinished. A beautiful page that ignores business purpose is unfinished. A faithful screenshot match with brittle implementation is unfinished. The target is all of these at once: **design intent + visual hierarchy + visual coherence + stable visual specification + asset discipline + implementation fidelity + responsive continuity + implementation integrity**.
