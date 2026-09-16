# Changelog

## 1.6.1 — 2026-08-24

### Reliability corrections (version unchanged)
- Aligned the TODO status contract: unavailable verification now keeps the item `blocked`; `unverified` is descriptive evidence, not a status.
- Added manifest schema 2 with explicit completion conditions, required viewports, target ID, visual revision, latest-change timestamp, and structured render/final evidence.
- Made required viewport renders, Geometry Integrity, visual comparison, Final Re-comparison, and completion review non-waivable.
- Required Final Re-comparison for the latest visual state even when the first comparison needs no repair.
- Added missing implementation, mobile-composition, and mismatch-repair lifecycle items to the manifest.
- Corrected Semantic Versioning precedence for numeric and mixed prerelease identifiers.
- Added navigation to long reference files and completed the closure phase in every workflow example.

### Added
- Final Re-comparison Gate using fresh renders produced after the latest visual change.
- Mandatory final closure sweeps for typography, borders/dividers/frames, spacing/rhythm, and full-page regression.
- Machine-gate requirement for `final-recomparison` evidence before completion.

### Changed
- Any visual, content, font, asset, or responsive change after closure invalidates Final Re-comparison and reopens render, Geometry Integrity, and comparison work.
- Lint, type checks, source inspection, old screenshots, and pre-fix comparisons cannot serve as final visual evidence.
- Missing or stale latest-state renders are a hard completion failure; the agent must report an incomplete/blocked state rather than saying the task is ready to close.

## 1.6.0 — 2026-08-24

### Added
- Typography Feasibility Gate before typography-dependent visual approval.
- Google Fonts-first public font discovery with exact-string, script-coverage, license, weight/axis, loading, fallback, and multilingual-pairing evidence.
- Machine-gate requirement for `typography-feasibility` in FULL implementation manifests.
- Persistent Design Element Sheet synchronization through implementation and QA.
- Assembly Contract for semantic roles, instance counts, transforms, topology, shared anchors, safe areas, collisions, and responsive behavior.
- Geometry Integrity completion gate for measured reference coverage, containment, clearances, connectivity, alignment, and collisions.
- Machine-gate requirement for final `element-sheet-sync` evidence after accepted production corrections.
- Asset-hygiene checks for transparent edges, residual pixels, safe margins, and treatment-versus-source diagnosis.

### Changed
- Visual exploration must composite verified production typography instead of treating image-generated lettering as the implementation plan.
- Design Element Sheets carry the locked typography decision and compare reference versus production specimens.
- Local glyph-width problems use scoped optical corrections instead of global tracking changes.
- Regular mirrored/rotated geometry prefers one CSS/SVG master with deterministic transforms.
- Render QA verifies repeated motif coverage and cross-section spacing/divider consistency before declaring completion.

## 1.5.0 — 2026-08-24

### Added
- Mandatory evidence-backed Locked Execution TODO after scope and direction converge.
- Scope/content contract that separates the public page job from internal tools and test workflows.
- Design Element Sheet for typography specimens, icons, lines, frames, HUD parts, patterns, textures, and other implementation-ready visual elements.
- Asset Manifest requirement with stable IDs, implementation media, output paths/tokens, QA evidence, and lock state.
- `assets/design-gates.template.json` and `scripts/check_design_gates.py` for optional machine validation in writable work environments.

### Changed
- FULL workflow now blocks final implementation until the visual target, four maps, element-sheet decision, asset manifest, and required asset locks are evidenced.
- TODO items require deliverables, completion conditions, and evidence; downstream items reopen when scope or locked design inputs change.
- Completion now requires desktop/mobile render evidence, target comparison, and machine-gate disclosure when validation cannot run.
- Startup version checks use GitHub's Contents API with cache bypassing so a freshly published release is not hidden by stale Raw CDN responses.
- Synchronized visible version and release documentation to v1.5.0.

## 1.4.0 — 2026-08-23

### Added
- Mandatory Startup Receipt for local version, remote version status, loaded guidance, capabilities, workflow profile, and first gate.
- Read-only startup version check through `web-design/version.json` and `scripts/check_version.py`.
- `FULL` / `LITE` workflow classification with phase-gate requirements.
- Explicit handling for unavailable or unverified browser, render, asset, and interaction capabilities.

### Changed
- The Skill now prompts the user when a newer remote version is available, without automatically updating or overwriting the current Skill.
- Completion requires version status and unverified capability disclosure in addition to visual and implementation checks.
- Synchronized the visible version across `SKILL.md`, `README.md`, `README.zh-TW.md`, `agents/openai.yaml`, and `version.json`.

## 1.3.0 — 2026-08-22

### Added
- Canonical source metadata and standards-aligned top-level MIT license field.
- Discovery-oriented routing terms for redesign, screenshot-to-code, image-to-HTML, mockup-to-code, pixel-accurate frontend, responsive design, and visual QA.
- Conversation-language rule.
- Progressive-disclosure references for detailed design, decomposition, responsive, and render-fidelity contracts.
- Plugin Directory submission pack and reviewer notes.

### Changed
- Main `SKILL.md` reduced below the recommended 500-line target while preserving detailed v1.2.1 rules in references.
- Visible version and OpenAI interface description updated to `v1.3.0`.
- MIT wording clarified to require retention of copyright and license notice.


All notable changes to `web-design` are documented here.

## [1.2.1] - 2026-08-22

### Added

- Author and license metadata in `SKILL.md`.
- MIT License inside the Skill package.
- English `web-design/README.md`.
- Traditional Chinese `web-design/README.zh-TW.md`.
- Explicit commercial-use permission and copyright notice.

### Changed

- UI short description now shows `v1.2.1`.
- Skill package now carries its own documentation and license so attribution survives when the Skill folder is redistributed independently of the GitHub repository.

## [1.2.0] - 2026-08-22

### Added

- Visible version marker near the top of `SKILL.md`.
- Typography System.
- Visual Grammar / Style Consistency.
- Cross-cutting Token Map.
- Asset Style Contract for multi-pass image generation.
- Composition consistency rules.
- Focal Hierarchy Contract and attention budget.
- Section Role Model.
- Explicit Image / Text Relationship modes.
- Motion and Interaction Language with motion budget.
- Responsive Design Continuity.
- Brand Drift Detection.
- Visual Coherence Review.
- `agents/openai.yaml` UI metadata with version in `short_description`.

### Changed

- Core workflow now includes a `Coherence Contract` between decomposition and asset resolution.
- Quality target now treats Design Intent, Visual Fidelity, Visual Coherence, and Implementation Integrity as connected concerns.

## [1.1.0] - 2026-08-22

### Added

- Progressive, option-based design clarification.
- Visual exploration before implementation for high-visual pages.
- User reaction and explicit Approved Visual Target.
- Four decomposition artifacts: Block Map, Layer Map, Text Map, Geometry Map.
- Asset Resolution Plan.
- Local regeneration policy.
- Asset approval / lock to prevent target drift.
- Visual-weight control and focal hierarchy.
- Separate mobile translation strategies: preserve, simplify, or create a separate mobile concept.
- Screenshot-based reconstruction review.

### Changed

- Workflow shifted from direct `Art Direction → HTML` toward a visual-design-to-code decomposition pipeline.
- Generated-image text is not semantic truth; original/user copy remains the semantic source of truth.

## [1.0.0] - 2026-08-22

### Added

- Initial `web-design` Skill.
- Art direction and visual system workflow.
- Composition-first implementation.
- Render-based visual QA.
- 1–3 dominant mismatch refinement rule.
- Generic AI UI detection.
- Reference matching mode.
- Responsive composition guidance.
