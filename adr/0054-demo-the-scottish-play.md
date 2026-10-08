# ADR-0054: Demoing literals - "break a leg" and the Scottish play

**Status:** Proposed 2026-10-08 - a demo plan, nothing built (no seed data,
no script). For delivery in Scotland: DrupalCon 2027 or a Drupal camp in
early 2027.
**Date:** 2026-10-08

## Context

The point to land with a Drupal audience: **a model can act on a thing it
is never told.** Literals keep an exact value on the site and show a model
only a key and a gist (a short description). The model chooses by gist;
the site then resolves the value for the viewer. The value does not cross
the site boundary to the model.

The audience will ask the fair question: if only metadata leaves the site,
does it do any work? Theater supplies both halves of the answer.

## The two metaphors

- **"Break a leg."** The metadata does work, so the performance happens.
  What crosses the boundary (a key and a gist) is enough for the model to
  pick the right item. Saying "break a leg" is wishing success by
  indirection, and the wish still lands.
- **"The Scottish play."** Actors avoid saying the name of the play in a
  theater. They say "the Scottish play" and everyone knows which one. Here
  that is literal: a literal with key `scottish_play`, gist "The Scottish
  play" (and nothing more that gives it away), value "Macbeth". The model
  is shown the key and the gist and picks it. The reply that says
  "Macbeth" comes from the site, resolved after the choice. The word
  never appears in anything the model was sent.

Together: the indirection is not a loss of function, and it is also the
privacy property.

## Demo script (about three minutes)

1. **Set up the superstition.** One slide: "In a theater you do not say the
   name." Show the literal in the admin list: key, gist, and the value
   column (visible to the presenter, who has the permission).
2. **Ask without saying it.** In the chat box, as a visitor: "What is that
   Shakespearean play about the ambitious Thane and the witches?" The
   visitor must not type "Macbeth", or the claim is spoiled for the
   question.
3. **Show what the model saw.** The decision request: the question, a menu
   of keys and gists, and the choice `scottish_play`. No value anywhere in
   it. Show the actual request (provider log or a small viewer), not a
   mock-up.
4. **Show where the answer came from.** The reply "Macbeth" is the
   resolved value; the audit line records key, audience, user and outcome
   (never the value).
5. **Break a leg.** The box office phone literal: the same flow gives a
   working `tel:` link, to show the metadata really does the
   job and not only the demo's party trick.
6. **Audience boundary.** Ask the same thing as an anonymous visitor
   against a restricted literal: the answer is "I don't have that". The
   model never saw the restricted item's name either, because access is
   filtered before the menu is built.
7. **Optional, mode 2.** If smoothing is built by then (see the ADR-0052
   addendum): the model writes a friendly reply around a *public* value,
   and say plainly that this is the case where the value does go to the
   model. Do not blur it.

## What to claim, and what not to

- **Claim:** the stored value is never sent to the decision model, only the
  key and the gist; access is checked before the model sees the menu; an
  unknown or low-confidence question gives "none", not a guess.
- **Do not claim** that nothing about the visitor leaves: the question
  itself goes to the decision model. The property is about the stored
  value, not about the question.
- **Do not claim** the gist is secret. It is sent. A gist that spells out
  the value ("the play called Macbeth") defeats the demo, so the seed set
  must be checked for gists that contain their own value (a cheap
  assertion in the seed script or a guardrail; ADR-0040 covers value
  checks at save).
- **Numbers**, if quoted: the finder's results (about 97% answerable hits,
  0 wrong-confident, about 0.3 s per query on hosted Jev) come from gold
  sets written by the module's author or an agent, not an independent
  blind set. Say so, or hold the numbers back until a set written by
  someone else exists (the open item in the handoff).

## What has to be built or checked

- A small theater seed set: `scottish_play` -> "Macbeth", plus a few
  neighbors (a box office phone number, box office hours, a venue page) so
  the choice is a real choice and the finder is not trivially right. Seed script alongside
  `demo/seed.php`, idempotent, with a cleanup.
- A way to show the decision request on screen (provider debug log or a
  viewer). Check what the Decision API logs by default and that nothing
  sensitive is revealed.
- A rehearsed fallback for a miss (the finder says "none"): turn it into
  the point, the system choosing not to guess, then rephrase.
- Local-only fallback if the venue network or the hosted provider fails
  (this site's temporary hosted Jev is synthetic data only, ADR-0021
  addendum; a local decision model has not been measured, ADR-0038).
- The public URL and widget access rules in the site's CLAUDE.md apply:
  the chat widget is closed to anonymous, so the demo user signs in or the
  tester access is opened deliberately and closed afterward.

## Open questions

- Is the Scottish-play line a closing joke or the main thread? (Leaning:
  open with it, return to it at the end.)
- Which venue and date; the talk length decides whether steps 6 and 7 stay.
- Whether to demo the aim-side story (a fact carrying `[literal:key]`,
  ADR-0052 addendum) in the same talk or keep this one to literals alone.
