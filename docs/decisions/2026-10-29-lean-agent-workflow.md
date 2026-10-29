# Lighter agent workflow and conditional MariaDB CI

- Date: 2026-10-29
- Approved by: Michael, October 29, 2026 ("yes" to moving MariaDB to nightly with money and booking PRs still on both databases)
- Choice: every PR tests on MySQL 8.4 (production); MariaDB 11.8 runs nightly, on manual runs, on critical-path changes and on PRs labelled `full-ci`. Agents start from `docs/HANDOFF.md`; decisions are one file each in `docs/decisions/`; the old log is archived.
- Why: token and CI cost per PR had grown with the docs agents loaded and with conflicts on the shared decision log. Production is MySQL; MariaDB remains supported (Spec Section 2).
