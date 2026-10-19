# ADR-0052: What literals is for, and what it is not

**Status:** Proposed 2026-10-06, rewritten 2026-10-11 as current state (the
2026-10-08 addendum is folded in; history in git). Decision 5 (resolvers
return a pair) is built: `ResolvedLiteral` (value, label, kind),
`resolveItem()`, `LiteralReader::readItem()`. Token replacement on recall and
the `aim_literal` tool are built; the rest is unbuilt. Narrows the claims in
[ADR-0040](0040-literals-probabilistic-lookup-of-exact-values.md) and relates
to [ADR-0049](../../aim/adr/0049-finder-family-parked.md).
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
- **The cost saving is real, and measured at the gate.** The finder (a
  cheap typed Jev question) runs ahead of the chat model, and a hit skips
  the hosted call (decision 3). A chat model that merely has `aim_literal`
  as a tool saves nothing, which is why the gate sits in front of it.

## Decision

1. **Position literals as a find-and-gate layer for things, not fields.** A
   literal is a thing a person would point at (a phone number, a login page,
   a "pay your council tax" entry point). A field is only where a value
   lives.
2. **Do not justify it by chat cost alone.** Four independent uses, in order
   of defensibility: correct-or-none answers; values kept out of prompts;
   per-viewer access at resolve time; non-chat consumers (tokens, form
   fields, MCP). The cost gate is a fifth, measured 2026-10-12 (decision 3); it is the
   weakest of the five reasons to adopt literals, not the strongest.
3. **The finder is the gate, ahead of the chat model.** Measured
   2026-10-12 (`drush aim:chatbot-eval`, numbers and charts in
   [how-it-works.md](how-it-works.md)): letting the chat model decide to call
   `aim_literal` cost two hosted calls plus the finder and still
   under-called the tool (20/51 on the 101-literal set, against the
   finder's 50/51 on the same questions). Putting the finder (a small
   decision model, Jev) first and handing the question to the chat model only
   on `none` or `ambiguous` used a fifth to a quarter of the hosted tokens
   at 101 literals, was the fastest, and kept per-viewer visibility. The
   prompt-stuffing alternative scaled with the pool (3x tokens at 101
   literals) and cannot serve two audiences. A hit skips the hosted model
   only; the Jev call stays, as it is the fuzzy match. Built: `AimGateProcessor`, a `ChatProcessor` in aim_chatbot (open points in aim's TODO.md). `aim_literal` stays
   for MCP and external agents, which have no gate in front of them. Not yet
   measured: local Jev latency.
4. **Avoid the second sitemap with a menu and page candidate provider**
   (the candidates idea in ADR-0040 "Deferred and pinned"): enumerate main-menu links and nodes
   flagged as entry points, label = menu title, value = path or entity,
   resolver does the access check, gist from the menu title. Nobody
   retypes the sitemap.
5. **Resolvers return a pair, not a string.** `{label, url}`, so a bare
   `/node/5` becomes a usable link. Literals supplies the data (the
   literal's name, or the entity title); the caller (chat, block, aim's
   gate) renders it. Built: `ResolvedLiteral` and `LiteralReader::readItem()`.
6. **Harvesting gists from Drupal metadata alone does not work.** Core
   metadata is too thin to describe things in the words people use, so a
   gist is written (or suggested from the token's description) by a person.
7. **Build no more literal-specific gist machinery for now**
   (aliases, pools, vector tier stay pinned). The finder stays as a
   demonstration: a typed decision model matches a question to an exact
   value with no vector DB, and no value reaches a prompt. It is not
   "better than search"; the right demo is a paraphrase with no word
   overlap ("how do I ring you" to "main switchboard number"), not a
   navigational query.

## How aim uses literals, and the answer modes

Built: `aim` replaces `[literal:key]` and `[literal:key:link]` in fact text
at recall as the viewing account (a fact naming an unreadable literal is
withheld whole), and the assistant calls `aim_literal`. Everything else here
is unbuilt design.

1. **Convert-a-fact retires the fact.** Promoting an extracted one-shot fact
   (an exact value) to a literal supersedes the fact through the existing
   non-destructive `superseded_by` edge. The literal's gist is then the only
   description of the thing; the value lives once, in the literal. Facts
   that merely mention a literal in a longer sentence stay facts and carry
   `[literal:key]`, so the value is never stale. **Tokens in body copy are
   dropped** (a text-format filter would have to pass cache contexts or risk
   serving a restricted value to the wrong viewer).
2. **Two ways to show a literal in recall, neither built.**
   - *Finder as a live source:* `aim_recall` asks the finder as a second
     source and returns the literal as a fact-shaped live result (value
     resolved for the viewer). Nothing stored in `aim`. The finder's failure
     mode is `none` (0 wrong-confident measured), not a wrong value.
   - *Pointer fact with a tracked gist:* a fact with no text of its own whose
     index entry is computed from the literal's gist. Only worth building if
     the live source proves insufficient. If both run, de-duplicate by
     literal key.
3. **Two answer modes, a setting (unbuilt).**
   - *Mode 1, literal only:* the reply is the fixed text and link; no large
     model, lowest cost, the value never reaches a model.
   - *Mode 2, literal plus smoothing:* the question and the resolved literal
     go to the large model for a natural reply.
   - *Escalation:* mode 1 can show a "think harder" control that re-runs the
     question through mode 2 or the full recall path.
4. **Visibility decides what may reach a hosted model.** Default: mode 2 only
   for unrestricted values; restricted values stay mode 1, or go to a local
   model. A trade-off accepted until local reasoning models are available,
   not a settled principle. It assumes aim's standing rule that it is not for
   classified data (aim CLAUDE.md, Scope).
5. **Copy controls are front-end work.** The chat items already carry value,
   label and kind, so a "copy value" / "copy link" button shown by role or
   restriction needs nothing new in `literals`.

## Consequences

- A short fixed list, or a few verbatim facts with consolidation opted out
  ([ADR-0020](../../aim/adr/0020-verbatim-facts-consolidation-opt-out.md)), covers the
  "well known but unavailable" case on a typical site without a finder. The
  finder only earns its keep when such values are numerous or restricted.
- The demo's literals ship as default content in the `aim_demo_library` recipe.

## Open questions

- Gate (decision 3): answered 2026-10-12 for hosted tokens, time and
  visibility. Open: local Jev latency, and whether a Jev-written sentence
  around the value is worth one more in-house call.
- Does a menu and page provider keep gist coverage high enough to matter?
