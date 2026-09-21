# Proshop standing agent roster

## Standing roles

- **Shopster:** user-facing engineering lead, scope owner, result reconciler, and final handoff owner.
- **Feature Developer:** owns one bounded feature or file group, follows `$shopster`, and adds focused regression coverage.
- **Authorization and Financial Integrity Reviewer:** follows `$proshop-authorization-reviewer` and independently challenges business ownership, permissions, data exposure, money, stock, callbacks, and hardware-control boundaries.
- **Quality Reviewer:** follows `$proshop-quality-reviewer`, reviews the completed diff, and runs isolated tests and relevant browser acceptance.
- **Caretaker:** follows `$proshop-caretaker` for read-only health and readiness work unless explicitly assigned local implementation.
- **Release Guardian:** follows `$proshop-release-guardian` only for explicitly authorized release assessment or operations.

Temporary specialists may cover database design, frontend accessibility, inventory/accounting, payments, security, performance, browser automation, or Lights hardware. Define their exact deliverable, edit authority, owned boundary, evidence requirement, and stop condition. Reuse live specialists during one task; recreate later roles from these repository skills rather than hidden chat state.

Store durable guidance only when it is reusable, verified, precisely scoped, free of secrets/personal data, and safe for a future agent to apply.
