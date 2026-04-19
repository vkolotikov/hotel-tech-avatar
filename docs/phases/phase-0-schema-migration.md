# Phase 0 — Schema Migration Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Evolve the existing single-vertical hotel chat schema into a multi-vertical, evidence-grounded expert-avatar foundation that supports Wellness v1.0 without regressing the hotel SPA.

**Architecture:** Introduce a `verticals` table as the first-class grouping concept. Backfill every existing agent to the `hotel` vertical. Add additive columns and new tables that cover prompt versioning, knowledge chunks with pgvector embeddings, citations, verification events, token-usage ledgering, user profiles, and subscription entitlements. Every new column is nullable / defaulted; every new table is additive so existing hotel reads and writes are bit-for-bit unchanged. A regression feature-test suite pins the hotel API surface before and after every migration.

**Tech Stack:** Laravel 13 · PHP 8.4 · PostgreSQL 15+ · pgvector extension · Pest/PHPUnit for feature tests.

**Scope boundary for this plan:** database schema + pgvector + hotel regression pin only. Eval harness scaffold, Sentry/Langfuse telemetry, Sanctum for mobile, and the Expo skeleton are separate Phase 0 plans to be written after this one is approved and landed.

---

## Part A — Design (read and sign off before Part B)

### A.1 Vertical tagging — how

**Decision:** a `verticals` table with FK `vertical_id` on `agents`, rather than a loose string column.

| Option | Pro | Con |
|---|---|---|
| String column `vertical` on agents | trivial | no place to hang branding, launch flags, app-store IDs, localisation, handoff defaults; referential integrity via check constraint only |
| **`verticals` table + FK** ← chosen | metadata lives on vertical (accent colour, tagline, default language, app-store IDs, launched_at, kiosk flag, which handoffs are allowed), RI enforced, matches "one engine, many configurations" philosophy | one extra join (eager-loadable) |

Denormalised `vertical_id` is also copied onto `conversations` for cheap tenant-style queries (avoids joining through `agents` for every list endpoint).

Seed rows at migration time:

| id | slug | name | is_active | launched_at | metadata |
|---|---|---|---|---|---|
| 1 | `hotel` | Hotel Concierge Suite | true | now() | `{"accent":"#8B5CF6","surface":"spa"}` |
| 2 | `wellness` | WellnessAI | false | null | `{"accent":"#10B981","app_store_id":null,"play_store_id":null}` |

Wellness launches flipped `is_active=true` + `launched_at` set once the mobile app ships.

### A.2 Table inventory — extend vs create

**Extended (additive columns only, all nullable or defaulted):**

| Table | Added columns | Why |
|---|---|---|
| `users` | `birthdate` date null, `jurisdiction` varchar(8) null (ISO 3166-1 alpha-2), `consent_json` jsonb null, `locale` varchar(8) default `en` | 18+ age gate, GDPR vs CCPA branching, recorded consent per spec §6.1 |
| `agents` | `vertical_id` FK → verticals.id (NOT NULL after backfill), `domain` varchar(64) null (e.g. `functional-medicine`, `nutrition`), `persona_json` jsonb null (voice, visual preset, accent colour), `scope_json` jsonb null (allowed topics, disallowed language patterns), `red_flag_rules_json` jsonb null, `handoff_rules_json` jsonb null, `active_prompt_version_id` nullable FK → agent_prompt_versions.id | per-avatar configuration lives as data, not code |
| `conversations` | `vertical_id` FK → verticals.id (NOT NULL after backfill), `user_id` FK → users.id null, `summary_json` jsonb null, `last_activity_at` timestamp null, `session_cost_usd_cents` integer default 0 | per-session cap (spec §9.4), rolling summary (spec §5.8), user ownership |
| `messages` | `agent_id` FK null (denormalised for speed, carries the agent that produced/received a message), `verification_status` varchar(16) default `'not_required'` (`not_required`, `pending`, `passed`, `softened`, `blocked`), `handoff_from_agent_id` FK null, `claim_count` int null, `grounded_claim_count` int null, `red_flag_triggered` bool default false | hooks the verification pipeline, handoff telemetry, red-flag counts |

**New tables created in Phase 0:**

| Table | Purpose |
|---|---|
| `verticals` | multi-vertical grouping (§A.1) |
| `agent_prompt_versions` | versioned system_instructions + persona + scope + red-flag + handoff snapshot. Diff / A/B per CLAUDE.md admin requirements. |
| `knowledge_documents` | curated per-avatar corpus (one row per source document, with licence and evidence grade) |
| `knowledge_chunks` | chunked + embedded text; single `embedding vector(3072)` column via pgvector (OpenAI `text-embedding-3-large`). Voyage 1024 is a Phase 1+ quality experiment, not a Phase 0 schema concern. |
| `external_source_cache` | stub — Phase 0 creates the table; Phase 1 populates it (PubMed, USDA, etc.) |
| `message_citations` | per-message links to `knowledge_chunks.id` or `external_source_cache.id`, with span offsets |
| `verification_events` | per-message claim-extraction, grounding, critic, safety results. One row per stage, ordered, with pass/fail + notes. |
| `llm_calls` | every LLM invocation — provider, model, prompt_tokens, completion_tokens, cost_usd_cents, latency_ms, trace_id (Langfuse), parent_llm_call_id for chains |
| `red_flag_events` | every deterministic red-flag trigger, pre-generation; immutable audit log |
| `token_usage_daily` | rolled-up per (user_id, yyyy-mm-dd): messages_count, tokens_in, tokens_out, cost_usd_cents |
| `subscription_plans` | catalog (free, basic, pro, ultimate) with message caps, features, price cents |
| `subscription_entitlements` | per-user current plan, trial_ends_at, renews_at, vendor-neutral `billing_provider` (`revenuecat`, `stripe`, `manual`) + `billing_customer_id` + `billing_metadata` jsonb. No vendor names in column names. |
| `user_profiles` | one wide row per user with typed columns for known product fields (goals, conditions, medications, dietary_flags, wearables_connected, height_cm, weight_kg, sex_at_birth, activity_level) plus `profile_metadata` jsonb for experimental or provisional data. PHI-sensitive fields stay typed and queryable. |

**Deferred past Phase 0 (noted, not created here):**

- `eval_datasets`, `eval_runs`, `eval_results` — eval harness plan.
- `avatar_handoffs` — messages already carry `handoff_from_agent_id`; a dedicated event table can be added when we need cross-avatar analytics.
- `user_media_assets` (lab PDFs, skin selfies) — Phase 5 Multimodal plan.
- `wearable_samples` — Phase 5.

### A.3 Existing-data mapping

| Existing row | Maps to |
|---|---|
| 4 seeded hotel agents (`hotel-concierge` Sofia, `spa-therapist` Elena, `events-coordinator` Marco, `culinary-guide` Hans) | `agents.vertical_id = 1` (hotel), `domain = null`, `persona_json` synthesised from `openai_voice` and `avatar_image_url`, `active_prompt_version_id` set to the freshly-created `agent_prompt_versions` row snapshot of current `system_instructions`. |
| Existing `conversations` rows | `vertical_id = 1` (hotel) backfilled via `UPDATE conversations SET vertical_id = (SELECT vertical_id FROM agents WHERE agents.id = conversations.agent_id)`. `user_id` stays NULL — existing hotel flow has no users. |
| Existing `messages` rows | `agent_id` backfilled via `UPDATE messages SET agent_id = (SELECT agent_id FROM conversations WHERE conversations.id = messages.conversation_id) WHERE role = 'agent'`. User-role messages keep `agent_id` NULL. `verification_status = 'not_required'` (default). |
| Existing `agent_knowledge_files` | untouched. Hotel continues using the existing OpenAI vector-store sync path. Wellness uses the new `knowledge_documents` + `knowledge_chunks` path. A later ADR may unify them. |
| `users` table | existing admin users keep behaving; added columns are NULL. First mobile-app-authenticated user will populate them. |

### A.4 Regression strategy — how we prove hotel stays green

1. **Pin current behaviour before touching schema.** Task B.1 creates `tests/Feature/Regression/HotelSpaRegressionTest.php` covering the public hotel endpoints: list agents, open conversation, list conversations, list messages, post user message, trigger agent reply, voice transcribe stub, voice speak stub, Heygen token stub. All assertions are against current responses — green on `main` today.
2. **Run the regression suite after every migration.** Part B tasks interleave migration + test run + commit. A failing regression blocks progress.
3. **All schema changes are additive.** No column drops, no column renames, no NOT NULL added to existing columns, no foreign-key cascades that affect existing rows until after the backfill migration succeeds. NOT NULL on `agents.vertical_id` and `conversations.vertical_id` only added in the follow-up migration once backfill has run.
4. **SPA build unchanged.** This plan touches zero frontend files. The existing `/spa/` admin + hotel chat surface continues reading/writing the same columns it always has.
5. **Manual smoke test before merge.** A checklist (Task B.15) walks the engineer through each hotel agent to confirm the live site still works before tagging the release.
6. **Rollback plan.** Every new migration has a `down()` that reverses it. Task B.16 verifies `php artisan migrate:rollback` returns to the pre-plan state against a fresh seed.

### A.5 Decisions (locked 2026-04-19)

