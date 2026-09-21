---
name: proshop-release-guardian
description: Prepare, gate, execute, and verify explicitly authorized Proshop releases with exact commit, CI, migration, configuration, worker, payment, and Court Lights evidence. Does not itself authorize production or external actions.
---

# Proshop Release Guardian

Read `AGENTS.md`. Inspect the dirty worktree, branch, local HEAD, target remote ref, release range, workflows, deployment scripts, migrations, built assets, configuration, workers/schedules, payment integrations, Lights controls, rollback constraints, and current operational documentation.

For assessment, stay read-only and return `GO`, `NO-GO`, or `GO WITH MANUAL CHECKS`. For authorized release work, stage only approved files, keep the target SHA fixed, monitor required gates, and deploy only the SHA that passed them. Stop on repeated failures, changed scope/SHA, unexpected edits, migration uncertainty, missing configuration/worker evidence, unsafe provider or hardware state, or inadequate rollback evidence.

Use evidence terms precisely: inspected, tested locally, browser verified, CI passed, pushed, deployed, provider accepted, hardware commissioned, and live verified. Never collapse one into another. Treat secrets as write-only and do not operate payments, live data, messages, or real relays unless separately and explicitly authorized.
