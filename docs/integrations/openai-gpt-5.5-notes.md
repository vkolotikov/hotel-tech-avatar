# GPT-5.5 — what we need to know

Distilled from the official OpenAI guide (`openai-API.docx` in repo root,
fetched 2026-04-28). Reference back to that file for full details.

## Verdict for this project

**Default model recommendation:** `gpt-5.4` for now. gpt-5.5 is a meaningfully
different family, not a drop-in replacement, and unlocking its best behaviour
requires the **Responses API** (not Chat Completions, which is what
`OpenAiProvider::chat()` currently calls). Switching to gpt-5.5 on Chat
Completions works for basic single-turn use but loses:

- `reasoning.effort` parameter (medium / low / high / xhigh)
- `text.verbosity` parameter (low / medium / high) — concise output control
- `previous_response_id` for cheaper multi-turn state handling
- Native `phase` handling for tool preambles
- Hosted tools (web search, file search, code interpreter, computer use)

See "Migration plan" at the bottom for what a proper move to Responses API
would touch.

## Behavioural changes vs gpt-5.4 / gpt-4o

1. **Default reasoning effort = `medium`.** Reserve `low` for latency-critical
   chat turns, `high`/`xhigh` only when evals show measurable quality lift.
   Higher effort isn't automatically better — with conflicting instructions or
   weak stopping rules, it can lead to overthinking.

2. **Image inputs preserve more visual detail by default.** When `image_detail`
   is unset, GPT-5.5 uses `original` — up to 10.24 MP / 6,000-pixel dim limit
   (was much smaller on prior models).

3. **Stronger instruction-following.** GPT-5.5 reads prompts literally. Define
   success criteria + stopping rules. Outcome-first phrasing beats step-by-step
   process scripts.

4. **Default style is concise + direct.** Customer-facing or conversational
   products need explicit personality/warmth guidance — Hexalife does. Use
   `text.verbosity: low` only when you want even more terseness.

5. **Coding workflows need orchestration.** Not relevant to wellness chat.

## Prompting changes that matter for Hexalife

The prompting guide pushes a specific structure for prompts. Compared to our
current `SystemPromptBuilder`:

- ✅ We have personality via persona_json + agent role.
- ⚠️ We over-specify process. The guide flags `ALWAYS`/`NEVER`/`must`/`only` as
  load-bearing only for safety invariants, not for judgment calls. Our prompt
  uses these heavily.
- ⚠️ We describe the JSON output schema in the prompt. The guide recommends
  using **Structured Outputs** (`response_format: {type: 'json_schema',
  json_schema: {...}}`) instead — automatic validation + better adherence.
  Our current `response_format: {type: 'json_object'}` is the older legacy form.
- ✅ We don't add the current date — guide says GPT-5.5 already knows UTC date.
- ⚠️ We don't optimise for prompt caching. Static parts (persona, scope, rules)
  should come first, dynamic parts (retrieved evidence, conversation history)
  should come last. We currently interleave.

## Suggested prompt structure (per the guide)

```
Role: 1-2 sentences defining the model's function and job.

# Personality
Tone, demeanour, collaboration style.

# Goal
The user-visible outcome.

# Success criteria
What must be true before the final answer.

# Constraints
Policy, safety, business, evidence, side-effect limits.

# Output
Sections, length, tone.

# Stop rules
When to retry, fallback, abstain, ask, or stop.
```

## API changes when we migrate to Responses API

- Endpoint: `POST /v1/responses` instead of `/v1/chat/completions`
- Request shape changes: messages → `input` (different schema)
- Response shape changes: `choices[0].message.content` → `output[].content[].text`
- Multi-turn: pass `previous_response_id` instead of replaying full history
- Streaming: SSE event names differ (`response.output_text.delta` etc.)
- Tools: `function` tools have similar schema; hosted tools use new types

This means rewriting `OpenAiProvider::chat()`, `useChatStream.ts`'s SSE
handler, and the streaming controller in Laravel. Estimate: 1.5-2 days.

## Migration plan (deferred — separate ADR-worthy task)

1. Add a feature flag `LLM_API_BACKEND=responses|chat` (default `chat`).
2. Build a parallel `OpenAiResponsesProvider` implementing the same
   `ProviderInterface`. Keeps the cut-over reversible.
3. Update streaming endpoint to emit Responses-style SSE events; mobile
   `useChatStream.ts` adds a parser branch keyed off the backend flag.
4. Per-agent `openai_model` field gains a `requires_responses_api` flag —
   admin can pick gpt-5.5 once the new path is live.
5. Run the eval harness against both backends; promote when gpt-5.5 on
   Responses beats gpt-5.4 on Chat Completions by a measurable margin.

Until that ships: keep the default at `gpt-5.4`. gpt-5.5 stays in the admin
picker for testing per-agent but isn't recommended as the fleet default.

## Other useful guidance from the doc (for future tuning)

- **Preambles for streaming**: prompt the model to emit a 1-2 sentence
  acknowledgement before tool calls or long reasoning. Improves perceived
  responsiveness.
- **Retrieval budgets**: explicit stopping rules for search ("one broad search
  by default; only search again if X, Y, Z"). Prevents over-searching.
- **Creative drafting guardrails**: when asking for slides/copy, distinguish
  source-backed facts from creative wording. Prevents made-up specifics.
- **Validation loops**: ask the model to check its work post-generation when
  validation tools are available.

## Source

`openai-API.docx` at repo root. Original URL: developers.openai.com/api/docs/guides/latest-model.
Fetched 2026-04-28 by user; this summary written same day.
