# TESTS.md - test coverage in literals

What the suites cover and the gaps left. Updated 2026-10-10 after the
review's gaps were filled.

## What exists

84 base tests (unit, kernel, functional) plus 23 in the submodules.
`LiteralsKernelTestBase` installs the six types, grants `view literals` to
the anonymous and authenticated roles, makes users (`member`,
`restrictedViewer`, `admin`), and has `createLiteral()` and `createPage()`.

| Area | File | Covers |
| --- | --- | --- |
| Links | `Unit/ResolvedLiteralTest` | `href()` allows only http(s), `tel:` (digits and `+`) and `mailto:`; `javascript:`, `data:`, `//host` and relative paths give NULL |
| Access | `LiteralAccessTest` | `restricted` flag matrix, no view permission, drafts, `edit literals` covers create/update/delete, query alter |
| Reader | `LiteralReaderTest` | published only, indistinguishable misses, cache metadata, token cycles, audit lines on read/update/delete without values, `replaceTokens()` (Markdown label escaping, href encoding, no rescan of resolved values, withholding), `resolveFound()` |
| Resolvers | `LiteralResolversTest` | text (int, phone, email), url access, entity (label, no canonical page, deleted target), token (per account, anonymous viewer), field (validation, entity and field access, kind, cache tags) |
| Tokens | `LiteralTokenTest` | `[literal:key]` and `:link`, HTML escaping of link text, restricted flag, drafts, bubbling, token info follows saves |
| Key, Guardrails | `LiteralKeyAndGuardrailsTest` | key as ID: uniqueness (validation and primary key), machine-name pattern, immutable, reserved keys; gist/value rejection; value never reaches model guardrails |
| Search | `LiteralSearchTest` | name/key/gist match, all words, LIKE wildcards and backslash literal, access, limit |
| Moderation | `LiteralModerationTest` | with Content Moderation, a draft of a published literal is not served until published |
| Install | `LiteralInstallTest` | six types, settings and view, schema-valid config, nothing granted; `literals_base` grants; demo recipe adds eight, re-applying adds none; clean uninstall |
| Admin UI | `Functional/LiteralAdminTest` | admin pages 403/200, literal form (key from name, duplicate and reserved keys, resolver error, key locked on edit), type delete guard, list is editors-only and shows restricted literals, revisions (history page, revert, 403 for viewers) |
| Submodules | `literals_finder`, `literals_tool`, `literals_chat` | finder cache and outcomes, tool modes (through `literals.lookup`), chat responder |
| Gist Guardrails form | `literals_finder` `Functional/LiteralGistGuardrailsFormTest` | the shipped set's no-markup rule rejects a gist on add and edit, as an error on the gist field; nothing saved until the gist is plain |
| aim integration | aim's `Kernel/AimRecallLiteralTokensTest` | `recall()` reads tokens per viewer, withholds or redacts per `show_redacted_facts`, follows value changes, unpublishing and deletion |

The real chooser (Decision API) is covered only by `drush literals:eval`.
That is deliberate; do not try to mock it into a PHPUnit "pass".

## Gaps

- **aim integration**: `aim_literal` against `literals:lookup` has no
  PHPUnit test. Both are thin wrappers over the `literals.lookup` service
  (tested through `literals_tool`), and the demo's `preflight.js` smoke
  check asks `aim_literal` a question and compares the value.

## Not worth testing

- `ginContentFormRoutes()` (a static list).
- The chooser's judgment (use `drush literals:eval`).
- Cosmetic list-builder columns.

## Running

```bash
ddev exec "cd /var/www/html && SIMPLETEST_DB='sqlite://localhost/sites/default/files/literals-test.sqlite' SIMPLETEST_BASE_URL=http://localhost vendor/bin/phpunit -c web/core web/modules/custom/literals/tests"
ddev exec "cd /var/www/html && SIMPLETEST_DB='sqlite://localhost/sites/default/files/literals-test.sqlite' SIMPLETEST_BASE_URL=http://localhost vendor/bin/phpunit -c web/core web/modules/custom/literals/modules"
```

Run the two separately; a combined run was OOM-killed once on this laptop.
`SIMPLETEST_BASE_URL=http://localhost` works for the functional tests from
inside the web container.
