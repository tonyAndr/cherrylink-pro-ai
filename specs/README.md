# CherryLink Plugin Specs

Spec-driven development: write or update the spec before implementing. Each spec describes the intended behavior and acceptance criteria for a feature area in the plugin.

**API specs** (endpoints, data contracts, indexing, logging) live in the API repo at `../api/specs/`.

## Spec index

| Spec | Area | Status |
|---|---|---|
| [editor-ui.md](editor-ui.md) | Classic editor panel UX | stable |
| [gutenberg-sidebar.md](gutenberg-sidebar.md) | Gutenberg sidebar UX, build toolchain | stable |

## How to use specs

1. **Before implementing a feature:** read the relevant spec. If no spec exists, write one first.
2. **After implementing:** check off acceptance criteria. Commit the spec update alongside the code.
3. **When debugging:** the spec describes intended behavior — compare against actual.

## Spec format

```markdown
# Feature Name

## Purpose
One paragraph on why this exists.

## UI behavior
What the user sees and does.

## Acceptance criteria
- [ ] Testable condition 1
- [ ] Testable condition 2

## Non-obvious constraints
Edge cases, known limitations, deliberate tradeoffs.
```
