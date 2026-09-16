# Design System Guidance

Use this reference when establishing art direction or when a render feels generic, over-componentized, or visually incoherent.

## Contents

- [Start from a visual thesis](#1-start-from-a-visual-thesis)
- [Typography is structural](#2-typography-is-structural)
- [Spacing should create rhythm](#3-spacing-should-create-rhythm)
- [Containers must have a reason](#4-containers-must-have-a-reason)
- [Shape language should be coherent](#5-shape-language-should-be-coherent)
- [Color should have roles](#6-color-should-have-roles)
- [Imagery must participate in composition](#7-imagery-must-participate-in-composition)
- [Composition patterns worth considering](#8-composition-patterns-worth-considering)
- [Responsive translation](#9-responsive-translation)
- [Distinctiveness test](#10-distinctiveness-test)

## 1. Start from a visual thesis

A visual thesis is a constraint that creates recognizable decisions. Examples:

- Dense technical publication with hard alignment and restrained color.
- Warm editorial commerce with oversized serif display type and documentary photography.
- Playful game-community interface using bold type, illustrated objects, and intentionally irregular composition.
- Precision industrial product page using monochrome surfaces, small labels, measurement-like details, and strict geometry.

The thesis should explain why typography, composition, color, imagery, and motion belong together.

Avoid style-word soup such as "modern, clean, premium, futuristic" unless each word maps to concrete visual behavior.

## 2. Typography is structural

Choose typographic roles before individual font sizes.

Typical roles:

- Display / campaign statement.
- Page title.
- Section title.
- Body / editorial copy.
- UI label.
- Metadata / caption.
- Numeric or data emphasis.

Create hierarchy through multiple variables: size, weight, width, line-height, tracking, case, and placement.

Do not make every heading bold sans-serif with only incremental size differences.

Check line wrapping deliberately. A hero headline that wraps differently from the intended composition changes the page more than most shadows or radius values.

## 3. Spacing should create rhythm

Do not use one spacing scale mechanically across every relationship.

Think in three levels:

- Intra-component spacing: tightly related items.
- Local grouping spacing: groups within a section.
- Section rhythm: large changes in density and breathing room.

Good pages often alternate compressed and expansive regions rather than maintaining identical vertical gaps throughout.

## 4. Containers must have a reason

A container is useful when it communicates:

- Shared state.
- Shared background or behavior.
- A semantic object.
- A meaningful boundary.
- A reusable interactive unit.

Do not wrap content merely to make it look designed. Alignment, whitespace, rules, typography, and background changes can create structure without cards.

## 5. Shape language should be coherent

Define a small vocabulary:

- Square / sharp.
- Slightly softened.
- Highly rounded.
- Mixed geometry with a clear rule.

Use radius according to object semantics. A page where buttons, cards, images, labels, panels, and inputs all use the same 16px radius usually feels generated rather than designed.

Borders, shadows, and separators should also belong to the same visual language.

## 6. Color should have roles

Assign roles rather than picking colors object by object:

- Canvas.
- Surface 1 / Surface 2.
- Primary text.
- Secondary text.
- Accent/action.
- Selection/focus.
- Positive/warning/negative when needed.

A brand accent does not need to fill large areas. Often the strongest branded compositions use accent color sparingly and let typography, imagery, and layout carry identity.

## 7. Imagery must participate in composition

Do not treat images as rectangles dropped into completed layouts.

Decide:

- Documentary vs illustrative vs product-centric vs atmospheric.
- Full-bleed vs contained.
- Crop behavior.
- Color treatment.
- Relationship to text.
- Whether imagery leads or supports.

If image generation is used, direct it according to the page art direction rather than generating generic "website hero" imagery.

## 8. Composition patterns worth considering

Use intentionally, not randomly:

- Editorial split with unequal columns.
- Strong vertical spine.
- Full-bleed image interrupted by type.
- Type-led hero with small supporting media.
- Asymmetric modular grid.
- Dense catalog/index.
- Long-form editorial rhythm.
- Poster-like first viewport.
- Horizontal narrative strip.
- Layered foreground/background composition.
- Framed technical/specification sheet.

The point is not novelty; it is choosing a composition whose geometry fits the content and brand.

## 9. Responsive translation

When translating to mobile, preserve the thesis rather than the geometry.

Examples:

- An asymmetric desktop editorial layout may become a strongly ordered typographic sequence on mobile.
- A desktop image-text overlap may become a cropped full-width image with type breaking its edge.
- A dense catalog may retain density through horizontal scrollers or compact lists rather than turning every row into a giant card.

## 10. Distinctiveness test

Before finalizing, ask:

- If all copy were replaced with lorem ipsum, would the page still have a recognizable identity?
- If the accent color were removed, would hierarchy and composition still work?
- Could this exact component arrangement belong to 100 unrelated AI-generated SaaS sites?
- Is there at least one deliberate composition or typographic decision that could be described as specific to this product?

If the answer indicates generic output, revisit the art direction before adding polish.
