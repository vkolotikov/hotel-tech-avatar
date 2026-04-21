# Verification Pipeline Integration Design

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Wire the completed verification pipeline into the generation flow so every wellness avatar response is verified before reaching the user, while ensuring the hotel vertical continues operating without regression or latency impact.

**Architecture:** Extraction of generation logic into a dedicated `GenerationService` that orchestrates context building, LLM calls, and conditional verification. Verification runs synchronously in the request cycle and completes before the message is saved. Vertical-specific behavior is data-driven: only wellness vertical agents trigger verification. Failed verifications trigger a revision loop (max 2 attempts); exhausted revisions fall back to a professional referral response.

**Tech Stack:** Laravel 13, PHP 8.4, PostgreSQL, VerificationService (existing), Message model (extended), LlmClient (existing).

---

## Architecture

### Integration Point

```
ConversationController.createMessage()
  └─> GenerationService.generateResponse(conversation)
       ├─> Build context (system prompt, knowledge, history)
       ├─> Call LlmClient.chat() → get response
       ├─> [Wellness only] VerificationService.verify(response, context, agent)
       │    ├─> Loop up to 2 revisions on failures
       │    └─> Return VerificationResult with pass/fail/revised response
       ├─> Fallback logic: if verification failed after retries, use professional referral
       └─> Save message to database with verification metadata
```

**Synchronous flow:** Verification runs in the request/response cycle. The message is not saved to the database until verification completes or falls back. This prevents unverified responses from reaching users.

**Vertical-conditional:** The agent's `vertical_slug` determines whether verification runs. Hotel vertical agents skip verification entirely (no latency impact, no code path executed). Wellness vertical agents always verify.

---

## Components and Responsibilities

### GenerationService (New)

**Location:** `app/Services/Generation/GenerationService.php`

**Constructor:**
- Inject: `LlmClient`, `VerificationService`, optional `RetrievalService`
- Dependencies are interfaces for testability

**Public method: `generateResponse(Conversation $conversation): Message`**

**Responsibilities:**
1. Load the conversation's agent
2. Build system prompt:
   - Use agent.system_instructions if set, else default from agent name + role + description
   - Append agent.knowledge_text if present (max 12,000 chars, configurable)
3. Build message history:
   - Load last 20 messages from conversation (configurable max_context_messages)
   - Order chronologically for LLM
   - Transform to OpenAI format (role=assistant/user, content)
4. Call LlmClient.chat():
   - Pass model, temperature, max_tokens from agent config
   - Capture response: content, provider, model, token counts, latency, trace_id
5. **Conditional verification** (wellness vertical only):
   - Check agent.vertical_slug
   - If 'wellness': call VerificationService.verify(response.content, context, agent)
   - Implement revision loop:
     ```php
     $revisionCount = 0;
     $verificationResult = $this->verificationService->verify($responseText, $context, $agent);
     
     while (!$verificationResult->passed && $revisionCount < 2 && $verificationResult->revision_suggestion) {
         $revisionPrompt = $this->buildRevisionPrompt($verificationResult->failures);
         $revisedResponse = $this->llmClient->chat(...)  // LLM call with revision prompt
         $verificationResult = $this->verificationService->verify($revisedResponse->content, $context, $agent);
         $revisionCount++;
     }
     ```
6. **Fallback handling** (wellness only):
   - If verification still fails after revision loop:
     - Replace response.content with: `"I recommend consulting a healthcare professional for this question"`
     - Set is_verified = false
7. Save Message record:
   - role='agent', content, ai_provider, ai_model, prompt_tokens, completion_tokens, total_tokens, ai_latency_ms, trace_id
   - **New fields:**
     - `is_verified`: boolean (null for hotel, true/false for wellness)
     - `verification_failures_json`: json (null for hotel/passing wellness, array of VerificationFailure for failing wellness)
     - `verification_latency_ms`: integer (0 for hotel, milliseconds for wellness)
   - `retrieval_used`: boolean (if retrieval was enabled for this agent)
8. Return Message object

**Error handling:**
- LlmClient throws exception → catch, return offline message (existing behavior)
- VerificationService throws exception → catch, log warning, use fallback response
- Revision prompt throws exception → catch, log error, use fallback response
- Citation validation timeout → caught by VerificationService, does not block message save

**Observability:**
- Log each step: context building, LLM call duration, verification decision (pass/fail), revision attempts, fallback usage
- Include: agent_id, vertical_slug, response length, token counts, verification latency

### ConversationController (Modified)

**Location:** `app/Http/Controllers/Api/V1/ConversationController.php`

**Changes to existing methods:**

1. **createMessage():**
   - Remove the inline `generateReply()` call
   - Inject GenerationService into constructor
   - Call `$this->generationService->generateResponse($conversation)` instead
   - Return the Message as before

2. **agentReply():**
   - Remove the inline `generateReply()` call
   - Call `$this->generationService->generateResponse($conversation)` instead
   - Return the Message as before

3. **Delete `generateReply()` method** (logic moves to GenerationService)

### Message Model (Modified)

**Location:** `app/Models/Message.php`