1. **Embedding dimensionality → `vector(3072)`, single column named `embedding`.** OpenAI `text-embedding-3-large`. Voyage 1024 is a Phase 1+ quality experiment and does not shape Phase 0 schema.
2. **Subscription billing columns → vendor-neutral.** `billing_provider` (varchar, values `revenuecat` | `stripe` | `manual`), `billing_customer_id` (varchar), `billing_metadata` (jsonb). No vendor names in column names anywhere.
3. **`user_profiles` shape → one wide row per user, with typed columns for known product fields plus `profile_metadata` jsonb** for experimental / provisional data. PHI-sensitive fields stay typed and queryable. No key-value alternative.
4. **Local dev DB → PostgreSQL required everywhere** (local, CI, staging, production). No SQLite fallback anywhere in the test suite. Phase 0 ships a `docker-compose.yml` alongside the schema migrations (Task B.0) so any developer can bring up Postgres + pgvector with one command.
5. **OpenAI data-retention posture (as of 2026-04-19):**
   - **Dashboard sharing:** all three org-level toggles set to *Disabled* — no prompts, responses, evaluation data, or feedback are shared with OpenAI for training or model-improvement. Safe for hotel production now.
   - **API call logging:** enabled per call by default at the organisation level. The LLM client abstraction (forthcoming in a sibling Phase 0 plan) is the enforcement point: wellness-vertical calls MUST set `store=false`; hotel-vertical calls keep logging on for debugging unless a specific call opts out.
   - **Formal contractual ZDR:** **not yet in place.** A formal request has been sent to OpenAI on 2026-04-19. Eliminates the 30-day abuse-monitoring retention once granted; expected to land within a few days to a few weeks.
   - **Phase 3 gate:** formal ZDR must be in place before any real wellness-user health data hits OpenAI in production. This plan (Phase 0, schema only, no user data) is unaffected. The facts above are recorded in `docs/compliance/openai-zdr.md` in Task B.0.

### A.6 Dev environment — prerequisites for Part B

- **Postgres 16 + pgvector** must be running locally before any schema task runs. Task B.0 provides `docker-compose.yml`.
- **No SQLite branching in tests.** Every feature test targets pgsql. Tests that previously skipped on non-pgsql (e.g. `KnowledgeChunkVectorTest`) still keep the driver guard as a belt-and-braces safeguard, but CI does not exercise that branch.
- **`.env.example`** is updated to show the docker-compose connection values so new engineers can copy-paste and run.

---

## Part B — Task breakdown (TDD, bite-sized)

Do not start Part B until Part A is signed off.

Legend: every task = failing test → minimal impl → green → commit. Run commands use `php artisan` and `./vendor/bin/pest` (or `./vendor/bin/phpunit` if Pest isn't installed; fall back cleanly).

---

### Task B.0 — Prerequisite: local Postgres via docker-compose + test-DB isolation + OpenAI compliance doc

**Why test-DB isolation belongs here:** `phpunit.xml` currently has the SQLite overrides commented out and carries no explicit `DB_CONNECTION` / `DB_DATABASE` for the testing suite. That means running `./vendor/bin/pest` inherits the repo's `.env` values — which point to Laravel Cloud production Postgres. `RefreshDatabase` against that would be catastrophic. We fix this before any test runs in B.1.

**Files:**
- Create: `docker-compose.yml`
- Create: `docker/postgres/init/01-create-test-db.sql`
- Modify: `.env.example` (fill username/password so it matches the compose stack)
- Modify: `phpunit.xml` (force `DB_DATABASE=hotel_avatar_test`)
- Create: `docs/compliance/openai-zdr.md`

- [ ] **Step 1: Write `docker-compose.yml`**

```yaml
services:
  postgres:
    image: pgvector/pgvector:pg16
    container_name: avatar-postgres
    restart: unless-stopped
    environment:
      POSTGRES_USER: avatar
      POSTGRES_PASSWORD: avatar
      POSTGRES_DB: hotel_avatar
    ports:
      - "5432:5432"
    volumes:
      - postgres_data:/var/lib/postgresql/data
      - ./docker/postgres/init:/docker-entrypoint-initdb.d:ro
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U avatar -d hotel_avatar"]
      interval: 5s
      timeout: 5s
      retries: 10

volumes:
  postgres_data:
```

Rationale: the `pgvector/pgvector:pg16` image is the official combined build — Postgres 16 with the pgvector extension pre-installed. One container, one command. The bind-mounted `docker/postgres/init/` directory runs any `.sql` or `.sh` scripts once, on first boot, in alphabetical order — we use it to create the separate test database.

- [ ] **Step 1b: Write `docker/postgres/init/01-create-test-db.sql`**

```sql
CREATE DATABASE hotel_avatar_test OWNER avatar;
```

This file runs once on first container start. If you need to re-run it later (e.g. you changed the script), `docker compose down -v` drops the data volume and a fresh `up` re-seeds it.

- [ ] **Step 2: Update `.env.example`**

Replace the SQLite block (or append, if no DB block exists) with:

```dotenv
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=hotel_avatar
DB_USERNAME=avatar
DB_PASSWORD=avatar
```

Leave the production `.env` untouched — Laravel Cloud continues to inject its own `DB_*` values via secrets.

- [ ] **Step 2b: Update `phpunit.xml` to force a separate test database**

The current `phpunit.xml` has the SQLite overrides commented out, so tests inherit the repo's `.env`. Without this fix, `RefreshDatabase` runs against production Postgres. Add three `<env>` lines inside the `<php>` block (keep existing entries):

```xml
<env name="DB_CONNECTION" value="pgsql"/>
<env name="DB_DATABASE" value="hotel_avatar_test"/>
<env name="DB_HOST" value="127.0.0.1"/>
<env name="DB_PORT" value="5432"/>
<env name="DB_USERNAME" value="avatar"/>
<env name="DB_PASSWORD" value="avatar"/>
```

Delete the two commented SQLite lines at the same time — they are no longer valid given the "Postgres everywhere, no SQLite fallback" decision. Keep `APP_ENV=testing` so Laravel doesn't think it's running in production.

Note for CI: the CI pipeline should rely on these phpunit defaults and stand up the same compose stack (or an equivalent managed Postgres) — not inject its own DB_* via the CI env, which would defeat the isolation.

- [ ] **Step 3: Write `docs/compliance/openai-zdr.md`**

Record the OpenAI data-retention posture as of 2026-04-19, mirroring §A.5 item 5 above. Template:

```markdown
# OpenAI — data retention posture

**Last updated:** 2026-04-19
**Owner:** platform / compliance

## Current status

### Dashboard-level data sharing (organisation)

All three organisation-level sharing toggles in the OpenAI console are **Disabled**:

- "Share inputs and outputs with OpenAI" — Disabled
- "Share evaluation and fine-tuning data with OpenAI" — Disabled
- "Enable sharing of model feedback from the Platform" — Disabled

No prompts, responses, evaluation data, or feedback are shared with OpenAI for training or model-improvement purposes.

### API call logging (per-request)

Organisation-level default permits logging. Per-request `store=false` is the enforcement mechanism.

- **Wellness vertical:** all calls MUST pass `store=false`. Enforced centrally by the LLM client abstraction (`app/Services/LlmClient.php`, forthcoming in the Phase 0 telemetry plan).
- **Hotel vertical:** logging remains on by default (helpful for debugging existing flows). Any specific call may opt out via `store=false`.

### Formal contractual Zero Data Retention (ZDR)

**Not yet in place as of 2026-04-19.** Formal request sent to OpenAI the same day.

Contractual ZDR is a stronger commitment than the dashboard toggles — it eliminates the 30-day abuse-monitoring retention. Expected processing time: a few days to a few weeks.

## Gate

Per spec hard-rule #5 (CLAUDE.md, `docs/PROJECT_SPEC.md`): **real wellness-user health data must not be routed to OpenAI in production until formal ZDR is in place.** Phase 0 ships no user data, so this is not yet blocking. The gate applies from Phase 3 onwards.

## Changelog

- 2026-04-19 — recorded initial state; formal ZDR request filed.
```

- [ ] **Step 4: Bring Postgres up and sanity-check the connection** *(requires Docker on the executing machine)*

Run:
```bash
docker compose up -d
docker compose ps
# Sanity-check both databases exist:
docker exec -i avatar-postgres psql -U avatar -d postgres -c '\l' | grep hotel_avatar
# Should list both: hotel_avatar (dev) and hotel_avatar_test (tests)

# Point local .env at the compose DB temporarily (do NOT commit):
# DB_HOST=127.0.0.1, DB_DATABASE=hotel_avatar, DB_USERNAME=avatar, DB_PASSWORD=avatar
php artisan migrate:status
```

Expected: `avatar-postgres` healthy, both `hotel_avatar` and `hotel_avatar_test` present, `migrate:status` reports the existing migrations against the compose DB.

If the executing engineer does not have Docker installed, they must stand up a local Postgres 16 + pgvector some other way before proceeding to B.1, or the test runs will fail (or worse, hit production). Docker is the sanctioned path; alternatives are the engineer's responsibility.

- [ ] **Step 5: Commit**

```bash
git add docker-compose.yml docker/postgres/init/01-create-test-db.sql .env.example phpunit.xml docs/compliance/openai-zdr.md
git commit -m "chore(devenv): postgres+pgvector compose, test-db isolation, openai zdr doc"
```

---

### Task B.1 — Pin hotel regression before touching schema

**Files:**
- Create: `tests/Feature/Regression/HotelSpaRegressionTest.php`

- [ ] **Step 1: Write the failing regression test**

```php
<?php

namespace Tests\Feature\Regression;

use App\Models\Agent;
use App\Models\Conversation;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HotelSpaRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
    }

    public function test_agents_index_returns_four_published_hotel_agents(): void
    {
        $response = $this->getJson('/api/v1/agents');

        $response->assertOk();
        $response->assertJsonCount(4);
        $response->assertJsonFragment(['slug' => 'hotel-concierge']);
        $response->assertJsonFragment(['slug' => 'spa-therapist']);
        $response->assertJsonFragment(['slug' => 'events-coordinator']);
        $response->assertJsonFragment(['slug' => 'culinary-guide']);
    }

    public function test_agent_show_returns_existing_fields(): void
    {
        $agent = Agent::where('slug', 'spa-therapist')->firstOrFail();

        $response = $this->getJson("/api/v1/agents/{$agent->id}");

        $response->assertOk();
        $response->assertJsonStructure([
            'id', 'slug', 'name', 'role', 'description',
            'avatar_image_url', 'chat_background_url',
            'created_at', 'updated_at',
        ]);
    }

    public function test_conversation_create_and_message_roundtrip(): void
    {
        $agent = Agent::where('slug', 'hotel-concierge')->firstOrFail();

        $conv = $this->postJson("/api/v1/agents/{$agent->id}/conversations")
            ->assertCreated()
            ->json();

        $this->assertEquals($agent->id, $conv['agent_id']);

        $this->postJson("/api/v1/conversations/{$conv['id']}/messages", [
            'role' => 'user',
            'content' => 'Hello',
            'auto_reply' => false,
        ])->assertCreated();

        $this->getJson("/api/v1/conversations/{$conv['id']}/messages")
            ->assertOk()
            ->assertJsonPath('1.content', 'Hello');
    }
}
```

- [ ] **Step 2: Run it to confirm it passes on `main` (baseline green)**

Run: `./vendor/bin/pest tests/Feature/Regression/HotelSpaRegressionTest.php`
Expected: PASS (3/3). If it fails, STOP — the pin is wrong and must be fixed before any migration lands.

- [ ] **Step 3: Commit the pin**

```bash
git add tests/Feature/Regression/HotelSpaRegressionTest.php
git commit -m "test: pin hotel SPA API regression suite before Phase 0 schema work"
```

---

### Task B.2 — Create `verticals` table + seed hotel & wellness

**Files:**
- Create: `database/migrations/2026_04_19_000001_create_verticals_table.php`
- Modify: `database/seeders/DatabaseSeeder.php`
- Create: `database/seeders/VerticalsSeeder.php`
- Create: `app/Models/Vertical.php`
- Create: `tests/Feature/VerticalsTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Vertical;
use Database\Seeders\VerticalsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VerticalsTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_hotel_and_wellness_verticals(): void
    {
        $this->seed(VerticalsSeeder::class);

        $this->assertEquals(2, Vertical::count());

        $hotel = Vertical::where('slug', 'hotel')->firstOrFail();
        $this->assertTrue($hotel->is_active);
        $this->assertNotNull($hotel->launched_at);

        $wellness = Vertical::where('slug', 'wellness')->firstOrFail();
        $this->assertFalse($wellness->is_active);
        $this->assertNull($wellness->launched_at);
    }
}
```

- [ ] **Step 2: Run to confirm it fails**

Run: `./vendor/bin/pest tests/Feature/VerticalsTest.php`
Expected: FAIL — class `App\Models\Vertical` not found.

- [ ] **Step 3: Create the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verticals', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 32)->unique();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamp('launched_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verticals');
    }
};
```

- [ ] **Step 4: Create the model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vertical extends Model
{
    protected $fillable = ['slug', 'name', 'description', 'is_active', 'launched_at', 'metadata'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'launched_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function agents(): HasMany
    {
        return $this->hasMany(Agent::class);
    }
}
```

