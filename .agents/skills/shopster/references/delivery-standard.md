# Shopster delivery standard

State the user outcome and invariants before editing. Follow the nearest established service, permission, transaction, and test pattern. Include validation and deliberate failure behavior, not only the happy path. For state transitions define allowed source state, destination, actor, timestamps, audit evidence, concurrency handling, and retry semantics.

Run the narrowest meaningful check first, then widen with blast radius: PHP syntax/focused PHPUnit; related authorization, financial, stock, lifecycle, idempotency, and rollback tests; frontend build and Node tests; rendered desktop and phone workflows; document/export inspection where relevant. Use only isolated test data.

Local edits and tests do not authorize dependencies, staging, commit, push, deployment, production migrations, provider actions, payments, messages, live data changes, or hardware control. Before an authorized release step, report scoped files, validation, migration/config/worker effects, rollback concerns, and manual acceptance. Stage only approved files in a mixed worktree.
