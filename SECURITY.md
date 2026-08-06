# Security Policy

## Supported versions

The latest tagged release is supported. This package is small and has no
long-term support branches.

## Reporting a vulnerability

**Please do not open a public issue for a security problem.**

Report it through GitHub's private vulnerability reporting:
<https://github.com/jamesgifford/hold/security/advisories/new>

Please include the package version, the Laravel and PHP versions, and enough
detail to reproduce.

## Scope worth noting

This package exposes a **public, unauthenticated, CSRF-exempt** endpoint —
`POST /{prefix}/signup` — which is reachable while a hold is active, and a
signed `/{prefix}/preview` route that sets a bypass cookie. Those are the parts
most worth scrutiny. The signup endpoint is deliberately quiet: honeypot hits,
rate-limited requests and duplicate addresses all return the same response as a
genuine signup, so it never reveals whether an address is on the list.