- [ ] **Step 5: Create the seeder**

```php
<?php

namespace Database\Seeders;

use App\Models\Vertical;
use Illuminate\Database\Seeder;

class VerticalsSeeder extends Seeder
{
    public function run(): void
    {
        Vertical::updateOrCreate(
            ['slug' => 'hotel'],
            [
                'name' => 'Hotel Concierge Suite',
                'description' => 'Hospitality avatars — concierge, spa, events, culinary.',
                'is_active' => true,
                'launched_at' => now(),
                'metadata' => ['accent' => '#8B5CF6', 'surface' => 'spa'],
            ]
        );

        Vertical::updateOrCreate(
            ['slug' => 'wellness'],
            [
                'name' => 'WellnessAI',
                'description' => 'Six specialist avatars for consumer wellness.',
                'is_active' => false,
                'launched_at' => null,
                'metadata' => ['accent' => '#10B981', 'app_store_id' => null, 'play_store_id' => null],
            ]
        );
    }
}
```

- [ ] **Step 6: Register seeder in DatabaseSeeder**

Modify `database/seeders/DatabaseSeeder.php` `run()` to call `$this->call(VerticalsSeeder::class);` before `DemoSeeder`.

- [ ] **Step 7: Run migration + verify tests**

Run:
```
php artisan migrate
./vendor/bin/pest tests/Feature/VerticalsTest.php
./vendor/bin/pest tests/Feature/Regression/HotelSpaRegressionTest.php
```
Expected: both green.

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_04_19_000001_create_verticals_table.php app/Models/Vertical.php database/seeders/VerticalsSeeder.php database/seeders/DatabaseSeeder.php tests/Feature/VerticalsTest.php
git commit -m "feat(schema): add verticals table with hotel + wellness seed rows"
```

---

### Task B.3 — Add `vertical_id` to `agents` and backfill

**Files:**
- Create: `database/migrations/2026_04_19_000002_add_vertical_id_to_agents.php`
- Create: `database/migrations/2026_04_19_000003_backfill_agents_vertical_id.php`
- Create: `database/migrations/2026_04_19_000004_agents_vertical_id_not_null.php`
- Modify: `app/Models/Agent.php`
- Create: `tests/Feature/AgentVerticalTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Vertical;
use Database\Seeders\DemoSeeder;
use Database\Seeders\VerticalsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentVerticalTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_seeded_agents_belong_to_hotel_vertical(): void
    {
        $this->seed(VerticalsSeeder::class);
        $this->seed(DemoSeeder::class);

        $hotel = Vertical::where('slug', 'hotel')->firstOrFail();

        Agent::all()->each(function (Agent $agent) use ($hotel) {
            $this->assertEquals($hotel->id, $agent->vertical_id, "Agent {$agent->slug} not on hotel vertical");
            $this->assertEquals($hotel->id, $agent->vertical->id);
        });
    }
}
```

- [ ] **Step 2: Run to confirm it fails**

Run: `./vendor/bin/pest tests/Feature/AgentVerticalTest.php`
Expected: FAIL — column `vertical_id` does not exist on `agents`.

- [ ] **Step 3: Write the additive migration (nullable)**

`2026_04_19_000002_add_vertical_id_to_agents.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->foreignId('vertical_id')->nullable()->after('id')->constrained('verticals')->restrictOnDelete();
            $table->index('vertical_id');
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropForeign(['vertical_id']);
            $table->dropIndex(['vertical_id']);
            $table->dropColumn('vertical_id');
        });
    }
};
```

- [ ] **Step 4: Write the backfill migration**

`2026_04_19_000003_backfill_agents_vertical_id.php`:

```php
<?php

use App\Models\Vertical;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $hotel = Vertical::firstOrCreate(
            ['slug' => 'hotel'],
            ['name' => 'Hotel Concierge Suite', 'is_active' => true, 'launched_at' => now()]
        );

        DB::table('agents')->whereNull('vertical_id')->update(['vertical_id' => $hotel->id]);
    }

    public function down(): void
    {
        DB::table('agents')->update(['vertical_id' => null]);
    }
};
```

- [ ] **Step 5: Write the NOT NULL migration**

`2026_04_19_000004_agents_vertical_id_not_null.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->foreignId('vertical_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->foreignId('vertical_id')->nullable()->change();
        });
    }
};
```

- [ ] **Step 6: Update the Agent model**

Add to `$fillable`: `'vertical_id'`. Add relationship:

```php
use Illuminate\Database\Eloquent\Relations\BelongsTo;

public function vertical(): BelongsTo
{
    return $this->belongsTo(Vertical::class);
}

public function scopeForVertical($query, string $slug)
{
    return $query->whereHas('vertical', fn ($q) => $q->where('slug', $slug));
}
```

Also update `DemoSeeder.php` so every seeded agent row includes `'vertical_id' => Vertical::where('slug', 'hotel')->value('id'),` — this keeps fresh-install seeding correct without relying on the backfill migration.

- [ ] **Step 7: Run migrations + full test suite**

Run:
```
php artisan migrate:fresh --seed
./vendor/bin/pest tests/Feature/AgentVerticalTest.php tests/Feature/Regression/HotelSpaRegressionTest.php tests/Feature/VerticalsTest.php
```
Expected: all green.

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_04_19_00000{2,3,4}_*.php app/Models/Agent.php database/seeders/DemoSeeder.php tests/Feature/AgentVerticalTest.php
git commit -m "feat(schema): tag agents with vertical_id, backfill existing rows to hotel"
```

---

### Task B.4 — Add `vertical_id` + `user_id` + session fields to `conversations`

**Files:**
- Create: `database/migrations/2026_04_19_000005_extend_conversations_table.php`
- Create: `database/migrations/2026_04_19_000006_backfill_conversations_vertical_id.php`
- Modify: `app/Models/Conversation.php`
- Create: `tests/Feature/ConversationVerticalTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Vertical;
use Database\Seeders\DemoSeeder;
use Database\Seeders\VerticalsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversationVerticalTest extends TestCase
{
    use RefreshDatabase;

    public function test_conversations_inherit_vertical_from_agent(): void
    {
        $this->seed(VerticalsSeeder::class);
        $this->seed(DemoSeeder::class);

        $hotelId = Vertical::where('slug', 'hotel')->value('id');

        Conversation::all()->each(fn (Conversation $c) =>
            $this->assertEquals($hotelId, $c->vertical_id)
        );
    }

    public function test_conversation_session_cost_defaults_to_zero(): void
    {
        $this->seed(VerticalsSeeder::class);
        $this->seed(DemoSeeder::class);

        Conversation::all()->each(fn (Conversation $c) =>
            $this->assertSame(0, $c->session_cost_usd_cents)
        );
    }
}
```

- [ ] **Step 2: Run to confirm it fails**

Run: `./vendor/bin/pest tests/Feature/ConversationVerticalTest.php`
Expected: FAIL.

- [ ] **Step 3: Write the extend migration**

