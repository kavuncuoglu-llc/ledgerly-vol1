# Ledgerly (Volume 1 companion app)

Companion code for **Large Language Models for Laravel Developers, Volume 1: The Foundations of Large Language Models**.

Ledgerly is a small, fictional invoicing product with customers, invoices, a 120-article help center, and a support inbox. Over the course of the volume you bolt a support assistant onto it, one chapter at a time. At `ch01` it is a plain Laravel app with seed data and **no AI code at all**.

The repository is public and free to use. See [LICENSE](LICENSE) for the terms (personal and educational use; not for republishing or resale).

## Chapter tags

Every chapter that changes the app has a Git tag, and `vol2-start` marks the hand-off to Volume 2 (*Running, Training, and Securing Large Language Models*). A tag is the app exactly as that chapter leaves it.

```sh
git clone https://github.com/kavuncuoglu-llc/ledgerly-vol1.git ledgerly
cd ledgerly
git tag --list 'ch*'
git checkout ch01
```

Chapter tags never move. Fixes land on `main` with an entry in [CHANGELOG.md](CHANGELOG.md); a chapter whose code had to change gets a new tag alongside the old one, such as `ch08-r2`. Pick up fixes with `git pull` on `main` and `git fetch --tags`.

Prefer a zip? On GitHub, choose a chapter tag from the branch menu, then **Code → Download ZIP**.

## Run it