**New columns (migration):**
- `is_verified: boolean nullable` — null for hotel/old messages, true if verification passed, false if failed
- `verification_failures_json: json nullable` — array of VerificationFailure objects (null if verified or hotel)
- `verification_latency_ms: integer nullable` — milliseconds spent in verification pipeline

**Model additions:**
```php
protected $casts = [
    // ... existing casts
    'verification_failures_json' => 'json',
];

public function getVerificationFailuresAttribute() {
    return $this->verification_failures_json ? 
        collect($this->verification_failures_json)->map(fn($f) => new VerificationFailure(...)) :
        null;
}
```

### VerificationService (No Changes)

The existing VerificationService (from Phase 1 Sub-project #3) is used as-is. It exposes:
- `verify(string $response_text, RetrievedContext $context, Agent $agent): VerificationResult`
- Returns: VerificationResult with passed bool, failures array, revision_suggestion string, latency_ms

---

## Data Flow

### Happy Path: Wellness Vertical, Verification Passes

1. User sends message → ConversationController.createMessage()
2. Controller validates, saves user message
3. Controller calls GenerationService.generateResponse($conversation)
4. Service builds context (system prompt + history)
5. Service calls LlmClient.chat() → response_text received
6. Service checks agent.vertical_slug = 'wellness'
7. Service calls VerificationService.verify(response_text, context, agent)
8. Verification returns: passed=true, failures=[]
9. Service saves Message with: content=response_text, is_verified=true, verification_failures_json=null, verification_latency_ms=N
10. Service returns Message
11. Controller returns Message JSON to client

**Latency:** LLM latency + verification latency (~500-1500ms for local operations). No external API calls in verification path for the common case (citation caching).

### Failure Path: Wellness Vertical, Verification Fails, Revision Succeeds

1-6. Same as happy path
7. Service calls VerificationService.verify() → passed=false, failures=[...], revision_suggestion="..."
8. Service enters revision loop (revision_count < 2):
   - Build revision prompt with failure details
   - Call LlmClient.chat() with revision prompt
   - Call VerificationService.verify() on revised response
   - Revision 1 succeeds: passed=true
9. Service saves Message with: content=revised_response_text, is_verified=true, verification_failures_json=null, verification_latency_ms=N (includes LLM + verification time)
10. Service returns Message
11. Controller returns Message JSON to client

**Latency:** 2× LLM calls + 2× verification calls.

### Exhaustion Path: Wellness Vertical, Verification Fails After Max Revisions

1-7. Same as failure path
8. Service attempts revisions:
   - Revision 1: failed
   - Revision 2: failed
   - revision_count = 2, loop exits
9. Service detects exhaustion:
   - Replace content with fallback: *"I recommend consulting a healthcare professional for this question"*
   - Set is_verified=false
10. Service saves Message with: content=fallback_response, is_verified=false, verification_failures_json=[...], verification_latency_ms=N
11. Service returns Message (client sees fallback response)
12. VerificationEvent logged for audit trail

**Latency:** 3× LLM calls + 3× verification calls.

### Hotel Vertical: No Verification

1-3. Same as wellness happy path
4. Service builds context
5. Service calls LlmClient.chat()
6. Service checks agent.vertical_slug = 'hotel'
7. Service skips VerificationService call entirely
8. Service saves Message with: content=response_text, is_verified=null, verification_failures_json=null, verification_latency_ms=null
9. Service returns Message
10. Controller returns Message JSON to client

**Latency:** LLM latency only. Zero verification overhead. Behavior identical to current state.

---

## Error Handling

| Error Scenario | Handling | Outcome |
|---|---|---|
| LLM call fails (any vertical) | Catch exception, log error | Return offline message (existing behavior) |
| Verification throws exception (wellness) | Catch exception, log error | Use fallback response, is_verified=false |
| Revision prompt throws exception (wellness) | Catch exception, log error | Use fallback response, is_verified=false |
| Citation validator timeout (wellness) | Caught by VerificationService | VerificationResult marks citation_invalid; verification may still pass |
| LLM rate limit / transient error (any vertical) | LlmClient handles retries internally | Retry up to N times; if exhausted, return error |
| Unexpected exception during generate | Catch all, log, return offline message | User sees "offline" message; error tracked |

**Fallback Response (Wellness):**
```
"I recommend consulting a healthcare professional for this question"
```

This response is:
- Always safe (no claims to verify)
- Professional (acknowledges limitation)
- Universal (applies to any failed verification)
- Not regenerated (hard-coded, no LLM involvement)

---

## Database Migration

**File:** `database/migrations/YYYY_MM_DD_add_verification_to_messages_table.php`

```php
Schema::table('messages', function (Blueprint $table) {
    if (!Schema::hasColumn('messages', 'is_verified')) {
        $table->boolean('is_verified')->nullable()->after('content');
    }
    if (!Schema::hasColumn('messages', 'verification_failures_json')) {
        $table->json('verification_failures_json')->nullable()->after('is_verified');
    }
    if (!Schema::hasColumn('messages', 'verification_latency_ms')) {
        $table->integer('verification_latency_ms')->nullable()->after('verification_failures_json');
    }
});
```

**Backward compatibility:**
- Existing messages: is_verified=null, verification_failures_json=null, verification_latency_ms=null
- Hotel vertical messages: always null (no verification)
- Wellness vertical messages: boolean + json (set on generation)

---

## Testing Strategy

### Unit Tests

**GenerationServiceTest.php:**
- Test: hotel vertical bypasses verification entirely
- Test: wellness vertical calls VerificationService
- Test: verification passing saves is_verified=true
- Test: verification failing triggers revision loop
- Test: max revisions respected (loop exits at 2)
- Test: fallback response used when revisions exhausted
- Test: fallback response has is_verified=false and failures captured
- Test: LLM exception returns offline message
- Test: VerificationService exception uses fallback
- Test: message saved with all metadata (tokens, latency, trace_id)
- Test: context building includes knowledge base (capped at max chars)
- Test: message history ordered chronologically
- Test: retrieval_used flag set correctly

### Integration Tests

**GenerationServiceIntegrationTest.php:**
- Full flow with real database, mocked LlmClient + VerificationService
- Test: hotel agent message created with is_verified=null
- Test: wellness agent passing verification saves is_verified=true
- Test: wellness agent failing verification saves is_verified=false + failures_json
- Test: revision loop produces revised content in message
- Test: fallback response appears in message.content when exhausted
- Test: message relationships (conversation, agent) created correctly
- Test: timestamps set correctly

### Feature Tests

**ConversationControllerVerificationTest.php:**
- Full end-to-end via HTTP endpoints
- Test: POST /conversations/{id}/messages with auto_reply=true triggers generation
- Test: POST /conversations/{id}/agent-reply triggers generation
- Test: response JSON includes is_verified, verification_failures_json (for wellness)
- Test: hotel vertical response has no verification metadata
- Test: verification metadata not present in old hotel test data
- Test: concurrent requests don't interfere (each gets own verification context)

### Regression Tests

**HotelVerticalSmokeTest.php:**
- Existing test suite for hotel vertical continues to pass
- Latency comparison: verify no significant increase in response time
- Message creation: verify hotel messages have no verification overhead

---

## Configuration

**Existing config/verification.php** already contains:
- Grounding threshold (0.65)
- Max revisions (2)
- Revision timeout (10s)
- Safety patterns (hard + soft)
- Citation validator settings

**New config entries needed:**
- `services.llm.max_context_messages` (default: 20) — used by GenerationService
- `services.llm.max_knowledge_chars` (default: 12000) — used by GenerationService

These already exist in the codebase from ConversationController. GenerationService will use the same config.

---

## Vertical-Specific Behavior

### Hotel Vertical

- Agent.vertical_slug = 'hotel'
- GenerationService detects 'hotel', skips all verification
- Message.is_verified = null
- Message.verification_failures_json = null
- Message.verification_latency_ms = null
- Latency: zero verification overhead
- Behavior: identical to current state
- Regression risk: none (verification is not executed)

### Wellness Vertical

- Agent.vertical_slug = 'wellness'
- GenerationService detects 'wellness', runs VerificationService
- Message.is_verified = true/false (boolean)
- Message.verification_failures_json = [] or [VerificationFailure, ...] (json array)
- Message.verification_latency_ms = N (milliseconds)
- Latency: LLM + verification + revision retries (typical 1.5-3s for passing responses)
- Behavior: responses guaranteed to be verified or fallen back
- Safety: all factual claims grounded, cited, and free of diagnosis/prescription

### Adding New Verticals

- Set agent.vertical_slug to a new value (e.g., 'sports', 'legal')
- By default, new verticals skip verification (is_verified=null)
- To enable verification for a new vertical, add to config:
  ```php
  'verification' => [
      'enabled_verticals' => ['wellness'],  // Add to this list
  ]
  ```
- No code changes required; behavior is data-driven

---

## Success Criteria

- [ ] GenerationService extracts and encapsulates generation pipeline
- [ ] ConversationController delegates to GenerationService (no regression in hotel tests)
- [ ] Wellness vertical messages always have is_verified boolean
- [ ] Hotel vertical messages always have is_verified=null
- [ ] Verification runs synchronously; message not saved until verification complete
- [ ] Revision loop respects max_revisions (2 attempts)
- [ ] Fallback response used when verification exhausted
- [ ] All messages include verification_latency_ms
- [ ] Existing hotel vertical continues without latency impact or behavioral change
- [ ] Comprehensive unit, integration, and feature tests covering all paths
- [ ] No regressions in hotel smoke test
- [ ] All tests passing (215+ assertions)

---

## Open Questions / Deferred

- **Streaming responses:** Current implementation is synchronous, saves complete message. Streaming (SSE) would require asynchronous verification post-stream. Deferred to Phase 2.
- **Live API citation validation:** Some citations may need real-time API calls (PubMed, USDA). Currently cached. Deferred to Phase 2 if latency becomes critical.
- **Revision loop tuning:** Max 2 revisions is conservative. Can be tuned based on production metrics (revision success rate, latency distribution).