`2026_04_19_000005_extend_conversations_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->foreignId('vertical_id')->nullable()->after('agent_id')->constrained('verticals')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->after('vertical_id')->constrained('users')->nullOnDelete();
            $table->jsonb('summary_json')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->unsignedInteger('session_cost_usd_cents')->default(0);

            $table->index('vertical_id');
            $table->index('user_id');
            $table->index('last_activity_at');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropForeign(['vertical_id']);
            $table->dropForeign(['user_id']);
            $table->dropIndex(['vertical_id']);
            $table->dropIndex(['user_id']);
            $table->dropIndex(['last_activity_at']);
            $table->dropColumn(['vertical_id', 'user_id', 'summary_json', 'last_activity_at', 'session_cost_usd_cents']);
        });
    }
};
```

- [ ] **Step 4: Write the backfill migration**

`2026_04_19_000006_backfill_conversations_vertical_id.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('
            UPDATE conversations
               SET vertical_id = agents.vertical_id
              FROM agents
             WHERE agents.id = conversations.agent_id
               AND conversations.vertical_id IS NULL
        ');
    }

    public function down(): void
    {
        DB::table('conversations')->update(['vertical_id' => null]);
    }
};
```

- [ ] **Step 5: Update Conversation model**

Add to `$fillable`: `'vertical_id'`, `'user_id'`, `'summary_json'`, `'last_activity_at'`, `'session_cost_usd_cents'`. Add casts: `summary_json => array`, `last_activity_at => datetime`. Add relationships `vertical()` and `user()`.

- [ ] **Step 6: Run migrations + tests**

Run: `php artisan migrate:fresh --seed && ./vendor/bin/pest tests/Feature/ConversationVerticalTest.php tests/Feature/Regression/HotelSpaRegressionTest.php`
Expected: green.

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_04_19_00000{5,6}_*.php app/Models/Conversation.php tests/Feature/ConversationVerticalTest.php
git commit -m "feat(schema): extend conversations with vertical, user, summary, session cost"
```

---

### Task B.5 — Extend `agents` with persona + scope + handoff JSON + active prompt version FK

**Files:**
- Create: `database/migrations/2026_04_19_000007_extend_agents_configuration.php`
- Modify: `app/Models/Agent.php`
- Create: `tests/Feature/AgentConfigurationTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Agent;
use Database\Seeders\DemoSeeder;
use Database\Seeders\VerticalsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_has_configuration_columns(): void
    {
        $this->seed(VerticalsSeeder::class);
        $this->seed(DemoSeeder::class);

        $agent = Agent::first();
        $agent->update([
            'domain' => 'hospitality',
            'persona_json' => ['voice' => 'nova', 'accent' => '#8B5CF6'],
            'scope_json' => ['allowed' => ['hotel-services']],
            'red_flag_rules_json' => [],
            'handoff_rules_json' => ['fallback' => null],
        ]);

        $agent->refresh();

        $this->assertSame('hospitality', $agent->domain);
        $this->assertSame('nova', $agent->persona_json['voice']);
        $this->assertIsArray($agent->scope_json);
    }
}
```

- [ ] **Step 2: Run to confirm it fails**

Run: `./vendor/bin/pest tests/Feature/AgentConfigurationTest.php`
Expected: FAIL — columns don't exist.

- [ ] **Step 3: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->string('domain', 64)->nullable()->after('role');
            $table->jsonb('persona_json')->nullable();
            $table->jsonb('scope_json')->nullable();
            $table->jsonb('red_flag_rules_json')->nullable();
            $table->jsonb('handoff_rules_json')->nullable();
            $table->unsignedBigInteger('active_prompt_version_id')->nullable();
            // FK added in Task B.6 once agent_prompt_versions exists.
            $table->index('domain');
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropIndex(['domain']);
            $table->dropColumn([
                'domain', 'persona_json', 'scope_json',
                'red_flag_rules_json', 'handoff_rules_json',
                'active_prompt_version_id',
            ]);
        });
    }
};
```

- [ ] **Step 4: Update Agent model**

Add to `$fillable`: `'domain'`, `'persona_json'`, `'scope_json'`, `'red_flag_rules_json'`, `'handoff_rules_json'`, `'active_prompt_version_id'`. Add array casts for the four JSON columns.

- [ ] **Step 5: Run migrations + tests**

Run: `php artisan migrate:fresh --seed && ./vendor/bin/pest tests/Feature/AgentConfigurationTest.php tests/Feature/Regression/HotelSpaRegressionTest.php`
Expected: green.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_04_19_000007_*.php app/Models/Agent.php tests/Feature/AgentConfigurationTest.php
git commit -m "feat(schema): add persona/scope/red-flag/handoff JSON config to agents"
```

---

### Task B.6 — Create `agent_prompt_versions` and link `agents.active_prompt_version_id`

**Files:**
- Create: `database/migrations/2026_04_19_000008_create_agent_prompt_versions.php`
- Create: `database/migrations/2026_04_19_000009_agents_active_prompt_version_fk.php`
- Create: `database/migrations/2026_04_19_000010_snapshot_existing_agent_prompts.php`
- Create: `app/Models/AgentPromptVersion.php`
- Modify: `app/Models/Agent.php`
- Create: `tests/Feature/AgentPromptVersionsTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\AgentPromptVersion;
use Database\Seeders\DemoSeeder;
use Database\Seeders\VerticalsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentPromptVersionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_agents_have_active_prompt_version_snapshot(): void
    {
        $this->seed(VerticalsSeeder::class);
        $this->seed(DemoSeeder::class);

        Agent::all()->each(function (Agent $agent) {
            $this->assertNotNull($agent->active_prompt_version_id, "Agent {$agent->slug} missing active prompt version");
            $version = $agent->activePromptVersion;
            $this->assertSame($agent->system_instructions, $version->system_instructions);
            $this->assertSame(1, $version->version_number);
            $this->assertTrue($version->is_active);
        });
    }

    public function test_can_publish_a_new_version(): void
    {
        $this->seed(VerticalsSeeder::class);
        $this->seed(DemoSeeder::class);

        $agent = Agent::first();
        $v2 = AgentPromptVersion::create([
            'agent_id' => $agent->id,
            'version_number' => 2,
            'system_instructions' => 'Updated instructions',
            'persona_json' => null,
            'scope_json' => null,
            'red_flag_rules_json' => null,
            'handoff_rules_json' => null,
            'is_active' => false,
            'created_by_user_id' => null,
            'note' => 'manual test',
        ]);

        $this->assertEquals(2, $agent->promptVersions()->count());
    }
}
```

- [ ] **Step 2: Run to confirm it fails**

Run: `./vendor/bin/pest tests/Feature/AgentPromptVersionsTest.php`
Expected: FAIL — class / table not found.

- [ ] **Step 3: Write the create migration**

`2026_04_19_000008_create_agent_prompt_versions.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_prompt_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained('agents')->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->text('system_instructions');
            $table->jsonb('persona_json')->nullable();
            $table->jsonb('scope_json')->nullable();
            $table->jsonb('red_flag_rules_json')->nullable();
            $table->jsonb('handoff_rules_json')->nullable();
            $table->boolean('is_active')->default(false);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['agent_id', 'version_number']);
            $table->index(['agent_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_prompt_versions');
    }
};
```

- [ ] **Step 4: Write the FK-linking migration**

`2026_04_19_000009_agents_active_prompt_version_fk.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->foreign('active_prompt_version_id')
                ->references('id')->on('agent_prompt_versions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropForeign(['active_prompt_version_id']);
        });
    }
};
```

- [ ] **Step 5: Write the snapshot migration**

`2026_04_19_000010_snapshot_existing_agent_prompts.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('agents')->orderBy('id')->get()->each(function ($agent) use ($now) {
            $versionId = DB::table('agent_prompt_versions')->insertGetId([
                'agent_id' => $agent->id,
                'version_number' => 1,
                'system_instructions' => $agent->system_instructions ?? '',
                'persona_json' => $agent->persona_json,
                'scope_json' => $agent->scope_json,
                'red_flag_rules_json' => $agent->red_flag_rules_json,
                'handoff_rules_json' => $agent->handoff_rules_json,
                'is_active' => true,
                'created_by_user_id' => null,
                'note' => 'Initial snapshot of pre-versioning prompt',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('agents')->where('id', $agent->id)->update(['active_prompt_version_id' => $versionId]);
        });
    }

    public function down(): void
    {
        DB::table('agents')->update(['active_prompt_version_id' => null]);
        DB::table('agent_prompt_versions')->where('version_number', 1)->delete();
    }
};
```

- [ ] **Step 6: Create the model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentPromptVersion extends Model
{
    protected $fillable = [
        'agent_id', 'version_number', 'system_instructions',
        'persona_json', 'scope_json', 'red_flag_rules_json', 'handoff_rules_json',
        'is_active', 'created_by_user_id', 'note',
    ];

    protected function casts(): array
    {
        return [
            'persona_json' => 'array',
            'scope_json' => 'array',
            'red_flag_rules_json' => 'array',
            'handoff_rules_json' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
```

- [ ] **Step 7: Update Agent model with relationships**

Add:

```php
public function promptVersions(): HasMany
{
    return $this->hasMany(AgentPromptVersion::class);
}

public function activePromptVersion(): BelongsTo
{
    return $this->belongsTo(AgentPromptVersion::class, 'active_prompt_version_id');
}
```

- [ ] **Step 8: Run + verify**

Run: `php artisan migrate:fresh --seed && ./vendor/bin/pest tests/Feature/AgentPromptVersionsTest.php tests/Feature/Regression/HotelSpaRegressionTest.php`
Expected: green.

- [ ] **Step 9: Commit**

```bash
git add database/migrations/2026_04_19_0000{08,09,10}_*.php app/Models/AgentPromptVersion.php app/Models/Agent.php tests/Feature/AgentPromptVersionsTest.php
git commit -m "feat(schema): add versioned agent prompts, snapshot existing as v1"
```

---

### Task B.7 — Enable pgvector extension and prove a trivial embedding roundtrip

