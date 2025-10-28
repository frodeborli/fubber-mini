# When to Use Mini

Modern PHP offers a spectrum of frameworks, from large, feature-rich ecosystems like **Laravel** and **Symfony** to small, explicit micro-frameworks.
**Mini** sits firmly at the minimalist end — built for developers who value control, transparency, and long-term maintainability over convention-driven abstraction.

This document explains when Mini makes sense — and why its strengths are often underestimated.

---

## Use Mini When You Want Explicitness Over Abstraction

Mini embraces PHP as it really is: a fast, capable, explicit language.
Routes are files, responses are simple functions, and there's no invisible lifecycle to reverse-engineer.

If you prefer:

* Knowing exactly what happens on each request
* Being able to debug production code without guessing which layer runs next
* Fixing things directly without breaking dependency injection graphs

Then Mini's *no-magic, no-surprise* approach will feel like home.

**Example:**
You can make a live `/ping` endpoint in one line:

```php
<?php respond_json('pong', ['Cache-Control' => 'no-cache']);
```

No routing YAML, controller boilerplate, or service container to configure.
You just write the logic.

---

## Use Mini When Operational Stability Matters

In frameworks that bootstrap large dependency trees on every request, a single typo can 500 the entire application.
Mini isolates failures naturally — one route, one file, one scope.

This isolation makes it ideal for:

* Systems that must stay online 24/7
* SaaS platforms where uptime trumps abstraction
* Hotfixes deployed under pressure (you can patch a single file safely)

Mini's simplicity isn't just aesthetic; it's operational resilience.

---

## Use Mini When You're Building for Decades, Not Releases

Framework ecosystems evolve — APIs deprecate, packages fall out of maintenance, and upgrade paths grow steep.
Mini's core principle is longevity: its dependencies are *PHP itself*.

If you expect your system to live 10–20 years (think CMS, wiki, or internal SaaS), Mini minimizes external risk.
Its small, explicit core can be maintained by any competent developer long after today's frameworks have changed or vanished.

---

## Use Mini When You Own the Architecture

Laravel and Symfony shine in environments where:

* Teams are large or fluid
* Convention consistency is more important than performance
* You need an ecosystem of ready-made components

Mini shines when:

* You have architectural clarity and discipline
* You want to keep total conceptual load low
* You prefer to *understand* rather than *inherit* your framework

It's not a replacement for Laravel — it's an alternative philosophy:
**Build less. Understand more. Depend on less.**

---

## Use Mini for Fast, Transparent, and Reliable Backends

Mini is particularly strong in:

* API backends and internal services
* CMS and content platforms
* Single-tenant or high-performance SaaS systems
* Admin dashboards and background task runners
* Educational or embedded environments where clarity matters

Its short learning curve and small runtime footprint make it ideal for systems where simplicity outlives fashion.

---

## Use Laravel/Symfony When You Need Ecosystem and Convention

If your organization:

* Relies on hiring from a broad talent pool
* Requires batteries-included features (queues, auth, ORM, migrations)
* Values standardized practices and documentation

Then a conventional framework like Laravel or Symfony remains a great choice.
They solve *coordination problems* across large teams.
Mini solves *complexity problems* across long time horizons.

---

## Summary

| Situation | Best Fit |
|-----------|----------|
| Rapid prototyping, large rotating teams | **Laravel / Symfony** |
| Long-term maintainability, small expert team | **Mini** |
| Performance-sensitive API or service | **Mini** |
| Plug-and-play integrations, rapid onboarding | **Laravel / Symfony** |
| Deep control, predictable behavior | **Mini** |

---

## Philosophy

> **Mini isn't anti-framework — it's pro-clarity.**
> It assumes you trust yourself more than your dependencies.
> It trades automation for autonomy, and complexity for durability.

When you value understanding your code more than inheriting someone else's conventions,
**Mini is not just sufficient — it's ideal.**
