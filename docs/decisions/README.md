# Decisions, one file each

Record a decision only when it is a real choice someone later would need to know: architecture, data model, money, security, tenant isolation, integrations, hosting. Do **not** record per-screen display or wording choices; the pull request description is enough for those.

- File name: `YYYY-MM-DD-short-slug.md`, for example `2026-11-02-ota-channel-deferred.md`.
- Keep it under 15 lines:

```markdown
# <Decision in one line>

- Date: 2026-11-02
- Approved by: <Michael, in his own words / engineering choice>
- Choice: <what was chosen>
- Why: <one or two sentences, with the spec section>
- Options considered: <short list, optional>
```

Money, pricing, tax, fees, refunds, Stripe, legal disclosure and data retention need Michael's explicit yes in his own words. Write the file only after he has said it.

Never edit `docs/DECISIONS.md` in a feature PR. It is a short summary that is refreshed occasionally from this folder.