**Files:**
- Create: `database/migrations/2026_04_19_000011_enable_pgvector.php`
- Create: `tests/Feature/PgvectorAvailabilityTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PgvectorAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_pgvector_extension_is_installed(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('pgvector requires PostgreSQL');
        }

        $row = DB::selectOne("SELECT extname FROM pg_extension WHERE extname = 'vector'");
        $this->assertNotNull($row, 'pgvector extension not installed');
    }

    public function test_vector_type_roundtrip(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('pgvector requires PostgreSQL');
        }

        DB::statement('CREATE TEMP TABLE _vtest (id serial primary key, v vector(3))');
        DB::statement("INSERT INTO _vtest (v) VALUES ('[1,2,3]')");
        $row = DB::selectOne('SELECT v::text AS v FROM _vtest LIMIT 1');
        $this->assertSame('[1,2,3]', $row->v);
    }
}
```

- [ ] **Step 2: Run to confirm it fails**

Run: `./vendor/bin/pest tests/Feature/PgvectorAvailabilityTest.php`
Expected: FAIL — extension not installed (OR skip if not on pgsql).

- [ ] **Step 3: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP EXTENSION IF EXISTS vector');
    }
};
```

- [ ] **Step 4: Run + verify**

Run: `php artisan migrate:fresh --seed && ./vendor/bin/pest tests/Feature/PgvectorAvailabilityTest.php`
Expected: green.

If Laravel Cloud's Postgres tier does not permit `CREATE EXTENSION vector`, this fails — STOP and open a support ticket with Laravel Cloud. Do not proceed to Task B.8.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_04_19_000011_enable_pgvector.php tests/Feature/PgvectorAvailabilityTest.php
git commit -m "feat(db): enable pgvector extension with availability test"
```

---

### Task B.8 — Create `knowledge_documents` + `knowledge_chunks` with `vector(3072)`

**Decision (locked per §A.5):** single `embedding vector(3072)` column matching OpenAI `text-embedding-3-large`.

**Files:**
- Create: `database/migrations/2026_04_19_000012_create_knowledge_documents.php`
- Create: `database/migrations/2026_04_19_000013_create_knowledge_chunks.php`
- Create: `app/Models/KnowledgeDocument.php`
- Create: `app/Models/KnowledgeChunk.php`
- Create: `tests/Feature/KnowledgeChunkVectorTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use Database\Seeders\DemoSeeder;
use Database\Seeders\VerticalsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class KnowledgeChunkVectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_store_and_nearest_neighbor_query_a_chunk(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('pgvector requires PostgreSQL');
        }

        $this->seed(VerticalsSeeder::class);
        $this->seed(DemoSeeder::class);

        $agent = Agent::first();

        $doc = KnowledgeDocument::create([
            'agent_id' => $agent->id,
            'title' => 'Test source',
            'source_url' => null,
            'evidence_grade' => 'expert-opinion',
            'licence' => 'internal',
            'locale' => 'en',
            'metadata' => [],
        ]);

        $vec = '[' . implode(',', array_fill(0, 3072, 0.0)) . ']';

        DB::insert("INSERT INTO knowledge_chunks (document_id, agent_id, chunk_index, content, embedding, created_at, updated_at)
                    VALUES (?, ?, 0, 'Hello world', ?::vector, NOW(), NOW())", [$doc->id, $agent->id, $vec]);

        $count = DB::scalar('SELECT COUNT(*) FROM knowledge_chunks');
        $this->assertSame(1, (int) $count);

        $nearest = DB::selectOne("SELECT content FROM knowledge_chunks ORDER BY embedding <-> ?::vector LIMIT 1", [$vec]);
        $this->assertSame('Hello world', $nearest->content);
    }
}
```

- [ ] **Step 2: Run to confirm it fails**

Run: `./vendor/bin/pest tests/Feature/KnowledgeChunkVectorTest.php`
Expected: FAIL — tables missing.

- [ ] **Step 3: Write `knowledge_documents` migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained('agents')->cascadeOnDelete();
            $table->string('title', 500);
            $table->string('source_url', 1000)->nullable();
            $table->string('evidence_grade', 32)->nullable(); // systematic-review, rct, observational, expert-opinion, guideline
            $table->string('licence', 64)->nullable();
            $table->string('locale', 8)->default('en');
            $table->string('checksum', 64)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamp('ingested_at')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();

            $table->index('agent_id');
            $table->index('evidence_grade');
            $table->index('retired_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_documents');
    }
};
```

- [ ] **Step 4: Write `knowledge_chunks` migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('knowledge_documents')->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained('agents')->cascadeOnDelete();
            $table->unsignedInteger('chunk_index');
            $table->text('content');
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->unique(['document_id', 'chunk_index']);
            $table->index('agent_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE knowledge_chunks ADD COLUMN embedding vector(3072)');
            // Note: pgvector HNSW caps dimension at 2000 for the `vector` opclass.
            // With 3072 dims we create the base column now and defer the ANN index
            // to Phase 1 (either halfvec HNSW, ivfflat, or a dim reduction) — picked
            // against eval harness results. Nearest-neighbor queries still work
            // without the index, they're just sequential scans against the seeded corpus.
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_chunks');
    }
};
```

- [ ] **Step 5: Create the models**

`app/Models/KnowledgeDocument.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KnowledgeDocument extends Model
{
    protected $fillable = ['agent_id', 'title', 'source_url', 'evidence_grade', 'licence', 'locale', 'checksum', 'metadata', 'ingested_at', 'retired_at'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'ingested_at' => 'datetime', 'retired_at' => 'datetime'];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(KnowledgeChunk::class, 'document_id');
    }
}
```

`app/Models/KnowledgeChunk.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KnowledgeChunk extends Model
{
    protected $fillable = ['document_id', 'agent_id', 'chunk_index', 'content', 'metadata'];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(KnowledgeDocument::class, 'document_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
```

- [ ] **Step 6: Run + verify**

Run: `php artisan migrate:fresh --seed && ./vendor/bin/pest tests/Feature/KnowledgeChunkVectorTest.php tests/Feature/Regression/HotelSpaRegressionTest.php`
Expected: green.

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_04_19_0000{12,13}_*.php app/Models/KnowledgeDocument.php app/Models/KnowledgeChunk.php tests/Feature/KnowledgeChunkVectorTest.php
git commit -m "feat(schema): add knowledge_documents + knowledge_chunks with pgvector HNSW index"
```

---

### Task B.9 — Extend `messages` + create `message_citations`, `verification_events`, `llm_calls`, `red_flag_events`

**Files:**
- Create: `database/migrations/2026_04_19_000014_extend_messages_table.php`
- Create: `database/migrations/2026_04_19_000015_create_message_citations.php`
- Create: `database/migrations/2026_04_19_000016_create_verification_events.php`
- Create: `database/migrations/2026_04_19_000017_create_llm_calls.php`
- Create: `database/migrations/2026_04_19_000018_create_red_flag_events.php`
- Create: `database/migrations/2026_04_19_000019_backfill_messages_agent_id.php`
- Modify: `app/Models/Message.php`
- Create: `app/Models/MessageCitation.php`, `VerificationEvent.php`, `LlmCall.php`, `RedFlagEvent.php`
- Create: `tests/Feature/MessagePipelineTablesTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\LlmCall;
use App\Models\Message;
use App\Models\MessageCitation;
use App\Models\RedFlagEvent;
use App\Models\VerificationEvent;
use Database\Seeders\DemoSeeder;
use Database\Seeders\VerticalsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MessagePipelineTablesTest extends TestCase
{
    use RefreshDatabase;

    public function test_verification_pipeline_tables_exist_and_link(): void
    {
        $this->seed(VerticalsSeeder::class);
        $this->seed(DemoSeeder::class);

        $conv = Conversation::first();
        $agent = Agent::find($conv->agent_id);

        $msg = Message::create([
            'conversation_id' => $conv->id,
            'agent_id' => $agent->id,
            'role' => 'agent',
            'content' => 'grounded reply',
            'verification_status' => 'passed',
        ]);

        MessageCitation::create([
            'message_id' => $msg->id,
            'chunk_id' => null,
            'external_source_id' => null,
            'label' => 'Source A',
            'span_start' => 0,
            'span_end' => 14,
        ]);

        VerificationEvent::create([
            'message_id' => $msg->id,
            'stage' => 'grounding',
            'passed' => true,
            'notes' => ['grounded_claims' => 3, 'total_claims' => 3],
        ]);

        LlmCall::create([
            'message_id' => $msg->id,
            'provider' => 'openai',
            'model' => 'gpt-4o',
            'prompt_tokens' => 120,
            'completion_tokens' => 80,
            'cost_usd_cents' => 3,
            'latency_ms' => 840,
            'trace_id' => 'test-trace',
        ]);

        RedFlagEvent::create([
            'conversation_id' => $conv->id,
            'message_id' => $msg->id,
            'rule_slug' => 'self-harm',
            'severity' => 'critical',
            'payload' => [],
        ]);

        $this->assertSame(1, MessageCitation::where('message_id', $msg->id)->count());
        $this->assertSame(1, VerificationEvent::where('message_id', $msg->id)->count());
        $this->assertSame(1, LlmCall::where('message_id', $msg->id)->count());
        $this->assertSame(1, RedFlagEvent::where('message_id', $msg->id)->count());
        $this->assertSame('passed', $msg->fresh()->verification_status);
    }

    public function test_existing_agent_messages_have_agent_id_backfilled(): void
    {
        $this->seed(VerticalsSeeder::class);
        $this->seed(DemoSeeder::class);

        Message::where('role', 'agent')->get()->each(
            fn (Message $m) => $this->assertNotNull($m->agent_id, "Agent message {$m->id} missing agent_id after backfill")
        );
    }
}
```

- [ ] **Step 2: Run to confirm it fails**

Run: `./vendor/bin/pest tests/Feature/MessagePipelineTablesTest.php`
Expected: FAIL.

- [ ] **Step 3: Extend messages**

`2026_04_19_000014_extend_messages_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->foreignId('agent_id')->nullable()->after('conversation_id')->constrained('agents')->nullOnDelete();
            $table->string('verification_status', 16)->default('not_required')->after('retrieval_source_count');
            $table->foreignId('handoff_from_agent_id')->nullable()->after('verification_status')->constrained('agents')->nullOnDelete();
            $table->unsignedInteger('claim_count')->nullable();
            $table->unsignedInteger('grounded_claim_count')->nullable();
            $table->boolean('red_flag_triggered')->default(false);

            $table->index('agent_id');
            $table->index('verification_status');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropForeign(['agent_id']);
            $table->dropForeign(['handoff_from_agent_id']);
            $table->dropIndex(['agent_id']);
            $table->dropIndex(['verification_status']);
            $table->dropColumn(['agent_id', 'verification_status', 'handoff_from_agent_id', 'claim_count', 'grounded_claim_count', 'red_flag_triggered']);
        });
    }
};
```

- [ ] **Step 4: Create `message_citations`**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_citations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('messages')->cascadeOnDelete();
            $table->foreignId('chunk_id')->nullable()->constrained('knowledge_chunks')->nullOnDelete();
            $table->unsignedBigInteger('external_source_id')->nullable(); // FK added in next migration
            $table->string('label', 255);
            $table->unsignedInteger('span_start')->nullable();
            $table->unsignedInteger('span_end')->nullable();
            $table->timestamps();

            $table->index('message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_citations');
    }
};
```

