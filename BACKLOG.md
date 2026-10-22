# BACKLOG

Open work on the `literals` modules, moved here 2026-10-22 from aim's
TODO.md and BACKLOG.md. Design reasoning and the full parked list are in
[ADR-0040](adr/0040-literals-probabilistic-lookup-of-exact-values.md)
("Deferred and pinned", "Open questions"); link to it, don't copy it here.
Work on aim's side (the chat gate, convert-a-fact, answer modes) stays in
aim's [TODO.md](../aim/TODO.md). Finished items are deleted (git history
keeps them).

## Next

- [ ] **Gist overlap check.** The `login`, `password_reset` and `my_account`
      gists overlap ("where do I log in", "what I've borrowed"; blind set
      2026-10-12: 16 of 22 hits, 2 wrong-confident). Do NOT reword gists to
      pass the set (feedback rule). Build a general check (warn when two
      gists share a phrase), designed without looking at those failures and
      measured on a fresh set; re-check `chooser_context` and thresholds.
      The set is Claude-drafted, so not fully independent.
- [ ] **Example phrasings per literal** (ADR-0040 "Deferred and pinned"):
      re-test against the in-gist `ALSO` convention after the gist overlap
      check.
- [ ] **A local finder, for latency and data handling.** Hosted Jev is
      demo-only. Re-measure a local candidate (ADR-0040 "A local decision
      model") on latency, accuracy and 50 to 500 options; local Jev latency
      on aim's gate is unmeasured.

## Before a drupal.org release

- [ ] **Finder test exclusions.** The schema-checker exclusions in
      `literals_finder`'s tests may be removable now the
      `literals_max_length` guardrail uses `scan_all_user_messages`.

## Wait for a trigger

- [ ] **Showing the literal key** (wait for a request; Nik 2026-10-21:
      `[literal:key]` tokens are not for general use). The key is hidden on
      the edit form (shown on add only); options are a collection list
      column or the token read-only in the sidebar.
