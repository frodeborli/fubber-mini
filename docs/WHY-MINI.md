# Why Choose Mini? A Thorough Discussion

Mini isn't trying to be Laravel. It's solving a different problem with a different philosophy.

This document addresses the real concerns developers have when choosing between Mini and established frameworks, with honest answers about trade-offs.

---

## The Core Thesis: Designed for Decades, Not Release Cycles

**Mini is designed for zero maintenance.**

Not "low maintenance" - **zero maintenance over 10-20 years**.

### Why This Is Possible

Mini wraps PHP's stable core APIs:

```php
fmt()->currency(19.99, 'EUR')           // → NumberFormatter (stable since PHP 5.3, 2009)
t("Hello {name}", ['name' => 'World'])  // → MessageFormatter (stable since PHP 5.3, 2009)
db()->query("SELECT * FROM users")      // → PDO (stable since PHP 5.1, 2005)
\Locale::setDefault('de_DE')            // → intl extension (stable since PHP 5.3, 2009)
```

**These APIs haven't changed in 15-20 years. Why would they change in the next 20?**

PHP's backward compatibility commitment is extraordinarily strong:
- `mysql_*` functions: 12 years from deprecation to removal
- `ereg_*` functions: 6 years from deprecation to removal
- PDO, intl, reflection: Never broken in 20 years

**Mini doesn't abstract PHP away - it provides convenient access to it.**

### Compare to Framework Churn

**Laravel over 10 years (2015-2025):**
- Version 5 → 6 → 7 → 8 → 9 → 10 → 11
- Each major version brings breaking changes
- Authentication system rewritten (5.x → 6.x)
- Mail backend replaced (SwiftMailer → Symfony Mailer)
- Query builder contracts changed
- Estimated upgrade time: 20-40 days total

**Why does Laravel change?**
- Complex abstractions evolve (Eloquent ORM, service container, facades)
- Feature additions require BC breaks
- "Improvements" to the developer experience
- Ecosystem coordination (packages must update)

**Mini over 10 years:**
- Same thin wrappers around stable PHP APIs
- No abstraction layer to maintain
- No feature churn (already feature-complete)
- Estimated maintenance: 0-2 days total

**Why doesn't Mini change?**
- Simple wrappers over stable APIs don't need updates
- Not chasing trends or adding features
- Direct PHP usage means PHP evolution benefits Mini automatically

### The Only Realistic Maintenance Scenarios

**1. Security vulnerability in Mini's code**
- Possible in any framework
- Mini's ~15,000 lines make auditing feasible
- Fix time: 2-4 hours for typical issues