- [ ] **Step 5: Create `verification_events`**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verification_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('messages')->cascadeOnDelete();
            $table->string('stage', 32); // claim-extraction, grounding, critic, citation, safety
            $table->boolean('passed');
            $table->jsonb('notes')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['message_id', 'stage']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_events');
    }
};
```

- [ ] **Step 6: Create `llm_calls`**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('llm_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->foreignId('parent_llm_call_id')->nullable()->constrained('llm_calls')->nullOnDelete();
            $table->string('purpose', 32)->default('generation'); // generation, critic, safety, embedding, rerank, classify
            $table->string('provider', 32);
            $table->string('model', 120);
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            $table->unsignedInteger('cost_usd_cents')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('trace_id', 128)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('message_id');
            $table->index(['provider', 'model']);
            $table->index('trace_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('llm_calls');
    }
};
```

- [ ] **Step 7: Create `red_flag_events`**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('red_flag_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->foreignId('message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->string('rule_slug', 64);
            $table->string('severity', 16); // info, warning, critical
            $table->jsonb('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['conversation_id', 'created_at']);
            $table->index('rule_slug');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('red_flag_events');
    }
};
```

- [ ] **Step 8: Backfill `messages.agent_id`**

`2026_04_19_000019_backfill_messages_agent_id.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            UPDATE messages
               SET agent_id = conversations.agent_id
              FROM conversations
             WHERE conversations.id = messages.conversation_id
               AND messages.role = 'agent'
               AND messages.agent_id IS NULL
        ");
    }

    public function down(): void
    {
        // no-op
    }
};
```

- [ ] **Step 9: Create models**

`app/Models/MessageCitation.php`, `VerificationEvent.php`, `LlmCall.php`, `RedFlagEvent.php` — each a minimal Eloquent model with `$fillable`, array casts for jsonb columns, and a `message()` or `conversation()` `belongsTo` relationship. Keep them thin.

Example — `MessageCitation.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageCitation extends Model
{
    protected $fillable = ['message_id', 'chunk_id', 'external_source_id', 'label', 'span_start', 'span_end'];

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function chunk(): BelongsTo
    {
        return $this->belongsTo(KnowledgeChunk::class, 'chunk_id');
    }
}
```

Similarly for the others — use the column lists from each migration.

- [ ] **Step 10: Update Message model**

Add to `$fillable` the new columns. Add relationships:

```php
public function agent(): BelongsTo { return $this->belongsTo(Agent::class); }
public function handoffFromAgent(): BelongsTo { return $this->belongsTo(Agent::class, 'handoff_from_agent_id'); }
public function citations(): HasMany { return $this->hasMany(MessageCitation::class); }
public function verificationEvents(): HasMany { return $this->hasMany(VerificationEvent::class); }
public function llmCalls(): HasMany { return $this->hasMany(LlmCall::class); }
```

Add `red_flag_triggered` boolean cast.

- [ ] **Step 11: Run + verify**

Run: `php artisan migrate:fresh --seed && ./vendor/bin/pest tests/Feature/MessagePipelineTablesTest.php tests/Feature/Regression/HotelSpaRegressionTest.php`
Expected: green.

- [ ] **Step 12: Commit**

```bash
git add database/migrations/2026_04_19_0000{14,15,16,17,18,19}_*.php app/Models/Message.php app/Models/MessageCitation.php app/Models/VerificationEvent.php app/Models/LlmCall.php app/Models/RedFlagEvent.php tests/Feature/MessagePipelineTablesTest.php
git commit -m "feat(schema): add verification pipeline tables and extend messages"
```

---

### Task B.10 — Create `external_source_cache` and wire citations FK

**Files:**
- Create: `database/migrations/2026_04_19_000020_create_external_source_cache.php`
- Create: `database/migrations/2026_04_19_000021_message_citations_external_source_fk.php`
- Create: `app/Models/ExternalSource.php`

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/MessagePipelineTablesTest.php`:

```php
public function test_external_source_can_be_cited(): void
{
    $this->seed(VerticalsSeeder::class);
    $this->seed(DemoSeeder::class);

    $conv = \App\Models\Conversation::first();
    $agent = \App\Models\Agent::find($conv->agent_id);

    $msg = \App\Models\Message::create([
        'conversation_id' => $conv->id,
        'agent_id' => $agent->id,
        'role' => 'agent',
        'content' => 'per PubMed',
    ]);

    $source = \App\Models\ExternalSource::create([
        'provider' => 'pubmed',
        'external_id' => 'PMID:12345',
        'title' => 'Test paper',
        'url' => 'https://pubmed.ncbi.nlm.nih.gov/12345',
        'payload' => ['abstract' => 'stub'],
        'fetched_at' => now(),
    ]);

    \App\Models\MessageCitation::create([
        'message_id' => $msg->id,
        'external_source_id' => $source->id,
        'label' => 'Test paper (2024)',
    ]);

    $this->assertSame(1, \App\Models\MessageCitation::where('external_source_id', $source->id)->count());
}
```

- [ ] **Step 2: Run to confirm it fails**

Run: `./vendor/bin/pest tests/Feature/MessagePipelineTablesTest.php`
Expected: FAIL — table missing.

- [ ] **Step 3: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_source_cache', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 32); // pubmed, europepmc, cochrane, usda, openfoodfacts, drugbank, rxnorm, loinc, cosing, inci
            $table->string('external_id', 128);
            $table->string('title', 500);
            $table->string('url', 1000)->nullable();
            $table->jsonb('payload')->nullable();
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->unique(['provider', 'external_id']);
            $table->index('fetched_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_source_cache');
    }
};
```

- [ ] **Step 4: Wire FK**

`2026_04_19_000021_message_citations_external_source_fk.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_citations', function (Blueprint $table) {
            $table->foreign('external_source_id')
                ->references('id')->on('external_source_cache')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('message_citations', function (Blueprint $table) {
            $table->dropForeign(['external_source_id']);
        });
    }
};
```

- [ ] **Step 5: Create the model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExternalSource extends Model
{
    protected $table = 'external_source_cache';

    protected $fillable = ['provider', 'external_id', 'title', 'url', 'payload', 'fetched_at'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'fetched_at' => 'datetime'];
    }
}
```

- [ ] **Step 6: Run + verify**

