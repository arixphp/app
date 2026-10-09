---
name: ArixPHP
description: Understated technical manual with jade accents and native CSS.
colors:
  light-bg: "oklch(0.974 0.005 220)"
  light-surface: "oklch(1 0 0)"
  light-text: "oklch(0.243 0.019 240)"
  light-muted: "oklch(0.452 0.031 234)"
  light-border: "oklch(0.888 0.014 230)"
  light-accent: "oklch(0.576 0.116 170)"
  light-accent-hover: "oklch(0.497 0.101 170)"
  dark-bg: "oklch(0.209 0.024 250)"
  dark-surface: "oklch(0.267 0.024 250)"
  dark-text: "oklch(0.951 0.01 232)"
  dark-muted: "oklch(0.851 0.026 234)"
  dark-border: "oklch(0.373 0.038 239)"
  dark-accent: "oklch(0.845 0.125 170)"
  dark-accent-hover: "oklch(0.914 0.095 172)"
typography:
  display:
    fontFamily: 'system-ui, "Segoe UI", Roboto, Oxygen, Ubuntu, Cantarell, Helvetica, Arial, "Helvetica Neue", sans-serif'
    fontSize: "clamp(2.4rem, 7vw, 4.8rem)"
    fontWeight: 700
    lineHeight: 1.1
    letterSpacing: "-0.02em"
  body:
    fontFamily: 'system-ui, "Segoe UI", Roboto, Oxygen, Ubuntu, Cantarell, Helvetica, Arial, "Helvetica Neue", sans-serif'
    fontSize: "1rem"
    lineHeight: 1.6
rounded:
  sm: "0.375rem"
  md: "0.5rem"
  lg: "0.8rem"
spacing:
  small: "0.5rem"
  normal: "1rem"
  component: "1.5rem"
  section: "4rem"
components:
  button-primary:
    backgroundColor: "{colors.light-accent}"
    textColor: "{colors.light-surface}"
    rounded: "{rounded.md}"
    padding: "0.5rem 1.25rem"
---

# Design System: ArixPHP

## Overview

**Creative North Star: "Understated technical manual"**

The demo preserves the visual language of Arix CSS: direct content, system
typography, and jade accents. The confirmed direction allows stronger contrast
without changing the CSS framework or adding dependencies or build steps.

**Key Characteristics:**
- Clear typographic hierarchy and readable content.
- Cool paper or ink backgrounds, depending on the theme.
- Understated components defined by borders.

## Colors

### Primary
Jade highlights links, focus, and actions. The frontmatter values reflect
the current OKLCH declarations in `styles/tokens.css`; the file also retains
hexadecimal fallbacks for older browsers.

### Neutral
Cool paper and white surfaces in light mode; ink and blue-toned surfaces in
dark mode. Text, secondary content, and borders use distinct semantic roles.
Approved contrast adjustments must maintain equivalence between themes.

## Typography

The system font family serves both headings and body text. Code examples
use the native monospace stack defined in the tokens.
The main heading has a maximum width of 15ch; paragraphs, 65ch.
The introduction uses 1.3rem and a maximum width of 48ch.

## Layout

The page has a 68rem container and 1.5rem padding. The header and navigation
use flex with wrapping; the main content has 4rem vertical spacing.
The spacing scale is based on 0.25rem increments.
The existing breakpoints are 40rem, 48rem, 64rem, and 80rem.

## Elevation & Depth

The current composition is flat: borders and tones separate regions.
Two subtle shadow tokens exist, but they are not applied to the current cards.

## Shapes

Thin borders and gently rounded corners. Fields and buttons use the medium
radius; cards use the large radius. Do not add ornamentation that competes
with the technical content.

## Components

### Buttons
Action links may use a jade background or an outlined variant. Hover
switches to the interaction accent; visible focus has a 2px outline.

### Cards / Containers
Cards with a 1px semantic border, 1.5rem padding, and no applied shadow.

### Inputs / Fields
Semantic surface, thin border, and medium radius; labels above the field.

### Navigation
Jade links grouped using flex with wrapping. The brand uses weight 800 and 1.5rem.

## Do's and Don'ts

### Do:
- **Do** preserve Arix CSS, its layers, and its semantic roles.
- **Do** check readability in light and dark modes when adjusting colors.
- **Do** keep the demo honest about the project's capabilities.

### Don't:
- **Don't** replace the CSS framework or introduce build tools.
- **Don't** add remote fonts for this refinement.
- **Don't** mistake understated borders for a requirement to enclose everything in cards.
