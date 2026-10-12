# Literals: running with no model, and what each model adds

Where the `literals` module needs a model, what each step up costs, and what
it buys. Not a decision record: for the reasoning see
[ADR-0040](0040-literals-probabilistic-lookup-of-exact-values.md)
and, for the same sort of accounting on the sibling module,
[model-call-budget.md](../../aim/adr/model-call-budget.md).

The short version: **literals works with no model at all, and the one model
it can use is a decision model.** Nothing is locked in: adding the finder is
a module install and a provider setting, not a rewrite.

Terms follow ADR-0040 ("The parts, settled"). The **finder** is the whole lookup (filter
by access, check the cache, ask the chooser, return `match`, `ambiguous` or
`none`). The **chooser** is the one model call inside it (a Decision API
`ChoiceQuestion` over each literal's key and gist).

## The ladder

| Step | You ask with | Needs | Model calls per uncached question | Built |
| --- | --- | --- | --- | --- |
| 0. Exact key | A key you already know: `[literal:key]`, the `literals:lookup` tool with `key`, PHP | Nothing beyond `user` and `views` (the tool needs `tool`) | 0 | Yes |
| 0b. Search | Words from a name, key or gist | `literals.search` in `literals` (no AI) | 0 | Yes |
| 1. Decision model | A plain-language question | `drupal/ai` and one decision model | 1 (the chooser), 0 on a cache hit | Yes (`literals_finder`) |
| 2. Large pools | A plain-language question, thousands of literals | Unknown | Unknown | No, and not needed so far |

Step 0b is discovery without a model: a plain search (every
typed word must appear in the name, key or gist). It lists candidates for an
agent to pick from, then the pick is read by key. It finds only
what shares words with the gist; it does not decide.

Step 0 is a complete product on its own: nothing in the base module calls an
AI service. A site that has no decision model gets key lookups, tokens and
the tool by key; a question returns `none` (reason `no_backend`) rather than
a guess.

Step 2 is a placeholder. Measured up to 234 literals the chooser takes the
whole menu with no loss of accuracy and no extra latency (see
[how-it-works.md](how-it-works.md)). If a real pool outgrows that, the
candidates are a staged choice (group, then literal: two sequential calls,
still only `drupal/ai`) or a vector index (Search API, `ai_search`, a vector
DB provider). Neither is built.

## Where a model is called, and the removed embedding gate

One model call in the module (the chooser), plus model-backed Guardrails at
save when `literals_finder` is on. The table of what each is sent and never
sent is in [how-it-works.md](how-it-works.md), "Where the models are called".
An embedding gate in front of the chooser was built and removed on
2026-10-05; why is in the same document, "Why there is no embedding gate".

## What is known per call

| Call | Model | Time | Source |
| --- | --- | --- | --- |
| Chooser over the full menu, 9 literals | Hosted Jev | 0.3 s mean per query | `literals:eval` |
| Chooser over the full menu, 234 literals | Hosted Jev | 0.4 s mean per query | `literals:eval` |
| Typed choice, small prompt | Local `tev1:4b`, CPU-only laptop | 10 to 14 s | ADR-0038 |

Not measured: pools past 234, a local decision model on the chooser, a
second language, and the share of real questions each outcome gets in live
traffic.

## When to move up a step

| Move | Signal | What it adds |
| --- | --- | --- |
| 0 to 1 | Callers arrive with questions, not keys (a chat assistant, a form pre-fill) | A decision model and the finder |
| 1 to 2 | The chooser's accuracy or latency degrades as the pool grows (measure first) | Staged choice or a vector index |

## Data exposure by step

The chooser never receives a value, only each candidate's key and gist, plus
the context line. Candidates are filtered by the asker's view access first,
so a gist the asker cannot see is never in any menu.

| Step | Leaves the building | Stays in |
| --- | --- | --- |
| 0 | Nothing | Everything |
| 1 | The question, the context, and key plus gist of the candidates the asker may see, to the chooser | Values |

Hosted Jev is a temporary deviation recorded in ADR-0021's 2026-10-02
addendum and fits synthetic or demo data only. A local decision model swaps
in as a settings change.

## Considered, not built: stacked questions in the chooser call

The Decision API takes a keyed array of questions, and `LiteralChooser`
sends only one (`choice`). More questions can ride in the same call, so they
cost no extra round trip. Candidates:

- `one_shot` (boolean): is this one self-contained lookup, or does it need
  follow-up or several values? A `false` returns `none` with reason
  `not_one_shot`, leaving the caller to handle it.
- `asks_for_value` (boolean): is the question asking for a stored value at
  all, or is it small talk? Would cut false matches on chat.
- `sensitive` (boolean): does it ask for something secret-like? Advisory
  only. The real gate stays `LiteralVisibility`, never the model.

Each boolean is a gate that runs before the `choice` margin and threshold
logic and short-circuits to `none`; it does not change how `choice`
probabilities are read. Start with `one_shot`, and add the others only if
`eval/gold.seed.yml` shows false matches they would catch. Unmeasured: the
extra questions may shift `choice` probabilities, so re-run `literals:eval`
before trusting the thresholds.

## What is built today

See [ADR-0040](0040-literals-probabilistic-lookup-of-exact-values.md) "What is
built" and [CLAUDE.md](../CLAUDE.md). The one access rule the finder must
keep: filter by `LiteralVisibility::visibleFlags($account)` before the
chooser sees any gist.

## Jev limits that shape the menu (TypeSafe docs, read 2026-10-06)

- A Choice takes up to 255 options. Per request, 64k tokens for state plus
  all questions, and 32k for state plus the single longest question. The
  whole menu is one question here, so about 32k is the ceiling. Input costs
  $42 per billion tokens (output is free).
- Option descriptions may be strings or objects (`{what, not_for,
  examples}`, field names free); drupal/ai's `ChoiceQuestion` passes both.
  Not used: a plain string tested as well as an alias convention needs.
- Jev 1.13 leans toward the first option, loses accuracy when similar
  options have long descriptions, and reads negation literally. The docs say
  keep descriptions concise; the gist cap stays 255.
- Open models are far tighter (Ollaya docs): `laya:en` takes 512 tokens for
  state plus questions, about 125 options; `laya:multilingual` 1,024 tokens,
  about 250. A small local model would need a per-send gist budget or the
  menu split into pages (parallel questions in one request on Jev, one
  request per page locally). Not built; measure a real local model first.