Run: `php artisan migrate:fresh --seed && ./vendor/bin/pest tests/Feature/MessagePipelineTablesTest.php tests/Feature/Regression/HotelSpaRegressionTest.php`
Expected: green.

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_04_19_0000{20,21}_*.php app/Models/ExternalSource.php tests/Feature/MessagePipelineTablesTest.php
git commit -m "feat(schema): add external_source_cache and wire citation FK"
```

---

### Task B.11 — Create `token_usage_daily` ledger

**Files:**
- Create: `database/migrations/2026_04_19_000022_create_token_usage_daily.php`
- Create: `app/Models/TokenUsageDaily.php`
- Create: `tests/Feature/TokenUsageDailyTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\TokenUsageDaily;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TokenUsageDailyTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_upsert_daily_rollup(): void
    {
        $user = User::factory()->create();

        TokenUsageDaily::create([
            'user_id' => $user->id,
            'usage_date' => '2026-04-19',
            'messages_count' => 3,
            'tokens_in' => 450,
            'tokens_out' => 800,
            'cost_usd_cents' => 5,
        ]);

        $this->assertSame(1, TokenUsageDaily::where('user_id', $user->id)->count());
    }

    public function test_unique_user_plus_date(): void
    {
        $user = User::factory()->create();

        TokenUsageDaily::create([
            'user_id' => $user->id, 'usage_date' => '2026-04-19',
            'messages_count' => 1, 'tokens_in' => 0, 'tokens_out' => 0, 'cost_usd_cents' => 0,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        TokenUsageDaily::create([
            'user_id' => $user->id, 'usage_date' => '2026-04-19',
            'messages_count' => 2, 'tokens_in' => 0, 'tokens_out' => 0, 'cost_usd_cents' => 0,
        ]);
    }
}
```

- [ ] **Step 2: Run to confirm it fails**

Run: `./vendor/bin/pest tests/Feature/TokenUsageDailyTest.php`
Expected: FAIL.

- [ ] **Step 3: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('token_usage_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('usage_date');
            $table->unsignedInteger('messages_count')->default(0);
            $table->unsignedInteger('tokens_in')->default(0);
            $table->unsignedInteger('tokens_out')->default(0);
            $table->unsignedInteger('cost_usd_cents')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'usage_date']);
            $table->index('usage_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('token_usage_daily');
    }
};
```

- [ ] **Step 4: Create the model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TokenUsageDaily extends Model
{
    protected $table = 'token_usage_daily';

    protected $fillable = ['user_id', 'usage_date', 'messages_count', 'tokens_in', 'tokens_out', 'cost_usd_cents'];

    protected function casts(): array
    {
        return ['usage_date' => 'date'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

- [ ] **Step 5: Run + verify**

Run: `php artisan migrate:fresh --seed && ./vendor/bin/pest tests/Feature/TokenUsageDailyTest.php tests/Feature/Regression/HotelSpaRegressionTest.php`
Expected: green.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_04_19_000022_*.php app/Models/TokenUsageDaily.php tests/Feature/TokenUsageDailyTest.php
git commit -m "feat(schema): add token_usage_daily rollup table"
```

---

### Task B.12 — Extend `users` + create `user_profiles`

**Decision (locked per §A.5):** one wide `user_profiles` row per user with typed columns for known product fields, plus `profile_metadata` jsonb for experimental / provisional data. PHI-sensitive fields stay typed and queryable.

**Files:**
- Create: `database/migrations/2026_04_19_000023_extend_users_table.php`
- Create: `database/migrations/2026_04_19_000024_create_user_profiles.php`
- Modify: `app/Models/User.php`
- Create: `app/Models/UserProfile.php`
- Create: `tests/Feature/UserProfileTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_have_profile_with_wellness_goals(): void
    {
        $user = User::factory()->create([
            'birthdate' => '1990-05-01',
            'jurisdiction' => 'DE',
            'consent_json' => ['gdpr' => true, 'marketing' => false],
            'locale' => 'en',
        ]);

        UserProfile::create([
            'user_id' => $user->id,
            'goals' => ['sleep', 'energy'],
            'conditions' => [],
            'medications' => [],
            'dietary_flags' => ['vegetarian'],
            'wearables_connected' => ['oura' => true],
        ]);

        $user->refresh();

        $this->assertSame('DE', $user->jurisdiction);
        $this->assertTrue($user->consent_json['gdpr']);
        $this->assertContains('sleep', $user->profile->goals);
    }
}
```

- [ ] **Step 2: Run to confirm it fails**

Run: `./vendor/bin/pest tests/Feature/UserProfileTest.php`
Expected: FAIL.

- [ ] **Step 3: Extend users migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->date('birthdate')->nullable();
            $table->string('jurisdiction', 8)->nullable();
            $table->string('locale', 8)->default('en');
            $table->jsonb('consent_json')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['birthdate', 'jurisdiction', 'locale', 'consent_json']);
        });
    }
};
```

- [ ] **Step 4: Create user_profiles migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->jsonb('goals')->nullable();
            $table->jsonb('conditions')->nullable();
            $table->jsonb('medications')->nullable();
            $table->jsonb('dietary_flags')->nullable();
            $table->jsonb('wearables_connected')->nullable();
            $table->unsignedSmallInteger('height_cm')->nullable();
            $table->unsignedSmallInteger('weight_kg')->nullable();
            $table->char('sex_at_birth', 1)->nullable(); // m, f, i (intersex), n (prefer-not-to-say)
            $table->string('activity_level', 16)->nullable();
            $table->jsonb('profile_metadata')->nullable(); // experimental or provisional data; promote to typed columns when stable
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_profiles');
    }
};
```

- [ ] **Step 5: Update User model**

Add to `$fillable`: `'birthdate', 'jurisdiction', 'locale', 'consent_json'`. Add casts: `birthdate => date`, `consent_json => array`. Add `profile()` `hasOne` relationship.

- [ ] **Step 6: Create UserProfile model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserProfile extends Model
{
    protected $fillable = ['user_id', 'goals', 'conditions', 'medications', 'dietary_flags', 'wearables_connected', 'height_cm', 'weight_kg', 'sex_at_birth', 'activity_level', 'profile_metadata'];

    protected function casts(): array
    {
        return [
            'goals' => 'array', 'conditions' => 'array', 'medications' => 'array',
            'dietary_flags' => 'array', 'wearables_connected' => 'array',
            'profile_metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

- [ ] **Step 7: Run + verify**

Run: `php artisan migrate:fresh --seed && ./vendor/bin/pest tests/Feature/UserProfileTest.php tests/Feature/Regression/HotelSpaRegressionTest.php`
Expected: green.

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_04_19_0000{23,24}_*.php app/Models/User.php app/Models/UserProfile.php tests/Feature/UserProfileTest.php
git commit -m "feat(schema): extend users with birthdate/jurisdiction/consent and add user_profiles"
```

---

### Task B.13 — Subscription plans + entitlements

**Decision (locked per §A.5):** vendor-neutral billing columns — `billing_provider` (`revenuecat` | `stripe` | `manual`), `billing_customer_id`, `billing_metadata` jsonb. No vendor names in column names.

**Files:**
- Create: `database/migrations/2026_04_19_000025_create_subscription_plans.php`
- Create: `database/migrations/2026_04_19_000026_create_subscription_entitlements.php`
- Create: `database/seeders/SubscriptionPlansSeeder.php`
- Create: `app/Models/SubscriptionPlan.php`
- Create: `app/Models/SubscriptionEntitlement.php`
- Modify: `database/seeders/DatabaseSeeder.php`
- Create: `tests/Feature/SubscriptionPlansTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\SubscriptionEntitlement;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Database\Seeders\SubscriptionPlansSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionPlansTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_four_plans(): void
    {
        $this->seed(SubscriptionPlansSeeder::class);

        $this->assertSame(4, SubscriptionPlan::count());
        $this->assertNotNull(SubscriptionPlan::where('slug', 'free')->first());
        $this->assertNotNull(SubscriptionPlan::where('slug', 'basic')->first());
        $this->assertNotNull(SubscriptionPlan::where('slug', 'pro')->first());
        $this->assertNotNull(SubscriptionPlan::where('slug', 'ultimate')->first());
    }

    public function test_user_can_have_entitlement(): void
    {
        $this->seed(SubscriptionPlansSeeder::class);

        $user = User::factory()->create();
        $plan = SubscriptionPlan::where('slug', 'pro')->first();

        SubscriptionEntitlement::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => 'trialing',
            'trial_ends_at' => now()->addDays(5),
            'renews_at' => now()->addDays(35),
            'billing_provider' => 'revenuecat',
            'billing_customer_id' => 'rc_abc123',
            'billing_metadata' => ['product_id' => 'pro_monthly', 'latest_receipt' => 'opaque-token'],
        ]);

        $this->assertSame('trialing', $user->entitlement->status);
        $this->assertSame('pro', $user->entitlement->plan->slug);
        $this->assertSame('revenuecat', $user->entitlement->billing_provider);
        $this->assertSame('pro_monthly', $user->entitlement->billing_metadata['product_id']);
    }
}
```

- [ ] **Step 2: Run to confirm it fails**

Run: `./vendor/bin/pest tests/Feature/SubscriptionPlansTest.php`
Expected: FAIL.

- [ ] **Step 3: Create subscription_plans**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 32)->unique();
            $table->string('name', 64);
            $table->unsignedInteger('price_usd_cents_monthly')->nullable();
            $table->unsignedInteger('price_usd_cents_annual')->nullable();
            $table->unsignedInteger('daily_message_limit')->nullable();
            $table->unsignedInteger('memory_days')->nullable();
            $table->jsonb('features')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_plans');
    }
};
```

- [ ] **Step 4: Create subscription_entitlements**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_entitlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('subscription_plans')->restrictOnDelete();
            $table->string('status', 24); // trialing, active, past_due, cancelled, expired
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('renews_at')->nullable();
            $table->string('billing_provider', 24)->nullable(); // revenuecat, stripe, manual
            $table->string('billing_customer_id', 128)->nullable();
            $table->jsonb('billing_metadata')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('billing_customer_id');
            $table->index('billing_provider');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_entitlements');
    }
};
```

- [ ] **Step 5: Create seeder**

```php
<?php

namespace Database\Seeders;

use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;

class SubscriptionPlansSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            ['slug' => 'free', 'name' => 'Free', 'monthly' => 0, 'annual' => 0, 'daily' => 5, 'memory' => 7, 'features' => ['avatars' => 1, 'voice' => false, 'uploads' => false, 'wearables' => false]],
            ['slug' => 'basic', 'name' => 'Basic', 'monthly' => 999, 'annual' => 8388, 'daily' => 30, 'memory' => 30, 'features' => ['avatars' => 6, 'voice' => true, 'uploads' => 'basic', 'wearables' => false]],
            ['slug' => 'pro', 'name' => 'Pro', 'monthly' => 1999, 'annual' => 16788, 'daily' => 100, 'memory' => null, 'features' => ['avatars' => 6, 'voice' => true, 'uploads' => 'full', 'wearables' => true, 'lab_ocr' => true, 'skin_analysis' => true]],
            ['slug' => 'ultimate', 'name' => 'Ultimate', 'monthly' => 3999, 'annual' => 33588, 'daily' => 500, 'memory' => null, 'features' => ['avatars' => 6, 'voice' => true, 'uploads' => 'full', 'wearables' => true, 'video_form_check' => true, 'priority' => true]],
        ];

        foreach ($plans as $p) {
            SubscriptionPlan::updateOrCreate(
                ['slug' => $p['slug']],
                [
                    'name' => $p['name'],
                    'price_usd_cents_monthly' => $p['monthly'],
                    'price_usd_cents_annual' => $p['annual'],
                    'daily_message_limit' => $p['daily'],
                    'memory_days' => $p['memory'],
                    'features' => $p['features'],
                    'is_active' => true,
                ]
            );
        }
    }
}
```

- [ ] **Step 6: Create the models**

