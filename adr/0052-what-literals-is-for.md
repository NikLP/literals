# ADR-0052: What literals is for, and what it is not

**Status:** Proposed 2026-10-06 - a decision record from a design discussion.
Decision 5 (resolvers return a pair) was built 2026-10-07: `ResolvedLiteral`
(value, label, kind), `resolveItem()`, `LiteralReader::readItem()`; the rest
is unbuilt. Narrows the claims in [ADR-0040](0040-literals-probabilistic-lookup-of-exact-values.md)
and builds on [ADR-0047](0047-literal-candidates-and-tracked-gists.md) and
[ADR-0049](../../aim/adr/0049-finder-family-parked.md).
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
3. **Build the gate first, in aim, and measure it** (see the 2026-10-08
   addendum: the finder is the gate; a pre-gate is optional). A one-question Jev
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

## Addendum 2026-10-08: how aim uses literals, and the answer modes

Decisions from a design discussion; none built. Where they differ from
Decision 3 above (a separate one-question classifier built first), this
addendum wins.

1. **Convert-a-fact retires the fact.** Promoting an extracted one-shot fact
   (an exact value) to a literal supersedes the fact through the existing
   non-destructive `superseded_by` edge (see ADR-0040 section 10 for the
   short form and the `retired`/`expires` dependency). The literal's gist is
   then the only description of the thing; the value lives once, in the
   literal. Facts that merely mention a literal in a longer sentence stay
   facts and carry `[literal:key]` (or `[literal:key:link]`) so the value is
   never stale; `aim` replaces the token at recall time as the viewing
   account, in code it controls, so no text-format filter or page cache is
   involved. **Tokens in body copy are dropped** (a text-format filter would
   have to pass cache contexts or risk serving a restricted value to the
   wrong viewer; not worth it for a rarely used feature).
2. **Two ways to show a literal in recall; build the first, the second can
   sit beside it.**
   - *Finder as a live source (preferred):* `aim_recall` asks the finder as a
     second source and returns the literal as a fact-shaped live result
     (value resolved for the viewer). Nothing is stored in `aim`, so the
     meaning is stored once. The large model is then exactly as exposed to
     the finder as it is to vector recall: both are probabilistic
     retrievers. The finder's failure mode is `none` (0 wrong-confident
     measured), not a wrong value.
   - *Pointer fact with a tracked gist:* a fact with no text of its own whose
     index entry is computed from the literal's gist (the ADR-0047/0053
     tracked-gist idea), reindexed when the literal changes. Only worth
     building if the live source proves insufficient. The two are not
     exclusive; if both run, de-duplicate by literal key.
3. **The finder is the gate.** It already answers match, ambiguous or none
   in about 0.3 s. A match goes down the one-shot path; anything else falls
   through to normal recall and the large model. A separate classifier is
   not needed to decide *whether a literal fits*.
4. **Optional pre-gate.** Running the finder on every query sends the whole
   menu to the decision model (about 4,000 tokens at 200 literals) even for
   questions that are clearly not lookups. A tiny "is this a lookup?"
   question with no menu in its prompt can run first. Its failures are safe
   (a false "no" is the status quo; a false "yes" costs one finder call), so
   it is a switchable setting, kept only if measured to pay (what share of
   traffic it removes, against its own latency).
5. **Two answer modes, a setting.**
   - *Mode 1, literal only:* the reply is the fixed text and link; no large
     model, lowest cost, works with no large model at all, and the value
     never reaches a model.
   - *Mode 2, literal plus smoothing:* the question and the resolved literal
     go to the large model for a natural reply.
   - *Escalation:* mode 1 can show a "think harder" control that re-runs the
     question through mode 2 or the full recall path when the visitor says
     the literal is wrong.
6. **Audience decides what may reach a hosted model.** Default: mode 2 only
   for public (anonymous) values; authenticated and restricted values stay
   mode 1, or go to a local model. This is a trade-off accepted until local
   reasoning models are available, not a settled principle; revisit with
   experience. It assumes aim's standing rule that it is not for classified
   data (aim CLAUDE.md, Scope).
7. **Copy controls are front-end work.** The chat items already carry value,
   label and kind, so a "copy value" / "copy link" button shown by role or
   audience needs nothing new in `literals`.
8. **Small prerequisite in `literals`:** a helper that turns a finder result
   into resolved items (`resolveItem()` with the empty-value downgrade to
   none); the chat responder and the tool both repeat that loop today.

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

- Does the gate (decision 3) save anything measurable? Setup-dependent
  since 2026-10-09: with the assistant calling the lookup tool itself, the
  finder runs only on lookups, so the pre-gate (addendum point 4) and this
  measurement apply to a setup that asks the finder on every question.
  Both stay in aim's TODO.md for later.
- Does a menu and page provider keep gist coverage high enough to matter?
- Annotation or literal: one type with a resolver, or two?
