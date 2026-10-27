# Changelog fragments

Each pull request adds one file in this directory instead of editing the Unreleased section of [`docs/CHANGELOG.md`](../CHANGELOG.md).

Name it `<branch-or-slug>.md`. Use the branch name with `/` replaced by `-`, or a short slug. Branch `feature/120-waitlist` uses `feature-120-waitlist.md`.

The file is a Markdown list in the same style as `docs/CHANGELOG.md`:

```markdown
- What changed, in one or more sentences.
```

Do not add, remove, or reword bullets under `## Unreleased`. The paragraphs above that heading can change. CI runs `.github/scripts/check-changelog-unreleased.sh` and fails the pull request when the Unreleased section differs from the base branch.

These files stay under `docs/`. The deploy archive excludes `docs`, and `inode_cleanup` deletes a leftover `docs` directory inside a release. A fragment adds no inodes to a release.

A tagged release can append a version heading below Unreleased, copy these notes into that heading, and delete the fragment files. That edit has to leave the Unreleased section unchanged. The check rejects a pull request that moves or rewrites those bullets.