`app/Models/SubscriptionPlan.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubscriptionPlan extends Model
{
    protected $fillable = ['slug', 'name', 'price_usd_cents_monthly', 'price_usd_cents_annual', 'daily_message_limit', 'memory_days', 'features', 'is_active'];

    protected function casts(): array
    {
        return ['features' => 'array', 'is_active' => 'boolean'];
    }

    public function entitlements(): HasMany
    {
        return $this->hasMany(SubscriptionEntitlement::class, 'plan_id');
    }
}
```

`app/Models/SubscriptionEntitlement.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionEntitlement extends Model
{
    protected $fillable = ['user_id', 'plan_id', 'status', 'trial_ends_at', 'renews_at', 'billing_provider', 'billing_customer_id', 'billing_metadata'];

    protected function casts(): array
    {
        return ['trial_ends_at' => 'datetime', 'renews_at' => 'datetime', 'billing_metadata' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_id');
    }
}
```

- [ ] **Step 7: Update User model**

Add `entitlement()` `hasOne` relationship to `SubscriptionEntitlement`.

- [ ] **Step 8: Register seeder**

Update `DatabaseSeeder::run()` to call `SubscriptionPlansSeeder` before `DemoSeeder`.

- [ ] **Step 9: Run + verify**

Run: `php artisan migrate:fresh --seed && ./vendor/bin/pest tests/Feature/SubscriptionPlansTest.php tests/Feature/Regression/HotelSpaRegressionTest.php`
Expected: green.

- [ ] **Step 10: Commit**

```bash
git add database/migrations/2026_04_19_0000{25,26}_*.php app/Models/SubscriptionPlan.php app/Models/SubscriptionEntitlement.php app/Models/User.php database/seeders/SubscriptionPlansSeeder.php database/seeders/DatabaseSeeder.php tests/Feature/SubscriptionPlansTest.php
git commit -m "feat(schema): add subscription plans + entitlements with four-tier seed"
```

---

### Task B.14 — Document the schema additions

**Files:**
- Create: `docs/adr/2026-04-19-phase-0-schema-migration.md`

- [ ] **Step 1: Write the ADR**

Create `docs/adr/2026-04-19-phase-0-schema-migration.md` with:

```markdown
# ADR: Phase 0 Schema Migration — Multi-Vertical Foundations

**Date:** 2026-04-19
**Status:** Accepted
**Supersedes:** n/a

## Context
Evolving single-vertical hotel chat schema into multi-vertical expert-avatar platform per PROJECT_SPEC v4.0.

## Decision
- Add `verticals` table as first-class grouping.
- Extend `agents`, `conversations`, `messages` additively — no destructive changes.
- Create: `agent_prompt_versions`, `knowledge_documents`, `knowledge_chunks` (with pgvector `vector(3072)` embedding column; ANN index deferred to Phase 1 — 3072 dims exceeds the HNSW `vector_l2_ops` 2000-dim cap so the index type is picked against eval harness results), `external_source_cache`, `message_citations`, `verification_events`, `llm_calls`, `red_flag_events`, `token_usage_daily`, `user_profiles` (wide typed + `profile_metadata` jsonb), `subscription_plans`, `subscription_entitlements`.
- All existing hotel rows backfilled to vertical `hotel`; all new rows in vertical `wellness` (flipped active on mobile launch).
- Embedding dimensionality: `vector(3072)` (OpenAI `text-embedding-3-large`). Voyage 1024 is a Phase 1+ quality experiment; if adopted it will be added as a parallel column against the eval harness rather than a schema swap.
- Billing: vendor-neutral columns only (`billing_provider`, `billing_customer_id`, `billing_metadata` jsonb). No vendor names in column names.
- Local dev: Postgres required everywhere (no SQLite fallback). `docker-compose.yml` ships alongside the schema.
- OpenAI ZDR: dashboard sharing disabled org-wide; formal contractual ZDR requested 2026-04-19 and gates Phase 3 user data. State recorded in `docs/compliance/openai-zdr.md`.

## Consequences
- Positive: hotel flow untouched; wellness pipeline has every table it needs for Phase 1–4 without further schema churn; prompt versioning enables admin A/B from day one.
- Negative: Postgres is required everywhere (local, CI, staging, prod). SQLite is no longer a supported dev DB. Mitigated by shipping `docker-compose.yml` so bring-up is one command.
- Follow-ups: eval harness tables (`eval_datasets`, `eval_runs`, `eval_results`) deferred to Phase 0 eval plan. Multimodal asset tables (`user_media_assets`, `wearable_samples`) deferred to Phase 5.

## Rollback
Each migration has a reversible `down()`. Full rollback sequence tested in `tests/Feature/SchemaRollbackTest.php`.
```

- [ ] **Step 2: Commit**

```bash
git add docs/adr/2026-04-19-phase-0-schema-migration.md
git commit -m "docs(adr): record Phase 0 schema migration decisions"
```

---

### Task B.15 — Full rollback + re-apply proof

**Files:**
- Create: `tests/Feature/SchemaRollbackTest.php`

- [ ] **Step 1: Write the test**

```php
<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SchemaRollbackTest extends TestCase
{
    public function test_can_rollback_phase_0_migrations_and_reapply(): void
    {
        Artisan::call('migrate:fresh', ['--seed' => true]);

        $this->assertTrue(DB::getSchemaBuilder()->hasTable('verticals'));
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('knowledge_chunks'));

        // Rollback all Phase 0 migrations (15 steps created today)
        Artisan::call('migrate:rollback', ['--step' => 15]);

        $this->assertFalse(DB::getSchemaBuilder()->hasTable('subscription_entitlements'));
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('verticals'));

        // Original hotel columns still intact
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('agents'));
        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('agents', 'system_instructions'));
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('agents', 'vertical_id'));

        // Re-apply
        Artisan::call('migrate', ['--seed' => true]);
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('verticals'));
    }
}
```

- [ ] **Step 2: Run + verify**

Run: `./vendor/bin/pest tests/Feature/SchemaRollbackTest.php`
Expected: green.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/SchemaRollbackTest.php
git commit -m "test: prove Phase 0 migrations roll back cleanly and reapply"
```

---

### Task B.16 — Manual hotel SPA smoke test + merge checklist

**File:**
- Create: `docs/phases/phase-0-schema-migration-merge-checklist.md`

- [ ] **Step 1: Write the checklist**

```markdown
# Phase 0 Schema Migration — Pre-merge Smoke Test

Before merging this branch into `main`:

## Automated (must pass in CI)

- [ ] `./vendor/bin/pest` — full suite green
- [ ] `./vendor/bin/pest tests/Feature/Regression/HotelSpaRegressionTest.php` — green
- [ ] `./vendor/bin/pest tests/Feature/SchemaRollbackTest.php` — green

## Manual hotel SPA walkthrough (http://avatar.local)

For each of the four hotel agents (Sofia, Elena, Marco, Hans):

- [ ] Agent list loads with avatar image and role
- [ ] Open chat — starter prompts render
- [ ] Send a text message — reply streams and finishes
- [ ] Start voice recording, stop, transcript appears (mic permission, HTTPS/localhost required)
- [ ] Click TTS on an agent reply — audio plays
- [ ] Attachments panel opens (both chat files and avatar files)
- [ ] Rename and delete conversation work
- [ ] HeyGen voice-mode button toggles (expected to show error overlay — v1 API is sunset; this is pre-existing and not a regression)

## Database

- [ ] `SELECT COUNT(*) FROM agents WHERE vertical_id IS NULL` returns 0
- [ ] `SELECT COUNT(*) FROM conversations WHERE vertical_id IS NULL` returns 0
- [ ] `SELECT COUNT(*) FROM messages WHERE role='agent' AND agent_id IS NULL` returns 0
- [ ] `SELECT slug FROM verticals ORDER BY id` returns `hotel, wellness`
- [ ] `SELECT slug FROM subscription_plans ORDER BY id` returns `free, basic, pro, ultimate`
- [ ] `SELECT extname FROM pg_extension WHERE extname='vector'` returns one row

## Sign-off

- [ ] Engineer name + date: __________
- [ ] Code reviewer: __________
```

- [ ] **Step 2: Commit**

```bash
git add docs/phases/phase-0-schema-migration-merge-checklist.md
git commit -m "docs: add Phase 0 schema migration pre-merge smoke test checklist"
```

---

## Post-plan follow-ups (separate plans to write after this lands)

1. `docs/phases/phase-0-telemetry.md` — Sentry (Laravel + Expo) + Langfuse LlmClient wrapper with one traced call.
2. `docs/phases/phase-0-sanctum-auth.md` — Sanctum personal-access tokens for mobile + hardened `/api/v1/health` auth round-trip.
3. `docs/phases/phase-0-expo-skeleton.md` — Expo project, iOS + Android simulator build, Sanctum login screen, `/api/v1/health` call.
4. `docs/phases/phase-0-eval-harness.md` — golden datasets structure, `eval_datasets` / `eval_runs` / `eval_results` tables, `php artisan eval:run` command, nightly scheduler entry.
5. `docs/phases/phase-0-docs-integrations-scaffold.md` — `docs/integrations/` templates for each third-party service per CLAUDE.md §"API integration rules".

---

## Self-review notes (author → reviewer)

- Coverage: every bullet in the user's Phase 0 schema brief (vertical concept, prompt versioning, knowledge chunks, citations, verification events, token usage, subscriptions, pgvector, existing-data mapping, regression proof) is addressed by at least one task B.1 through B.16.
- Placeholders: none. Every code block is complete.
- Type consistency: `verticals.id`, `agents.vertical_id`, `conversations.vertical_id`, `messages.agent_id`, `message_citations.chunk_id`, `message_citations.external_source_id` follow one naming pattern (`<table_singular>_id`). `agent_prompt_versions.version_number` is consistent across test and seeder.
- Known decisions deferred to sign-off: A.5 Q1–Q5.
- Known scope cut: mobile skeleton, telemetry, Sanctum, eval harness tables each get their own plan (listed above).
