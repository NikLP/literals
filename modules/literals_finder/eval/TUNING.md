# Tuning the chooser thresholds

Note for a future tuning skill. Nothing here is built yet.

## The problem

`match_threshold` and `choice_margin` are numbers with no feedback. The
right values depend on the site's literals (pool size, how alike the gists
are, how the questions are phrased), so there is no universal setting.
Tune per site, against that site's own data.

## What the values mean

- `match_threshold`: how sure the model must be of its top pick before the
  finder returns it. Below it, the answer is `none` (`low_confidence`).
- `choice_margin`: how far the top pick must lead the runner-up. Under it,
  the question is `ambiguous`.

## Method

1. Write a gold set for the site: real questions, each with the literal
   key it should find (or none). Keep a blind set apart from the one used
   to tune. See the `gold.*.yml` files here for the format.
2. Run `drush literals:eval` and record, per question, the top
   probability, the runner-up and whether the pick was right.
3. Sweep `match_threshold` (about 0.3 to 0.8) and `choice_margin`. For each
   step report: right answers kept, wrong answers let through, right
   answers lost to `none` or `ambiguous`.
4. Expect a plateau: most steps change nothing, a few change several
   answers at once. Pick a value in the middle of a plateau, not on its edge.
5. Prefer fewer wrong-confident answers over fewer misses. A wrong match
   is worse than "I don't know" for a customer-facing tool.
6. Re-check on the blind set. Do not tune gists or facts to pass the set.

## What a shipped skill may and may not claim

The pools and gold sets here are synthetic (a made-up library, written by
Claude), so a threshold tuned on them is tuned to that library. They are
fixtures for the module's own regression runs and worked examples of the
method, never a basis for another site's settings. A real site's literals and
gold set must come from its own data: its real literals, and questions its
visitors actually ask (the `Find:` confidence logging in aim's TODO would
help collect them). Scores on Claude-written questions are also optimistic,
because real questions are messier. The skill ships the method and tooling
(eval with probabilities, the sweep, the "no threshold helps" verdict), not
our numbers.

## Open

- The sweep script does not exist yet; `literals:eval` should print each
  query's top, runner-up and none probabilities itself, so the sweep needs no
  `ai_log` scraping or tag change. The sweep should cover `choice_margin` too,
  flag likely stale gold labels, and repeat a run to check stability.
- The `Find:` watchdog line does not record scores, so an admin cannot
  watch real traffic for borderline cases (proposal: log top pick and
  runner-up there, values-free).
- Calibration is unproven: check that higher confidence really means
  more often right before trusting a threshold.

## First sweep (2026-10-18, subq-snap-preview, 90-literal pool seeded)

How it was done, to repeat by hand until a script exists:

- Probabilities are only available if `literals_choose` is in
  `ai_logging.settings:prompt_logging_tags` (it is not by default). Add it
  temporarily, seed the pool (`drush literals:pool load pool100`), run `drush literals:eval` on each gold set
  (note `ai_log` max id before and after), then read each call's
  `output_text` (`answers.choice.probabilities`) and join to the eval rows
  by question text. Revert the tag and `drush literals:pool clean pool100` afterwards.
- 311 chooser calls over all eight gold sets, margin fixed at 0.2.

Result (threshold: right answers served / wrong answers served / unanswerable
questions answered / right answers lost to none):

| Threshold | Right | Wrong | False pos. | Lost |
| --- | --- | --- | --- | --- |
| 0.50 (current) | 159 | 15 | 19 | 52 |
| 0.65 | 153 | 11 | 16 | 62 |
| 0.80 | 140 | 8 | 12 | 78 |
| 0.90 | 114 | 4 | 8 | 108 |

Reading: no plateau, no good threshold. Each 0.05 step above 0.5 removes
one or two wrong answers and costs about five right ones. The wrong
answers are mostly confident (0.9+, for example "parking nearby" to
`pl_parking`), so a threshold cannot reach them. Correct hits have a median
top probability of 0.94, minimum 0.50. Leave `match_threshold` at 0.5.

Caveat: many "wrong" rows are gold-set artifacts of the big pool (the
`stress` set expects `st_*` literals, and `pl_childrens_phone` is a fair
answer to a question written before the pool existed; several `none`
expectations are now answerable by a `pl_*` literal). Treat the wrong and
false-positive columns as an upper bound and re-label before using them as a
target. What remains is a gist and pool-overlap problem, not a threshold one.
