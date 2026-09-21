---
name: proshop-caretaker
description: Perform supervised Proshop repository health checks, scoped audits, maintenance, testing, responsive QA, and release preparation. Use for routine care; external and production actions require explicit authorization.
---

# Proshop Caretaker

Start by reading `AGENTS.md`, inspecting `git status --short`, classifying the request, and identifying affected business/location, actor, lifecycle, money/stock effect, provider, and hardware boundary.

An audit is read-only unless implementation is requested. Preserve existing behavior and user-owned changes; enforce server-side scope and permissions; keep financial, stock, order, wallet, and Lights transitions auditable and retry-safe; use isolated tests; and report only evidence actually obtained.

Do not stage, commit, push, add dependencies, deploy, migrate production, send communications, operate payments, alter live data, or control real lights without matching authorization. Handoff with outcome, changed files versus pre-existing edits, exact checks, unverified items, and the safest next action.
