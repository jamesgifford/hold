# CLAUDE.md

Instructions for any Claude Code session working in this repository.

## Git: never commit or push automatically

Do not run `git commit` or `git push` as a side effect of finishing other
work — implementing a fix, running a cleanup pass, executing a plan. James
reviews and commits/pushes himself.

- Only commit and/or push when his message, in that turn, explicitly asks
  for it (e.g. "commit this", "push it", "commit and push").
- Otherwise, leave the working tree with the changes made and say so plainly
  — do not commit just because a task is complete.
- When he does explicitly ask: commit directly on `main`. Do not create a
  feature branch or open a pull request — this is a solo package, its whole
  history is direct-to-main, and the Tests workflow only triggers on
  push-to-`main` or PR-to-`main`, so a branch push runs no CI at all.
- Still confirm before force-pushing or rewriting published history, even
  when asked to commit/push.

This overrides any default "commit when the task looks done" instinct.
