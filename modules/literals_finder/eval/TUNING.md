# Tuning the chooser thresholds

Note for a future tuning skill. The tooling is built (`drush literals:eval
--dump`/`--check` and `drush literals:sweep`); the skill around it is not.

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
   to tune. See `gold.example.yml` here for the format.
2. Run `drush literals:eval SET --check` (no model calls): every expected key
   must exist on the site or in a pool file next to the set. Then run `drush literals:eval SET --dump=DIR/SET.json` for each gold set (the
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

The example set here is synthetic (a made-up library, written by Claude), so
a threshold tuned on it is tuned to that library: a worked example of the
format, never a basis for another site's settings. A real site's literals and
gold set must come from its own data: its real literals, and questions its
visitors actually ask (the `Find:` line's scores, `log_verbose`, show
which real lookups were borderline). Scores on Claude-written questions are also optimistic,
because real questions are messier. The skill ships the method and tooling
(eval with probabilities, the sweep, the "no threshold helps" verdict), not
our numbers.

## What a first sweep usually finds

Run on a synthetic library, most "wrong" answers were stale gold labels, not
model errors: keys deleted since the set was written, restricted literals
asked as anonymous, and questions a newer literal now answers fairly. Clean
labels before reading any threshold off the sweep. There the threshold mattered
little (a flat stretch of right answers either side of 0.5) and the margin
not at all; what remained was wrong-owner and near-topic questions at middling
confidence, a gist and policy question rather than a tuning one.

## Open

- Real traffic: a real site's questions, from the `Find:` line's scores
  (`log_verbose`), would test whether calibration holds outside
  Claude-written questions.
