# Responsive Translation and Interaction

Detailed rules for mobile translation, responsive design continuity, and interaction design.

## 21. Responsive Design and Mobile Translation

Treat mobile as a separate composition state sharing the same design system and Token Map. Preserve design continuity even when geometry changes substantially.

Desktop concept approval does **not** automatically mean the mobile composition is solved.

Before adapting a complex desktop design, choose a mobile translation strategy:

- **A. Preserve Composition** — keep the core spatial relationship with scaled adjustments.
- **B. Simplify Composition** — preserve hierarchy and art direction while removing or relocating secondary layers.
- **C. Create Separate Mobile Concept** — redesign the composition for mobile while keeping the same visual system.

For important mobile viewports reconsider:

- Focal point.
- Reading order.
- Navigation behavior.
- Hero proportion.
- Typography scale.
- Image crop.
- Density.
- Touch targets.
- Which floating layers disappear, merge, or relocate.
- Whether CTA moves earlier in the reading sequence.

Do not assume `desktop grid → single column` is sufficient.

A mobile page that merely stacks desktop cards is unfinished when the desktop relies on strong composition.

---

## 22. Interaction Design

Interaction should reinforce the visual concept and information architecture and follow the established motion / interaction language.

- Give visible states to controls that matter.
- Use motion purposefully.
- Use hover as enhancement, never as the only path to essential content.
- Keep interaction affordances consistent.
- Prefer native semantics and accessibility over decorative imitation.
- Avoid motion whose only purpose is to make a static composition look impressive.
- When a static visual concept implies interaction, reconstruct the interaction as behavior rather than baking it into a screenshot.

---