**2. Catastrophic PHP BC break**
- Example: PHP removes intl extension or breaks PDO API
- This would break Laravel, Symfony, and the entire ecosystem
- Not a "Mini problem" - it's an "ecosystem is dead" problem
- Probability: Near zero (PHP's BC commitment is rock-solid)

**3. You want new features**
- This is enhancement, not maintenance
- And it's optional (Mini works as-is)

### Real-World Longevity

I've been building PHP frameworks for 24 years. Code I wrote in 2002 still runs in production today at a regional SaaS in Norway. I know where BC breaks happen and how to avoid them.

Mini is designed with that experience: wrap stable APIs, avoid clever abstractions, use timeless patterns (files, SQL, templates).

---

## Addressing Common Concerns

### "Single-developer project - what if you disappear?"

**The concern is backwards.**

**Question:** What's easier to maintain?
- 15,000 lines of well-documented, explicit PHP
- 500,000+ lines of abstracted framework + ecosystem packages

**Reality check:**
- Any competent PHP developer can read and understand Mini in a weekend
- The code IS the documentation (clear docblocks, obvious patterns, explicit behavior)
- No complex service provider boot process, no magic bindings, no hidden lifecycle

**Compare to Laravel:**
- Good luck finding where something actually happens without xdebug
- Service container bindings are opaque
- Middleware pipeline is complex
- Facade magic obscures real dependencies

**The real question:** Is it safer to depend on transparent, simple code or complex, abstracted code?

**When I'm unavailable in year 10:**
- Mini: Fork it, maintain it (15K lines, fully documented)
- Laravel: Upgrade or rewrite (can't realistically fork 500K+ lines)

**Business reality:** You're not maintaining either framework day-to-day. But if you NEED to (in year 15), Mini is feasible. Laravel isn't.

---

### "Unknown release cycle - no predictable LTS versions"

**True, but the premise is flawed.**

**Mini's stability model is different:**
- Not designed for frequent releases (stable wrappers don't change)
- Breaking changes are rare (small surface area)
- **CHANGE-LOG.md** documents every breaking change explicitly

**When stable wrappers do need changes:**
- They're typically edge cases in routing or configuration loading
- Not "we rewrote the authentication system" (Laravel 5→6)
- Not "we replaced the mail backend" (Laravel 6→7)

**Laravel's "advantage" is overstated:**
- Yes, LTS versions get 2 years of support
- But upgrades between major versions are painful
- Teams often stay stuck on old versions because upgrading isn't worth the effort
- BC breaks every 2 years is predictable, but it's predictably expensive

**Mini's approach:**
- Fewer breaking changes total (simple code is stable code)
- When breaks happen, they're documented and easy to fix
- No pressure to upgrade every 2 years

**Ask yourself:** Would you rather have "supported" BC breaks every 2 years, or rare, documented changes when actually necessary?

---

### "Uncertain long-term support - might be abandoned"

**Let's be direct.**

**Yes, Mini could be abandoned. So could Laravel.**

Laravel survives because:
- Taylor Otwell makes money from Forge/Vapor/Spark
- That revenue stream funds development
- If it stops, Laravel's pace would slow dramatically

Mini survives because:
- I run production systems on it that need to work in 10 years
- It's already "done" (feature-complete, not feature-incomplete)
- Low maintenance burden (doesn't fight PHP)

**But here's the key difference:**

**Mini is forkable:**
- 15,000 lines of transparent PHP
- Any competent developer can maintain it
- No complex build pipeline, no code generation
- The code IS the documentation

**Laravel is not forkable:**
- 500,000+ lines across framework + ecosystem
- Complex abstractions require deep understanding
- Coordinating ecosystem packages is a full-time job

**Honest assessment:**
- Need guaranteed commercial support? → Laravel or Symfony
- Need code that works in 2035 with minimal intervention? → Mini is safer
- Need active feature development forever? → Neither framework guarantees that

**Think about it:** How many Laravel 5.1 apps from 2015 still run on that version without costly upgrades? Mini apps have less upgrade pressure because there's less to upgrade.

---

### "Small ecosystem means you're on your own"

**This reframes as an advantage.**

**Mini's ecosystem IS PHP's ecosystem:**

```php
// Need queues?
composer require symfony/messenger
composer require bernard/bernard
composer require php-resque/php-resque

// Need search?
composer require elasticsearch/elasticsearch
composer require meilisearch/meilisearch-php

// Need payments?
composer require stripe/stripe-php
composer require omnipay/omnipay

// Need AWS?
composer require aws/aws-sdk-php

// Need PDF generation?
composer require dompdf/dompdf
composer require mpdf/mpdf
```

**Use any Composer package directly. Zero ceremony. Zero constraints.**

**Laravel's "ecosystem" is actually constraining:**
- Must use Laravel-specific wrappers (`laravel/cashier`, `laravel/scout`)
- Can't upgrade underlying library without Laravel blessing
- Packages lag behind upstream features
- When Laravel changes, packages break
- Limited to what Laravel wrapped and how they wrapped it

**Example - Stripe Integration:**

```php
// Mini: Use Stripe's native API (full flexibility)
composer require stripe/stripe-php

$stripe = new \Stripe\StripeClient($_ENV['STRIPE_SECRET']);
$intent = $stripe->paymentIntents->create([
    'amount' => 2000,
    'currency' => 'usd',
    'payment_method_types' => ['card'],
    'capture_method' => 'manual',  // Full control
]);

// Laravel: Use Cashier wrapper (convenient but limited)
composer require laravel/cashier

$user->charge(2000, 'pm_card_visa');
// What if you need PaymentIntent options Cashier doesn't expose?
// Now you're fighting the framework.
```

**The trade-off:**
- Laravel: Standardized patterns (good for team consistency)
- Mini: Full library APIs (good for flexibility and power)

**Reality:** Laravel's ecosystem is mostly thin wrappers around standard PHP packages. You're not saving work - you're trading flexibility for convention.

---

### "You'll build authentication, authorization, queues yourself"

**Let's be specific about what this means.**

#### Authentication (Included in Mini)

```php
// Mini provides the plumbing
auth()->register($email, $password);  // Bcrypt hashing
auth()->login($email, $password);     // Session management
auth()->check();                      // Is user logged in?
auth()->user();                       // Get current user

// You provide: User table schema
// Time to working auth: 1-2 hours
```

**Laravel provides:**
- More features (email verification, password reset emails, 2FA)
- More opinions (must use Eloquent, must use Mail facade)
- More abstraction (guards/providers/drivers complexity)

**Trade-off:**
- Mini: Write 50 lines for password reset emails using any mail library
- Laravel: Get it free, but locked into Laravel's way

#### Authorization (30 minutes)

```php
// Option 1: Simple role checks
function requireRole($role) {
    if (!in_array($role, auth()->user()['roles'] ?? [])) {
        throw new AccessDeniedException();
    }
}

// Option 2: Use a library
composer require casbin/casbin
// Works directly with Mini, no integration layer needed
```

**Laravel provides:** Gates and Policies (which are... functions you write anyway, just in specific locations).

#### Job Queues (Pick Your Tool)

```php
// Option 1: Symfony Messenger (full-featured)
composer require symfony/messenger
// Configure transport, done. Works with Mini's db() directly.

// Option 2: Redis queues
composer require php-resque/php-resque
// No Mini-specific integration needed

// Option 3: Cron + CLI
*/5 * * * * vendor/bin/mini run process-emails
```

**Laravel provides:** Unified queue API, `php artisan queue:work`, consistent patterns.

**Trade-off:**
- Mini: Choose your queue strategy (1 hour integration), full flexibility
- Laravel: Standardized, but locked into Laravel's queue contracts

#### The Real Question: "Are you comfortable being your own integrator?"

**The answer:** If you can't integrate a Stripe library or AWS SDK, you can't build software. Period.

This isn't a "Mini vs Laravel" question - it's a "can you program?" question.

**Integration is not a burden - it's the job.** The question is whether you want a framework that:
- **Integrates FOR you** (Laravel: standardized, but constrained)
- **Stays OUT OF YOUR WAY** (Mini: direct, full flexibility)

**For long-term systems where you need full control**, Mini's approach is cleaner. You're not "building integrations yourself" - you're using libraries as designed, without a translation layer.

---

### "Harder to find developers who know it"

**This is the most legitimate hiring concern.**

**For hiring speed:**
- Zero developers know Mini specifically
- Thousands know Laravel
- Agencies/consultancies need Laravel for staff fungibility

**Counter-arguments:**

**1. Onboarding time:**
- Mini: 2-4 hours to productive (read docs, write first route)
- Laravel: 1-2 weeks to productive (facades, service container, Eloquent, Blade, middleware)

**2. Quality signal:**
- Mini attracts developers who want to understand their stack
- Laravel attracts a mix (excellent to "Stack Overflow programmers")
- Hiring "Laravel developers" doesn't guarantee quality

**3. Maintainability:**
- Any competent PHP dev can read Mini's source and understand it
- Finding Laravel developers who understand service providers and container binding is hard

**4. AI tooling reduces framework-specific expertise needs:**
- Claude Code, Cursor, Copilot work extremely well with Mini
- AI can read entire Mini framework in context window
- Explicit code (Mini) vs magic (Laravel) = fewer AI hallucinations

**When this matters:**
- Agencies, consultancies, large rotating teams → Laravel wins
- Product companies, small expert teams → Mini wins
- Startups with technical founders → Mini wins
- Startups with non-technical founders hiring fast → Laravel wins

**Honest answer:** Optimizing for hiring speed in competitive markets? Choose Laravel. Optimizing for code quality and long-term maintainability? Mini's simplicity is an advantage.

---

### "Laravel's performance is unlikely to be a bottleneck"

**Often true, but incomplete analysis.**

**Performance isn't just throughput - it's:**

#### 1. Latency (User Experience)
- Mini: 2ms bootstrap → API responses feel instant
- Laravel: 50-100ms bootstrap → noticeable delay on every request
- Matters for: Real-time systems, mobile apps, microservices

#### 2. Resource Efficiency (Cost)
- Mini: ~5MB per worker
- Laravel: ~50MB per worker
- **10x difference** = run 10x more workers on same server
- Matters for: Docker containers, serverless, cost-sensitive deployments

#### 3. Developer Experience
- Mini: Stack traces are 10 lines
- Laravel: Stack traces are 100 lines through facades/middleware/providers
- Matters for: Debugging speed, mental overhead, productivity

**Real numbers (typical VPS):**
- Laravel: ~100 req/sec per worker, ~50MB per worker → 500 req/sec on $20/month VPS
- Mini: ~1000 req/sec per worker, ~5MB per worker → 5000 req/sec on $20/month VPS

**When Laravel performance is fine:**
- Low-traffic sites (< 10 req/sec)
- Heavy I/O workloads (database is bottleneck)
- Rich frontend with minimal backend logic

**When Mini performance matters:**
- API backends serving mobile/frontend apps
- Microservices handling high request rates
- Real-time systems (webhooks, notifications)
- Cost-sensitive deployments (every dollar matters)

**Honest take:** For typical CRUD apps, Laravel's performance is fine. For API-heavy or high-scale systems, Mini's 10x efficiency translates to real cost savings and better UX.

---

## Mini's Hidden Advantage: AI-Native Architecture

**This is a huge advantage that's rarely discussed.**

### Why Mini Works Exceptionally Well with Claude Code/Cursor/Copilot

**1. Small, comprehensible codebase**
- AI can read entire framework in context window (~15,000 lines)
- No hidden magic for AI to hallucinate about
- Clear cause→effect relationships

**2. Explicit over implicit**
```php
// Mini: AI sees exactly what happens
$users = db()->query("SELECT * FROM users WHERE active = ?", [1]);
// Clear: database connection, SQL query, parameter binding

// Laravel: AI must guess through layers
$users = DB::table('users')->where('active', 1)->get();
// Where's the connection? What middleware ran? Which query builder?
// AI often suggests outdated patterns or wrong facades
```

**3. Direct PHP patterns**
- AI trained on PHP documentation
- Mini uses standard PHP (PDO, intl, reflection)
- Routes are files (AI understands filesystem)
- No DSL or custom syntax to learn

**4. Comprehensive documentation**
- Every class has detailed docblocks
- README files explain each feature
- No magic methods (AI can see actual definitions)

**Real impact:**
- AI suggests correct code on first try (no "that's Laravel 7 syntax")
- Debugging is transparent (AI can trace execution)
- Refactoring is safe (AI sees all dependencies)

**Compare to Laravel:**
- Facades confuse dependency tracking
- Magic methods produce hallucinations
- Service provider bindings are opaque
- AI often suggests outdated versions

**In 2025 and beyond**, frameworks designed for human+AI collaboration will have significant advantages. Mini's transparent architecture is naturally AI-friendly.

---

## When to Choose What

### Choose Mini When:

✅ **You value clarity and control over convention**
- Want to understand what happens on every request
- Prefer explicit over magical
- Need to debug production issues quickly

✅ **You're building for long-term stability (10+ years)**
- Can't afford upgrade treadmill
- Want predictable, stable codebase
- Need code that survives framework trends

✅ **You have a small, skilled team (1-5 developers)**
- Can integrate libraries directly
- Value code quality over hiring speed
- Comfortable reading framework source

✅ **You're using AI coding tools extensively**
- Claude Code, Cursor, Copilot, etc.
- Want AI to understand your stack
- Need transparent code for AI assistance

✅ **Performance and resource efficiency matter**
- API backends, microservices
- Cost-sensitive deployments
- High request rates

✅ **You want direct access to Composer ecosystem**
- Use any library without wrappers
- Need full API flexibility
- Want independent upgrades

### Choose Laravel When:

✅ **You need to hire quickly from broad talent pool**
- Large or rotating teams
- Agencies and consultancies
- Staff fungibility matters

✅ **You want standardized patterns and conventions**
- Team consistency > individual flexibility
- "The Laravel Way" for everything
- Don't want architectural decisions

✅ **You need batteries-included features with zero integration**
- Email verification, password resets, 2FA out of box
- Broadcasting, queues, notifications pre-configured
- Admin panels (Nova), deployment tools (Forge)

✅ **You need commercial support contracts**
- Enterprise requirements
- Vendor relationship needed
- Official support channels

✅ **You're optimizing for initial velocity over long-term stability**
- MVP needs to ship fast
- Will likely rewrite anyway
- Framework churn is acceptable cost

---

## The Human Factor: Developer Growth, Retention & Framework Rigidity

This point rarely makes it into technical comparisons, but it is one of the most important long-term factors for teams that plan to keep talent, grow talent, and avoid stagnation.

### Rigid Frameworks Shape Thinking — Often Narrowly

After 20 years of hiring and managing developers, here's a pattern I've seen repeatedly:

> Developers who spend 40% of their time navigating framework conventions, directory structures, facades, providers, lifecycle phases, and abstraction rules **stop using their brain creatively**.

Not because they're lazy — because the framework trains them to:

* Follow the "one true way"
* Avoid stepping outside the blessed abstractions
* Choose the framework's API over the underlying technology
* Lean on magic instead of understanding what's happening

Laravel is excellent at onboarding juniors quickly, but there is a downside: **it keeps them in Laravel-land**.

They may ship features, but they often do not deeply understand:

* HTTP message flow
* SQL query planning
* Transactions and isolation levels
* Caching behavior
* Unicode and locale rules
* Session and cookie mechanics
* Memory use and process lifecycle
* Email MIME structure
* IO blocking vs async models

These things matter tremendously once the systems you build start succeeding.

### Skill Growth Slows Under Heavy Abstraction

When developers primarily learn:

* "where to place your controller"
* "what the Laravel way says"
* "which facade to call for X"
* "how to satisfy the command bus pattern"
* "how the service provider bootstraps your class"

…they *don't* learn the part that makes great seniors:

* designing systems they understand end-to-end.

The result is predictable:

* Developers plateau early
* They struggle to reason outside the framework
* They hesitate to modify core behavior
* They avoid reading the framework source
* They look for new jobs because work becomes repetitive

A rigid framework can produce productive mid-level developers — but **it often delays the emergence of true senior engineering capability**.

Mini's philosophy intentionally avoids this trap:

* You work with real PHP objects, real SQL, real HTTP, real MIME.
* You understand your stack because it *is* your stack.
* There's minimal magic and minimal ceremony.
* Every abstraction is inspectable and plain.

This fosters real engineering growth, not just framework comfort.

### The Framework Fit Problem: "One Size Fits a Lot" Is Not "One Size Fits All"

Laravel is a wonderful general-purpose framework.
But no general framework can fit:

* Real-time global systems
* High-performance streaming systems
* Multi-tenant global SaaS
* Ultra-low latency APIs
* Event-driven architectures
* Long-running PHP runtimes (Swoole, Phasync)
* Highly unconventional domains (custom protocols, HPC, ML pipelines)

If your project succeeds and grows:

* traffic goes up
* requirements get more specialized
* the system architecture evolves

At that point you're still anchored to whatever decisions your framework made:

* bootstrapping model
* service container structure
* middleware pipeline
* ORM abstraction
* baked-in conventions
* synchronous assumptions
* routing architecture

Some teams manage to wrestle Laravel into shapes it was never meant for. Others face a complete rewrite.

### Mini's Advantage: It Doesn't Get in the Way of Success

Mini's small, PHP-first, explicit architecture makes it equally suitable as the starting point for:

* a wiki engine (MediaWiki-scale)
* a social platform (Facebook-scale)
* a helpdesk system
* a billing backend
* a file processing pipeline
* a distributed job system
* a global low-latency API

You're not fighting someone else's abstraction design.
There is no architectural "gravity well" pulling you into a specific pattern.

Mini is essentially a clean, coherent foundation:

* fast bootstrap
* predictable lifecycle
* no global mutable static state
* fiber-aware
* works in classical PHP or long-running runtimes
* easy to extend because it doesn't hide anything

If you end up building something huge, Mini *scales with you*, because the architecture remains yours.

### Developers Prefer Systems They Understand

From a retention perspective:

* Developers stay longer when they feel ownership
* Developers grow faster when they work close to the metal
* Developers become senior faster when they design instead of follow
* Developers produce better systems when they can reason about every layer

Frameworks like Laravel are fantastic teaching tools and productive scaffolds.

But for teams building software that must operate for decades, must scale, or must be deeply understood, a small transparent framework like Mini is a fundamentally better foundation — both technically and humanly.

---

## The Truth: Different Tools for Different Contexts

**Mini isn't "better than Laravel" - it's better for certain contexts.**

| Your Constraint | Best Choice |
|----------------|-------------|
| Hiring speed | Laravel |
| Code clarity | Mini |
| Long-term stability | Mini |
| Team coordination | Laravel |
| Performance/cost | Mini |
| AI-assisted development | Mini |
| Commercial support | Laravel/Symfony |
| Ecosystem "batteries" | Laravel |
| Direct library integration | Mini |
| Developer growth to senior | Mini |

**Laravel is optimized for team coordination and rapid hiring.**

**Mini is optimized for code clarity and long-term stability.**

**Choose based on your actual constraint.**

---

## Final Thought

> "Frameworks designed for release cycles optimize for change.
> Frameworks designed for decades optimize for stability.
>
> Mini wraps what won't change: PDO, intl, reflection.
> That's not a limitation - it's a feature."

**When you value:**
- Understanding over inheritance
- Stability over features
- Clarity over convenience
- Decades over releases

**Mini isn't a compromise - it's the right tool.**

---

## Getting Started

If Mini's philosophy resonates with you:

1. **Read the main [README.md](../README.md)** - Get started in 4 commands
2. **Browse [REFERENCE.md](../REFERENCE.md)** - Complete API reference
3. **Check [feature documentation](../src/)** - Each feature has detailed README
4. **Try it** - `composer require fubber/mini` and build something

**Mini isn't trying to replace Laravel. It's offering a different path:**

Build less. Understand more. Depend on less. Run for decades.
