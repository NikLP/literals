# ADR-0052: What literals is for, and what it is not

**Status:** Proposed 2026-10-06 - a decision record from a design discussion.
Decision 5 (resolvers return a pair) was built 2026-10-07: `ResolvedLiteral`
(value, label, kind), `resolveItem()`, `LiteralReader::readItem()`; the rest
is unbuilt. Narrows the claims in [ADR-0040](0040-literals-probabilistic-lookup-of-exact-values.md)
and builds on [ADR-0047](0047-literal-candidates-and-tracked-gists.md) and
[ADR-0050](../../aim/adr/0050-meaning-navigator.md).
**Date:** 2026-10-06

## Context

The question: is `literals` worth its curation cost, or is Search API simply
better? The test case was "I want to pay my council tax". Findings:

- **Literals has no fuzzy matching of its own.** The embedding gate was built
  and removed 2026-10-05. `literals_search` is plain keyword match (every
  word must appear). The fuzziness is the Decision API chooser (Jev)
  picking one ID from the whole visible menu, or "none" or "ambiguous".
- **For navigational queries Search API wins.** A bill payer wants a landing
  page, search returns it, and nobody wrote a gist. Hand-writing a literal
  per popular destination is a second sitemap.
- **What search cannot do:** return one exact value (phone, token, link) or
  an honest "none" instead of nearest neighbours; resolve per viewer with an
  access check; serve consumers that are not chat (a form field asking for
  "our phone number", a `[literal:key]` token, an MCP tool).
- **The model never sees values.** The chooser sees gists and IDs only; the
  value is resolved afterward. A hosted chat model doing recall would see it.
- **The cost-saving claim is unmeasured.** The intended design is a cheap
  typed Jev question ("is this one-shot?") routing simple queries to a fixed
  answer before recall or a large model. That gate does not exist. Today
  literals is a tool the chat model chooses to call, which saves nothing.

## Decision

1. **Position literals as a find-and-gate layer for things, not fields.** A
   literal is a thing a person would point at (a phone number, a login page,
   a "pay your council tax" entry point). A field is only where a value
   lives, and annotations on fields describe storage, not findable things.
2. **Do not justify it by chat cost alone.** Four independent uses, in order
   of defensibility: correct-or-none answers; values kept out of prompts;
   per-viewer access at resolve time; non-chat consumers (tokens, form
   fields, MCP). The cost gate is a fifth, to be measured, not assumed.
3. **Build the gate first, in aim, and measure it.** A one-question Jev
   classifier ("one-shot lookup?") ahead of recall. If it does not save
   cost or latency against the chat model doing the same lookup, the
   finder's cost story is dropped and the other four uses stand alone.
4. **Avoid the second sitemap with a menu and page candidate provider**
   (extends ADR-0047's candidates): enumerate main-menu links and nodes
   flagged as entry points, label = menu title, value = path or entity,
   resolver does the access check, gist tracked from the target's
   annotation with the menu title as fallback. Nobody retypes the sitemap.
5. **Resolvers return a pair, not a string.** `{label, url}`, so a bare
   `/node/5` becomes a usable link. Literals supplies the data (the
   literal's name, or the entity title); the caller (chat, block, aim's
   gate) renders it. Not yet checked: what `LiteralReader` returns today.
6. **Harvesting gists from Drupal metadata alone does not work.** Core
   metadata is too thin to describe things in the words people use, which
   is why Annotations exists. Gists for entry points come from annotations
   on points of interest (menus, key pages), not on fields. Until
   annotations are built (ADR-0024 is blocked on the ADR-0002 gate and an
   Annotations write path), this is design only.
7. **Build no more literal-specific gist machinery until then**
   (aliases, pools, vector tier stay pinned). The finder stays as a
   demonstration: a typed decision model matches a question to an exact
   value with no vector DB, and no value reaches a prompt. It is not
   "better than search"; the right demo is a paraphrase with no word
   overlap ("how do I ring you" to "main switchboard number"), not a
   navigational query.

## Consequences

- Literals may dissolve into annotations on things plus a resolve step and
  an audience rule. Whether that needs its own entity type or just an
  annotation with a resolver is settled once annotations exist.
- A short fixed list, or a few verbatim facts with consolidation opted out
  ([ADR-0020](../../aim/adr/0020-verbatim-facts-consolidation-opt-out.md)), covers the
  "well known but unavailable" case on a typical site without a finder. The
  finder only earns its keep when such values are numerous or restricted.
- The live site has no literals; a demo needs a seeded set (`demo/seed.php`).

## Open questions

- Does the gate (decision 3) save anything measurable?
- Does a menu and page provider keep gist coverage high enough to matter?
- Annotation or literal: one type with a resolver, or two?
