# BACKLOG

Open work on the `literals` modules, moved here 2026-10-22 from aim's
TODO.md and BACKLOG.md. Design reasoning and the full parked list are in
[ADR-0040](adr/0040-literals-probabilistic-lookup-of-exact-values.md)
("Deferred and pinned", "Open questions"); link to it, don't copy it here.
Work on aim's side (the chat gate, convert-a-fact, answer modes) stays in
aim's [TODO.md](../aim/TODO.md). Finished items are deleted (git history
keeps them).

## Next

- [ ] **Example phrasings per literal** (ADR-0040 "Deferred and pinned"):
      re-test against the in-gist `ALSO` convention, once a real miss
      calls for it.
- [ ] **A local finder, for latency and data handling.** Hosted Jev is
      demo-only. Re-measure a local candidate (ADR-0040 "A local decision
      model") on latency, accuracy and 50 to 500 options; local Jev latency
      on aim's gate is unmeasured.

## Wait for a trigger

- [ ] **Showing the literal key** (wait for a request; Nik 2026-10-21:
      `[literal:key]` tokens are not for general use). The key is hidden on
      the edit form (shown on add only); options are a collection list
      column or the token read-only in the sidebar.
