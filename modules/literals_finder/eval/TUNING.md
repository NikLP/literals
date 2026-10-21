# Tuning the chooser thresholds

Note for a future tuning skill. The tooling is built (`drush literals:eval
--dump` and `drush literals:sweep`); the skill around it is not.

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
2. Run `drush literals:eval SET --dump=DIR/SET.json` for each gold set (the
   table shows each question's top, runner-up and none probabilities).
3. Run `drush literals:sweep DIR`. It replays the recorded scores through
   the chooser's own rule (`LiteralChooser::rule()`) at each
   `match_threshold` and `choice_margin` step, with no model calls, and
   reports right answers served, wrong answers served, unanswerable
   questions answered (false positives) and right answers lost to `none` or
   `ambiguous`. Its replay check must show every row reproducing its live
   verdict. It also flags likely stale gold labels and prints a calibration
   table; `--drop-stale` sweeps without the flagged rows. Re-label the
   flagged rows by hand (expectations only, never the question wording)
   before treating wrong or false-positive counts as a target.
4. Look for a plateau: a stretch where right answers do not move. Pick a
   value in it, not past its edge.
5. Prefer fewer wrong-confident answers over fewer misses. A wrong match
   is worse than "I don't know" for a customer-facing tool.
6. Check calibration (a higher top probability should mean more often
   right) and repeat the run once (`--against=DIR2`) to see which rows sit
   on a threshold and flip.
7. Re-check on the blind set. Do not tune gists or facts to pass the set.

## What a shipped skill may and may not claim

The pools and gold sets here are synthetic (a made-up library, written by
Claude), so a threshold tuned on them is tuned to that library. They are
fixtures for the module's own regression runs and worked examples of the
method, never a basis for another site's settings. A real site's literals and
gold set must come from its own data: its real literals, and questions its
visitors actually ask (the `Find:` line's scores, `log_verbose`, show
which real lookups were borderline). Scores on Claude-written questions are also optimistic,
because real questions are messier. The skill ships the method and tooling
(eval with probabilities, the sweep, the "no threshold helps" verdict), not
our numbers.

## Open

- Re-label the gold sets (proposed in the 2026-10-21 sweep below, not
  done), then re-run: until then every wrong and false-positive count here
  is an upper bound.
- Real traffic: a real site's questions, from the `Find:` line's scores
  (`log_verbose`), would test whether the calibration below holds outside
  Claude-written questions.

## Second sweep (2026-10-21, subq-snap-preview, pool100 seeded)

Commands: `drush literals:pool load pool100`, then `drush literals:eval
eval/gold.SET.yml --dump=/tmp/literals-eval/run1/SET.json` for all eight
sets, the same again into `run2`, `drush literals:sweep
/tmp/literals-eval/run1 --against=/tmp/literals-eval/run2` (and again with
`--drop-stale`), `drush literals:pool clean pool100`. 312 chooser calls per
run (the five `as: evaltester` rows in `gold.restricted.yml` were skipped:
no such user here; earlier runs asked them silently as anonymous). Replay
check: 312 of 312. Margin 0.2.

| Threshold | Right | Wrong | False pos. | Lost | Suspects set aside: Right | Wrong | False pos. | Lost |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 0.30 | 164 | 15 | 28 | 39 | 164 | 1 | 18 | 2 |
| 0.50 (current) | 164 | 11 | 22 | 43 | 164 | 0 | 12 | 3 |
| 0.55 | 164 | 11 | 19 | 43 | 164 | 0 | 9 | 3 |
| 0.65 | 160 | 9 | 17 | 49 | 160 | 0 | 7 | 7 |
| 0.80 | 148 | 7 | 12 | 63 | 148 | 0 | 2 | 19 |
| 0.90 | 124 | 4 | 7 | 90 | 124 | 0 | 0 | 43 |

Stale-label suspects (61 rows):

- 50 questions expect only a key the asker cannot see, so they are lost or
  wrong at every setting: `opening_hours` x18 (deleted 2026-10-09; only
  `gold.blind.yml` was re-labeled then), `admin_user` x8 and `dimwit` x3
  (no such literal on the site or in any recipe), `my_account` x2
  (restricted, needs `as: 1`), `cfg_phone` x1 (no gist, so never offered),
  and 19 `st_*` (the stress pool was not loaded).
- 14 confident (0.8+) `pl_*` picks where the set expects none or an unseen
  key. Read by hand, 12 are fair answers the pool added (parking, the
  catalogue for book-availability questions x4, loan limit, printing
  price, audiobooks, story time x2, events, complaints). Two are real
  false positives: "loan period at the university library" to
  `pl_loan_period` 0.91 (wrong owner), and arguably "phone number for the
  city council" to `pl_council_phone` 0.92/0.96 (its gist is the council
  desk inside the library). Treat the set-aside columns as a lower bound on errors and
  the raw columns as an upper bound.

Calibration (top literal pick by its probability; suspects set aside in the
last column):

| Top p | Rows | Mean p | Pick right | Suspects set aside |
| --- | --- | --- | --- | --- |
| 0.50-0.60 | 6 | 0.54 | 33% | 2/5 |
| 0.60-0.70 | 12 | 0.65 | 58% | 7/10 |
| 0.70-0.80 | 14 | 0.74 | 64% | 9/13 |
| 0.80-0.90 | 35 | 0.86 | 77% | 27/29 (93%) |
| 0.90-0.95 | 48 | 0.93 | 90% | 43/43 |
| 0.95-0.99 | 78 | 0.97 | 92% | 72/72 |

Stability: of 312 questions in both runs, one changed verdict ("phone #":
`main_phone` 0.47, a miss, then 0.54, a hit) and one changed top pick (on a
row where none won anyway). With the same top pick, the probability moved by
median 0.000, max 0.068. One run is enough on this model; only rows near the
threshold flip.

Reading, against the 2026-10-18 finding:

- **`match_threshold` stays at 0.5.** Not disproved. Right answers are flat
  from 0.30 to 0.55 (164 to 165), so there is a plateau in right answers;
  0.5 is inside it. 0.55 removes three more false positives for nothing
  ("how do I renew a book" to `login` 0.51 in two sets, "call" to
  `main_phone` 0.54), but three rows, two of them one question, on a
  synthetic library is not a reason to move. Above 0.55 each step costs
  right answers again.
- **`choice_margin` is inert here.** 0.00 to 0.25 change nothing; 0.30+
  loses one or two right answers. With a top pick of 0.5 or more, a rival
  within the margin is rare.
- **"The wrong answers are confident ones" is revised.** The confident
  (0.8+) wrong answers and false positives were almost all gold-label
  artifacts. With the suspects set aside there are no wrong answers at 0.4
  and up, picks at 0.9+ are right 115 of 115 times (about 115 of 118 once
  the real false positives among the suspects count), and the remaining false
  positives (12 at 0.5) are mostly mid-confidence wrong-owner or near-topic
  questions ("reset my bank password" to `password_reset` 0.81, "sign in to
  my email" to `login` 0.78, renewing a book to `login`). A threshold can
  reach those, at one or two right answers each above 0.55: a policy choice
  (how much a wrong value costs), not a tuning result.
- Calibration holds: higher confidence means more often right. Below 0.8,
  the hit rate (40% to 70%) runs below the mean probability, so the model is
  somewhat overconfident there. The bins hold few rows.

## First sweep (2026-10-18, subq-snap-preview, 90-literal pool seeded)

Done by hand from `ai_log` (`answers.choice.probabilities` joined to the eval
rows by question text), 311 chooser calls over all eight gold sets, margin
fixed at 0.2. The 2026-10-21 tools replace that method.

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
