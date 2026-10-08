# Literals: running with no model, and what each model adds

Where the `literals` module needs a model, what each step up costs, and what
it buys. Not a decision record: for the reasoning see
[ADR-0040](0040-literals-probabilistic-lookup-of-exact-values.md)
and, for the same sort of accounting on the sibling module,
[model-call-budget.md](../../aim/adr/model-call-budget.md).

The short version: **literals works with no model at all, and the one model
it can use is a decision model.** Nothing is locked in: adding the finder is
a module install and a provider setting, not a rewrite.

Terms follow ADR-0040 ("The finder and the chooser"). The **finder** is the whole lookup (filter
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

## An embedding gate was built and removed

On 2026-10-05 a gate sat in front of the chooser: embed the question,
compare it with stored gist vectors in PHP, and decide alone or shortlist
the top 5. It was removed the same day. With a decision model it added no
accuracy (identical hits, same correct-none rate, at 9 and at 234 literals)
and, past a few hundred literals, no speed (near-neighbours leave small
leads, so the model was needed anyway). On its own, without a decision
model, it was a shortlister, not a decider: at the safe margin it decided 17
of 29 answerable questions, never picked wrongly, but could not answer
"none" to near-topic questions ("email of the librarian" returned five
phone-ish candidates). It cost about 450 lines, two stored fields, a queue,
an embedding provider and three thresholds that drifted once. The numbers
are in [how-it-works.md](how-it-works.md); the code is in git history before
the removal. Revisit it only if menus pass a few hundred literals **and** a
measured need appears (a metered or slow decision model is the likeliest
reason).

## Where a model is called

| Place | What it does | Needs | When it runs |
| --- | --- | --- | --- |
| Chooser | Picks one literal (or `none`) from key plus gist | Decision model (hosted Jev here) | Step 1, uncached questions only |
| Guardrails | Checks gist and value text at save, deterministic guardrails only for the value | `drupal/ai` Guardrails (`literals_finder` provides the set) | At save, if `literals_finder` is enabled |

Not model calls: the exact-key lookup, the outcome cache, the access filter
and token replacement.

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
  only. The real gate stays `LiteralAudience::visibleTo()`, never the model.

Each boolean is a gate that runs before the `choice` margin and threshold
logic and short-circuits to `none`; it does not change how `choice`
probabilities are read. Start with `one_shot`, and add the others only if
`eval/gold.seed.yml` shows false matches they would catch. Unmeasured: the
extra questions may shift `choice` probabilities, so re-run `literals:eval`
before trusting the thresholds.

## What is built today

- Step 0: the `literal` entity, `literal_type` bundles, the four resolver
  plugins, the audience access rule (single checks, entity queries and
  Views), `literals.reader`, the `[literal:key]` token, the `literals:lookup`
  tool (`literals_tool`), and Guardrails at save.
- Step 1: `literals_finder`: the chooser with a site-wide or per-call
  context, the outcome cache, `literals:find` and `literals:eval`.
- The one access rule the finder must keep: filter by
  `LiteralAudience::visibleTo($account)` before the chooser sees any gist.

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