You need PHP 8.3+ with the GD extension, Composer, Docker, and (from Chapter 0 on) [Ollama](https://ollama.com). From `ch07`, `invoice:read` on a PDF also needs Poppler's `pdftoppm` on your `PATH` (`brew install poppler`, `apt install poppler-utils`).

```sh
cp .env.example .env
docker compose up -d
composer install
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

Open <http://127.0.0.1:8000>. You get a customer list, an invoice list, the help center, and the support inbox.

From `ch18` the assistant answers through a queue and streams over WebSockets, so run two more processes next to `php artisan serve` (the queue connection is Redis; `.env.example` sets it):

```sh
php artisan horizon
php artisan reverb:start --host=127.0.0.1 --port=8080
```

Then open <http://127.0.0.1:8000/chat>, sign in as `acme@example.com` / `password`, and ask. `AI_QUEUED_REPLIES=false` brings back the synchronous `POST /support/ask` of earlier chapters. If port 8080 is taken on your machine, change `REVERB_PORT` and `REVERB_SERVER_PORT` in `.env`.

The seeder is deterministic. It creates 40 customers, 599 invoices in every status, 93 support tickets, and the help center is read from Markdown in `resources/help/`. Customer 12 is **Acme Studios** (`acme@example.com`, password `password`), the account the book's examples keep coming back to.

If port 5432 or 6379 is already taken on your machine, set `DB_PORT` and `REDIS_PORT` in `.env` before `docker compose up -d`; Compose reads them from there.

## Where things live

| Path | What it is |
| --- | --- |
| `app/Models` | Customer, Invoice, InvoiceItem, Payment, Ticket, User, ModelCall, Conversation, Message, UserMemory, CachedAnswer |
| `app/Support/HelpCenter.php` | Reads the Markdown help center from `resources/help/` |
| `app/Ai/Tokens` | `TokenCounter` interface with `TiktokenCounter` (exact, OpenAI-family) and `RatioCounter` (estimate) |
| `app/Ai/Events/ModelCalled.php` | Event every model call will dispatch with its usage, finish reason, and latency |
| `app/Ai/Listeners/RecordModelCall.php` | Writes one `model_calls` row per event and warns when a completion was truncated |
| `app/Ai/Prompt/Budget.php` | Token budget arithmetic over `config/ai.php` (`budget`) |
| `app/Providers/AiServiceProvider.php` | Binds `TokenCounter` from `AI_TOKEN_COUNTER`, the `AiManager` singleton and the default `ChatModel`, `EmbeddingModel` and `Reranker` bindings, and registers the listener |
| `app/Console/Commands/AiSample.php` | `php artisan ai:sample`: sends a prompt with explicit sampling parameters and prints completion, finish reason, and token counts |
| `app/Ai/Sampling` | `ChatSampler` (one chat-completions call) and `SampleResult`, the minimal plumbing behind `ai:sample` |
| `app/Ai/Contracts` | The provider-agnostic seam from ch19: the `ChatModel`, `EmbeddingModel` and `Reranker` interfaces; the request and response types `ChatRequest`, `ChatResponse`, `Usage`, `FinishReason`, `Capability`, `EmbedKind`; the stream events in `Contracts/Stream` (`Delta`, `ToolCallStarted`, `ToolCallArguments`, `Completed`, `Failed`); and the optional capability interfaces in `Contracts/Capabilities` (`SupportsContextWindowOverride`, `SupportsNativeStructuredOutput`). Features depend on these and nothing else |
| `app/Ai/AiManager.php`, `app/Ai/Facades/Ai.php` | `Ai::for('ticket.classify')` resolves a feature to a provider through `config('ai.features')`; `Ai::chat('frontier')`, `Ai::embed()` and `Ai::reranker()` resolve a provider name from `config('ai.providers')`. Instances are cached and wrapped once in `UsageLoggingModel`, `RetryingModel` and `ProfileMergingModel`. `Ai::fake()` swaps in the scripted fake (see `app/Ai/Testing`). `ModelCapabilities` asks a decorated model for a capability interface |
| `app/Ai/Drivers` | One class per API shape: `OpenAiCompatibleDriver` (Ollama, OpenAI, vLLM, llama-server) with `OpenAiCompatibleEmbeddings`, `AnthropicDriver` (Messages API), `GeminiDriver` (`generateContent`), and `FakeDriver` with `FakeEmbeddings`. `Drivers/Errors` maps each provider's errors to the exception hierarchy; `Drivers/Support` holds `ProviderClient`, `StreamAccumulator`, `SseReader`, `OptionMapper`, `GeminiSchema` and `DebugLog` |
| `app/Ai/Decorators` | `ProfileMergingModel` (sampling profile into the options), `RetryingModel` and `RetryingEmbeddingModel` (3 attempts, exponential backoff with jitter, `Retry-After` honored, only for `RateLimited` and `ProviderUnavailable`), `UsageLoggingModel` (one `ModelCalled` per logical call, so one `model_calls` row, with the provider name) |
| `app/Ai/Http` | `RetryPolicy` and `CallDetails`; `ModelRequestFailed` and its `RateLimited`, `ContextTooLong`, `ProviderUnavailable`, `InvalidRequest` and `ContentFiltered` subclasses are in `app/Ai/Exceptions` |
| `app/Ai/Testing` | `Ai::fake()` support: `FakeScript` (`for`, `respondWith`, `respondWithJson`, `respondWithToolCall`, `respondWithStream`, `failWith`), `FakeLedger` (what was sent), `AiFake`, `JsonSchemaCheck` (scripted content is validated against `response_format`), and `HttpRecorder` behind `ai:record` |
| `app/Ai/Native`, `app/Ai/Analysis` | `ServedModels` is the one place that calls `raw()` (lists the models a provider serves); `BoundaryScanner` is the static check, run by `php artisan ai:boundaries` and by a test, that fails when `->raw()` or a `App\Ai\Drivers` class is used outside the provider layer |
| `app/Console/Commands/AiProviders.php`, `AiRecord.php`, `AiBoundaries.php` | `php artisan ai:providers [--check]` (providers, drivers, models, capabilities; `--check` asks each configured chat provider which models it serves), `php artisan ai:record --provider= --fixture= --prompt=` (runs one request against a real provider and saves the redacted HTTP exchange under `tests/Fixtures/ai/{driver}/`), `php artisan ai:boundaries` |
| `tests/Fixtures/ai`, `tests/Support/FakeHttp.php`, `tests/Feature/Ai/DriverConformanceTest.php` | Fixtures per driver (12 each, see the CHANGELOG for which were recorded and which were written by hand), the replay helper, and the conformance suite every driver must pass |
| `app/Ai/Support` | `SupportAssistant` (asks the semantic cache first, otherwise builds the prompt through `ContextAssembler` and answers through `ToolRunner`; from ch15 it retrieves labelled passages before every reply, checks the citations and repairs once), `QuestionBatch`, `ClassifyTicket` (returns a `TicketCategory` through `StructuredClient`), and `TicketSamples` (reads the labeled sample file) |
| `app/Ai/Prompts` | Prompt classes with a `VERSION`: `SupportPrompt` (v6), `SummarizePrompt`, `ExtractMemoriesPrompt`, `TicketClassifyPrompt`, and `TicketExtractPrompt`. The version is logged in `model_calls.prompt_version` |
| `app/Console/Commands/TicketClassify.php`, `storage/samples/tickets.json` | `php artisan ticket:classify`: classifies the twenty labeled sample messages and reports agreement and misses |
| `app/Events/TicketReceived.php`, `app/Ai/Listeners/TriageTicket.php`, `ClassifyReceivedTicket.php` | `TicketReceived` (carries an event id, default `ticket:{id}`) fires the queued `TriageTicket` listener, which runs the triage pipeline once per event id. `ClassifyReceivedTicket` (the ch10 single-label classifier) is no longer registered on the event; nothing in the app dispatches `TicketReceived` yet except your own code |
| `app/Http/Controllers/LoginController.php` | `POST /login` (JSON): session login so the `curl -b cookies.txt` examples work |
| `app/Http/Controllers/SupportController.php`, `app/Http/Requests/AskRequest.php` | `POST /support/ask` (authenticated, `throttle:ai`): `{"question": "...", "conversation_id": 77}` in (`conversation_id` optional; omitted starts a conversation), `{"answer": "...", "conversation_id": 77, ...}` out |
| `app/Ai/Tools` | The tool layer: `Tool` interface, `ToolKind` (read, low-risk write, high-risk write), `ToolContext` (the authenticated user, never model-supplied), `ToolResult`, `ToolRegistry` (emits the `tools` array, dispatches by name), `ToolRunner` (the capped five-round loop, argument validation, gate, audit), `ToolGate` and `GateDecision`, `ToolApprovals` (approve or decline a paused call and resume the run) |
| `app/Ai/Tools/LookupInvoice`, `ListInvoices`, `OpenTicket`, `AccountStatus` | The four native tools (`account_status` arrived at ch17: plan, outstanding and overdue invoices, oldest overdue age, open tickets) and their argument DTOs (`list_invoices` returns an encoded table through `DataEncoder`, not JSON). Each query is scoped to `ToolContext::customer()`; `lookup_invoice` and `open_ticket` normalize `4471`, `#4471` and `INV-4471` to the stored `INV-4471` |
| `app/Ai/Tools/Mcp` | MCP as a consumer: `McpToolSource` merges tools from `config('ai.mcp_servers')` into the registry (names are prefixed `server_`, undeclared tools need approval), `McpClient` over the `laravel/mcp` client |
| `app/Mcp`, `routes/ai.php` | MCP as a server: `Servers/Ledgerly` serves the registry's native tools through `McpToolAdapter`, help articles and a customer's invoices as resources, and `SupportPrompt` and `TicketClassifyPrompt` as prompts. HTTP at `POST /mcp/ledgerly` behind Sanctum bearer tokens; stdio via `php artisan mcp:start ledgerly` |
| `app/Console/Commands/McpToken.php` | `php artisan ledgerly:mcp-token <email> <name>` prints a Sanctum token for an MCP client |
| `app/Http/Controllers/ToolApprovalController.php` | `GET /support/approvals/{id}`, `POST /support/approve/{id}`, `POST /support/decline/{id}` (owner only) |
| `app/Models/ToolCall.php`, `PendingToolCall.php` | The `tool_calls` audit log and the `pending_tool_calls` approval queue |
| `database/migrations/2026_01_05_*` to `2026_01_08_*` | `tool_calls`, `pending_tool_calls`, `tickets.idempotency_key` and `tickets.source`, `personal_access_tokens` |
| `app/Ai/Encoding`, `app/Ai/Encoding/Formats` | `DataEncoder::encode($rows, $shape, $format, $key)` (shape, limit, header, format description, then the rows) with the `Shape` and `Format` interfaces and the writers `ToonFormat` (via `helgesverre/toon`), `TsvFormat`, `MarkdownFormat`, `JsonFormat` (compact or pretty) and `YamlFormat`. The default is `AI_ENCODING_DEFAULT` (`toon`); `AI_ENCODING_LIST_INVOICES` overrides it for `list_invoices` |
| `app/Ai/Shapes/InvoiceSummaryShape.php` | Per-feature field selection and formatting for invoices: number, status, due date, total, days overdue, late fee; currency and today's date stated once in the header; 25 rows by default |
| `app/Console/Commands/BenchEncoding.php`, `app/Ai/Bench`, `storage/benchmarks/` | `php artisan ai:bench-encoding`: token cost and 20-question accuracy for six encodings of the same invoices, saved as `storage/benchmarks/{date}-{model}.json` (gitignored). The fixture is `invoices.json` (120 invoices, a pinned "today" of 2026-06-30) and `invoice-questions.json` (the 20 questions for all 120 rows). `--tokens-only` needs no model |
| `app/Console/Commands/AiStream.php` | `php artisan ai:stream "<prompt>"`: prints the reply token by token, then `[finish_reason · in N out N]`; warns when the stream ends without `[DONE]` |
| `app/Ai/Embeddings` | `Embedder` (query, document and batch embedding over an `EmbeddingModel`; the `search_query:` / `search_document:` prefixes now live in the `local-embed` provider config), `MarkdownChunker`, and `HelpIndexer` |
| `app/Console/Commands/HelpEmbed.php`, `HelpSearch.php` | `php artisan help:embed` chunks and embeds the help center; `php artisan help:search "<question>"` prints the five nearest chunks |
| `app/Models/HelpChunk.php`, `app/Casts/Vector.php` | The `help_chunks` model (with a `nearestTo` scope using pgvector's `<=>`) and its pgvector cast |
| `database/migrations/2026_01_02_000000_create_help_chunks_table.php` | `help_chunks` with `vector(768)` and an HNSW cosine index |
| `app/Ai/Retrieval` | `Retriever` (interface), `PgRetriever` (vector + full-text search merged by reciprocal rank fusion, similarity floor, plan and area pre-filters), `RetrievalScope`, `RetrievalSettings`, `RetrievedChunk` (label, help-center URL), `LabelMap`, `CitationChecker` and `CitationReport`, `GoldenSet`, `RetrievalEvaluator` |
| `app/Ai/Context/ContextBlock.php` | Renders the labelled passages under a one-line preamble that carries the docs version; `ContextAssembler` hands the label-to-chunk map to the caller |
| `app/Console/Commands/HelpIngest.php`, `app/Ai/Embeddings/HelpIndexer.php`, `BoilerplateStripper.php`, `IngestDiff.php` | `php artisan help:ingest [--docs-version=] [--force] [--strategy=heading\|parent-child] [--context-headers] [--source=DIR]`: strips boilerplate, chunks with metadata, and embeds and upserts only the chunks whose `chunk_key` is new or whose content changed. From ch16 the default strategy is `parent-child` (small child chunks are embedded, their parent section is what the prompt receives), `--context-headers` has the model write a one-sentence context per child, and `--source` parses extra `.md`, `.html`, `.docx` and `.pdf` files (`app/Ai/Parsing`). `help:embed` still exists and re-embeds everything |
| `app/Console/Commands/RetrievalEval.php`, `storage/golden/retrieval.jsonl` | `php artisan retrieval:eval [--golden=] [--mode=hybrid\|vector\|keyword] [--floor=] [--rerank=none\|sidecar\|lexical] [--rewrite=off\|rewrite\|expand\|hyde] [--candidates=] [--steps] [--embed-model= --reindex-to=]`: recall@1/3/5, precision, MRR and the off-topic questions over the 120-question golden set; `--steps` prints stage one, plus rerank, plus query rewrite as one table; results and misses go to `storage/golden/` (git-ignored) |
| `database/migrations/2026_01_13_*` to `2026_01_15_*` | `help_chunks` metadata, `search` tsvector with a GIN index and a halfvec HNSW index; `message_sources`; `model_calls.citation_errors` |
| `app/Models/MessageSource.php` | Which chunks each reply cited |
| `app/Console/Commands/InvoiceRead.php` | `php artisan invoice:read <path>`: sends an invoice image or PDF to a vision model through `StructuredClient::extract(InvoiceData::class, ...)` and prints the fields, the line count, and whether a repair turn was needed |
| `app/Ai/Vision` | `ImageEncoder` (resize to about 1 MP, JPEG, base64 data URL), `PdfPageRenderer` (first page via `pdftoppm`), `InvoicePrompt` (system and extraction prompts, `messages($dataUrl)`) |
| `app/Ai/Structured` | `StructuredClient::extract()` (schema from the DTO, enforced request, assert-parse, validate, one repair turn), `StructuredData`, `ExtractionMeta` (`repaired`, `attempts`), `RepairPrompt`, and `Provenance` |
| `app/Ai/Schema` | `SchemaBuilder` (reflects a DTO into a strict JSON Schema) and the `Describe` and `ItemsOf` attributes |
| `app/Ai/Schemas` | `InvoiceData`, `LineItem`, and `TicketData`: readonly DTOs with `rules()`, `afterRules()`, and `fromArray()` |
| `app/Console/Commands/TicketExtract.php` | `php artisan ticket:extract [path]`: extracts category, invoice number, email, amount, and date from the twenty sample messages and reports valid, repaired, failed, and fill counts |
| `storage/samples/` | `clean.pdf`, `phone-photo.jpg`, `bad-scan.jpg`: three renderings of one synthetic invoice (Northgate Paper Co, NP-20931), plus `tickets.json`, twenty support messages with human labels |
| `config/ai.php` | `default`, `providers` (named roles: `local`, `local-embed`, `vision`, `frontier`, `rerank`, `fake`), `features` (provider, profile and `escalate_to` per feature), `embedding`, `reranking`, `debug`, `mcp_servers`, `tool_allow_lists`, `mcp_stdio_user_email`, timeouts, `chunking`, `token_counter`, sampling `profiles`, `budget`, `routing`, `agents`, `triage`, `conversation`, `memory`, `retention`, `encoding`, `slot_budgets`, `prompt_cache`, `semantic_cache`, `rate_limit`, and the per-provider-name `rates` table (USD per million tokens) |
| `database/migrations/2026_01_01_000000_create_model_calls_table.php`, `2026_01_03_000000_add_prompt_version_to_model_calls_table.php`, `2026_01_04_000000_add_repaired_to_model_calls_table.php` | The usage log table, and its `prompt_version` and `repaired` columns |
| `app/Ai/Conversations` | `ConversationStore` (interface) and `EloquentConversationStore` (`start`, `append` with a stored token count, `history()` in whole exchanges with the first exchange pinned, `compact`, `close`), `Exchange`, `HistoryBudget` (history allowance measured against the live system text), `ToolResultCompactor`, `SummarizeConversation` (the queued job: compaction, then the rolling summary), `ReplayScript` |
| `app/Ai/Memory` | `MemoryStore` (the per-user key/value facts, the system-message block, expiry), `ExtractMemories` (queued on close), `StatedFactGuard` (drops extracted facts the customer did not say) |
| `app/Models/Conversation.php`, `Message.php`, `UserMemory.php`, `app/Models/Scopes`, `app/Support/Tenancy/CurrentTenant.php` | The conversation tables and their tenant scoping: `TenantScope` on conversations and memories, `ConversationTenantScope` on messages; the tenant is the signed-in user's `customer_id` unless `CurrentTenant::runAs()` says otherwise |
| `app/Http/Controllers/ConversationController.php`, `MemoryController.php` | `POST /support/conversations/{id}/close` (owner only; queues memory extraction), `GET /support/memories`, `DELETE /support/memories/{id}` (owner only) |
| `app/Console/Commands/ConversationReplay.php`, `storage/samples/long-conversation.json` | `php artisan conversation:replay storage/samples/long-conversation.json --user=12 [--close]`: replays thirty customer messages plus a follow-up through the assistant and prints prompt tokens and summary state per turn (runs the queue inline for the replay) |
| `app/Console/Commands/ConversationsRetain.php`, `UserForget.php` | `php artisan conversations:retain` (scheduled daily at 02:00: archives closed conversations older than `AI_RETENTION_CLOSED_CONVERSATION_DAYS`, expires memories unused for a year) and `php artisan user:forget <id> --confirm` |
| `database/migrations/2026_01_09_*`, `2026_01_10_*` | `conversations` and `messages`; `user_memories` |
| `app/Ai/Context` | `ContextAssembler` (slots in stable-first order: system with the memories block, summary, history, context, question; tokens per slot), `AssembledPrompt` (`messages`, `breakdown`, `overBudget`, and `breakpoints`, the message indexes after the stable slots; the driver decides whether to mark them, `AI_CACHE_BREAKPOINTS` turns it on for the `local` provider), and `ContextBlock` |
| `app/Ai/Cache` | `SemanticCache` (`lookup`, `lookupAnyCacheable`, `store`), `CachePolicy` (cacheable categories, thresholds, what counts as a generic question or a storable answer), `SupportCacheGate` (when the assistant may read or write the cache), `CacheLookup`, `HelpVersion` (hash of the help-center files), and `PrefixCacheEstimator` (the local estimate of prompt-cache hits from the prefix shared with the previous call) |
| `app/Models/CachedAnswer.php`, `database/migrations/2026_01_12_000000_create_cached_answers.php` | The `cached_answers` table: tenant, category, question, answer, `help_version`, status `auto|approved|rejected`, `hit_count`, expiry, and a `vector(768)` embedding with an HNSW cosine index |
| `database/migrations/2026_01_11_000000_add_context_columns_to_model_calls_table.php` | `model_calls.cached_tokens`, `cached_tokens_estimated`, `ttft_ms`, `context_breakdown` (JSON, tokens per slot) and `semantic_cache_hit` |
| `app/Ai/Stats`, `app/Console/Commands/AiStats.php` | `php artisan ai:stats --feature=support.reply --since=24h`: prompt cache hit rate, semantic cache hit rate, tokens per answer with the per-slot breakdown, and time to first token, against the 7-day figures, with a warning when tokens per answer rises more than 25 percent |
| `app/Console/Commands/SupportAsk.php`, `SupportAskBatch.php`, `storage/samples/howto.json` | `php artisan support:ask "<question>" [--user=]` and `php artisan support:ask-batch storage/samples/howto.json [--user=] [-v]`: ask as a customer (default `acme@example.com`) and see whether the semantic cache answered |
| `app/Console/Commands/CachedAnswersReview.php` | `php artisan cached-answers:review [--approve=1,2 [--share]] [--reject=3]`: list automatic answers; approving keeps an answer private to its tenant, `--share` also makes it visible to every tenant |
| `app/Ai/Agents` | `AgentRunner` (the Chapter 11 loop with a goal: prompt from state, call the model, execute tools through `ToolRunner::executeCall`, observe, repeat; persists every run), the `Agent` interface, `InvestigateAgent`, `AgentState` (serializable: goal, facts, steps, findings, conclusion), `AgentBudget` (rounds, tokens, seconds, writes, and the progress check), `Trace`, `AgentResult`, `CallSignature`, `ResultSummary` |
| `app/Ai/Support/Router.php`, `Route.php`, `VoteClassifier.php`, `CategoryVotes.php` | The router: samples the classifier `ai.routing.samples` times, takes the majority, and returns a `Route` (tool subset, retrieval area, tier, cache flag) or sends a split vote to a person. `SupportAssistant` runs it before every reply when `AI_ROUTING` is on |
| `app/Ai/Triage` | `TriagePipeline` (route, extract, urgency rule, assignment, one bounded `InvestigateAgent` for billing and account tickets, grounded draft), `TriageRunner` (idempotent per event id), `UrgencyRule`, `Assignment`, `Drafter`, `TriageResult` |
| `app/Models/AgentRun.php`, `TriageRun.php`, `database/migrations/2026_01_17_*` | `agent_runs` (state, budget usage and trace of every run), `triage_runs` (one per event id; `status` `queued_for_review` is the review queue), and `tickets.assignee` and `tickets.urgent` |
| `app/Console/Commands/TicketTriage.php`, `AgentTrace.php` | `php artisan ticket:triage [storage/samples/tickets.json] [--user=] [--fresh]` runs the twenty sample messages through the pipeline and prints a line per ticket; `php artisan agent:trace {run}` prints one agent run as a log |
| `app/Ai/Replies` | The queued reply: `ReplyDispatcher` (placeholder row, idempotent on `request_id`), `AnswerQuestion` (unique per reply, 180 s, 3 tries, `ai` queue) and `ResumeReply` (continues a run after its approval), `ReplyLifecycle` (pending, streaming, paused, resuming, completed, failed, expired), `ReplyStream` (batches deltas into broadcasts and the buffer) and `ReplyBroadcaster` (a dead socket server never fails the job) |
| `app/Ai/Streaming` | `DeltaBatcher` (flush every 50 ms or 20 deltas), `StreamTaps`, `ReplyBuffer` (numbered chunks per reply in the cache, the replay source) and `ReplyEventStream` (the server-sent-events relay) |
| `app/Ai/Events/Reply*.php`, `routes/channels.php` | `ReplyDelta`, `ToolCallStarted`, `ReplyPaused`, `ReplyCompleted`, `ReplyFailed`, `ReplyRestarted`, broadcast on `private-reply.{id}`; the channel is authorized for the reply's owner under the tenant scope |
| `app/Models/Reply.php`, `database/migrations/2026_01_18_*` | The `replies` table: the placeholder the job fills in (status, content, sources, `resume_token`, feedback) |
| `app/Http/Controllers/ReplyController.php`, `ChatController.php`, `public/js/`, `resources/views/chat` | `GET /replies/{id}` (stored state plus in-flight chunks), `GET /replies/{id}/stream` (SSE), `POST /replies/{id}/feedback`, and the `/chat` page (`chat.js`, `reply-channel.js`: WebSocket with an SSE fallback, no build step) |
| `app/Console/Commands/RepliesExpirePaused.php` | `php artisan replies:expire-paused` (scheduled daily at 03:00): expires replies waiting for an approval longer than `AI_PAUSED_EXPIRY_DAYS` (7) |
| `config/horizon.php`, `config/reverb.php`, `config/broadcasting.php` | Horizon pools `ai-interactive` (queue `ai`, `AI_CONCURRENCY` workers), `ai-batch` (queue `ai-batch`, 1 to 2 workers) and `default`; the Reverb app and the broadcast connection |
| `resources/help/{category}/{slug}.md` | The 120 help-center articles |
| `database/seeders` | Customers, invoices, tickets |
| `docker-compose.yml` | Postgres with pgvector, and Redis |
| `.env.example` | Points at the Docker services and at Ollama on localhost; `FRONTIER_*` stay empty until you have a cloud key |

## Tests

```sh
php artisan test
```

From `ch06` the tests run on the Postgres service from `docker compose`, in a separate `ledgerly_test` database with the `vector` extension (`docker/postgres/init.sql` creates both on a fresh volume). If your Postgres volume predates this, create it once: `docker exec ledgerly-postgres psql -U ledgerly -d ledgerly -c 'CREATE DATABASE ledgerly_test'`, then `docker exec ledgerly-postgres psql -U ledgerly -d ledgerly_test -c 'CREATE EXTENSION IF NOT EXISTS vector'`. Host and port come from your `.env`. No test calls a real model; HTTP is faked. Tests tagged `model` (the prompt behavior tests) also run against a fake model here, so they pass without Ollama; they check how the app handles replies, not how a real model behaves.

## If something goes wrong

| What you see | What to do |
| --- | --- |
| `git clone` asks for a username and password | The address is wrong. The repository is public and never asks you to sign in. |
| `git checkout` says the tag doesn't exist | Run `git fetch --tags`, then `git tag --list 'ch*'`. |
| A downloaded zip doesn't match the chapter you're reading | You probably downloaded `main`, which can be newer than the chapter. Download the zip for that chapter's tag instead. |

Found a problem? Open an issue. Errata for the book itself are listed on [kavuncuoglu.com](https://kavuncuoglu.com).
