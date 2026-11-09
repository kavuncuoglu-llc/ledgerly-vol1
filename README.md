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
| `app/Providers/AiServiceProvider.php` | Binds `TokenCounter` from `AI_TOKEN_COUNTER` and registers the listener |
| `app/Console/Commands/AiSample.php` | `php artisan ai:sample`: sends a prompt with explicit sampling parameters and prints completion, finish reason, and token counts |
| `app/Ai/Sampling` | `ChatSampler` (one chat-completions call) and `SampleResult`, the minimal plumbing behind `ai:sample` |
| `app/Ai/Http` | `AiHttpClient` (`chat()`, the `stream()` generator, and the retrying `post()` that `Embedder` also uses), `ChatResponse`, `Usage`, `RetryPolicy` (3 attempts, exponential backoff with jitter, `Retry-After` honored), and `StreamAccumulator`. Every `chat()` and `stream()` call dispatches `ModelCalled`, so it lands in `model_calls` |
| `app/Ai/Support` | `SupportAssistant` (asks the semantic cache first, otherwise builds the prompt through `ContextAssembler` and answers through `ToolRunner`; no retrieval yet), `QuestionBatch`, `ClassifyTicket` (returns a `TicketCategory` through `StructuredClient`), and `TicketSamples` (reads the labeled sample file) |
| `app/Ai/Prompts` | Prompt classes with a `VERSION`: `SupportPrompt` (v5), `SummarizePrompt`, `ExtractMemoriesPrompt`, `TicketClassifyPrompt`, and `TicketExtractPrompt`. The version is logged in `model_calls.prompt_version` |
| `app/Console/Commands/TicketClassify.php`, `storage/samples/tickets.json` | `php artisan ticket:classify`: classifies the twenty labeled sample messages and reports agreement and misses |
| `app/Events/TicketReceived.php`, `app/Ai/Listeners/ClassifyReceivedTicket.php` | Classifies a received ticket and stores its category (nothing dispatches the event yet) |
| `app/Http/Controllers/LoginController.php` | `POST /login` (JSON): session login so the `curl -b cookies.txt` examples work |
| `app/Http/Controllers/SupportController.php`, `app/Http/Requests/AskRequest.php` | `POST /support/ask` (authenticated, `throttle:ai`): `{"question": "...", "conversation_id": 77}` in (`conversation_id` optional; omitted starts a conversation), `{"answer": "...", "conversation_id": 77, ...}` out |
| `app/Ai/Tools` | The tool layer: `Tool` interface, `ToolKind` (read, low-risk write, high-risk write), `ToolContext` (the authenticated user, never model-supplied), `ToolResult`, `ToolRegistry` (emits the `tools` array, dispatches by name), `ToolRunner` (the capped five-round loop, argument validation, gate, audit), `ToolGate` and `GateDecision`, `ToolApprovals` (approve or decline a paused call and resume the run) |
| `app/Ai/Tools/LookupInvoice`, `ListInvoices`, `OpenTicket` | The three native tools and their argument DTOs (`list_invoices` returns an encoded table through `DataEncoder`, not JSON). Each query is scoped to `ToolContext::customer()`; `lookup_invoice` and `open_ticket` normalize `4471`, `#4471` and `INV-4471` to the stored `INV-4471` |
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
| `app/Ai/Embeddings` | `Embedder` (`/embeddings`, with the `search_query:` / `search_document:` prefixes), `MarkdownChunker`, and `HelpIndexer` |
| `app/Console/Commands/HelpEmbed.php`, `HelpSearch.php` | `php artisan help:embed` chunks and embeds the help center; `php artisan help:search "<question>"` prints the five nearest chunks |
| `app/Models/HelpChunk.php`, `app/Casts/Vector.php` | The `help_chunks` model (with a `nearestTo` scope using pgvector's `<=>`) and its pgvector cast |
| `database/migrations/2026_01_02_000000_create_help_chunks_table.php` | `help_chunks` with `vector(768)` and an HNSW cosine index |
| `app/Console/Commands/InvoiceRead.php` | `php artisan invoice:read <path>`: sends an invoice image or PDF to a vision model through `StructuredClient::extract(InvoiceData::class, ...)` and prints the fields, the line count, and whether a repair turn was needed |
| `app/Ai/Vision` | `ImageEncoder` (resize to about 1 MP, JPEG, base64 data URL), `PdfPageRenderer` (first page via `pdftoppm`), `InvoicePrompt` (system and extraction prompts, `messages($dataUrl)`) |
| `app/Ai/Structured` | `StructuredClient::extract()` (schema from the DTO, enforced request, assert-parse, validate, one repair turn), `StructuredData`, `ExtractionMeta` (`repaired`, `attempts`), `RepairPrompt`, and `Provenance` |
| `app/Ai/Schema` | `SchemaBuilder` (reflects a DTO into a strict JSON Schema) and the `Describe` and `ItemsOf` attributes |
| `app/Ai/Schemas` | `InvoiceData`, `LineItem`, and `TicketData`: readonly DTOs with `rules()`, `afterRules()`, and `fromArray()` |
| `app/Console/Commands/TicketExtract.php` | `php artisan ticket:extract [path]`: extracts category, invoice number, email, amount, and date from the twenty sample messages and reports valid, repaired, failed, and fill counts |
| `storage/samples/` | `clean.pdf`, `phone-photo.jpg`, `bad-scan.jpg`: three renderings of one synthetic invoice (Northgate Paper Co, NP-20931), plus `tickets.json`, twenty support messages with human labels |
| `config/ai.php` | `mcp_servers`, `tool_allow_lists`, `mcp_stdio_user_email`, `provider`, `base_url`, `api_key`, `model`, `vision_model`, `embed_model`, `embed_dimensions`, timeouts, `chunking`, `token_counter`, sampling `profiles`, `budget`, `conversation`, `memory`, `retention`, `encoding`, `slot_budgets`, `prompt_cache`, `semantic_cache`, `rate_limit`, and the per-model `rates` table (USD per million tokens) |
| `database/migrations/2026_01_01_000000_create_model_calls_table.php`, `2026_01_03_000000_add_prompt_version_to_model_calls_table.php`, `2026_01_04_000000_add_repaired_to_model_calls_table.php` | The usage log table, and its `prompt_version` and `repaired` columns |
| `app/Ai/Conversations` | `ConversationStore` (interface) and `EloquentConversationStore` (`start`, `append` with a stored token count, `history()` in whole exchanges with the first exchange pinned, `compact`, `close`), `Exchange`, `HistoryBudget` (history allowance measured against the live system text), `ToolResultCompactor`, `SummarizeConversation` (the queued job: compaction, then the rolling summary), `ReplayScript` |
| `app/Ai/Memory` | `MemoryStore` (the per-user key/value facts, the system-message block, expiry), `ExtractMemories` (queued on close), `StatedFactGuard` (drops extracted facts the customer did not say) |
| `app/Models/Conversation.php`, `Message.php`, `UserMemory.php`, `app/Models/Scopes`, `app/Support/Tenancy/CurrentTenant.php` | The conversation tables and their tenant scoping: `TenantScope` on conversations and memories, `ConversationTenantScope` on messages; the tenant is the signed-in user's `customer_id` unless `CurrentTenant::runAs()` says otherwise |
| `app/Http/Controllers/ConversationController.php`, `MemoryController.php` | `POST /support/conversations/{id}/close` (owner only; queues memory extraction), `GET /support/memories`, `DELETE /support/memories/{id}` (owner only) |
| `app/Console/Commands/ConversationReplay.php`, `storage/samples/long-conversation.json` | `php artisan conversation:replay storage/samples/long-conversation.json --user=12 [--close]`: replays thirty customer messages plus a follow-up through the assistant and prints prompt tokens and summary state per turn (runs the queue inline for the replay) |
| `app/Console/Commands/ConversationsRetain.php`, `UserForget.php` | `php artisan conversations:retain` (scheduled daily at 02:00: archives closed conversations older than `AI_RETENTION_CLOSED_CONVERSATION_DAYS`, expires memories unused for a year) and `php artisan user:forget <id> --confirm` |
| `database/migrations/2026_01_09_*`, `2026_01_10_*` | `conversations` and `messages`; `user_memories` |
| `app/Ai/Context` | `ContextAssembler` (slots in stable-first order: system with the memories block, summary, history, context, question; cache breakpoints where the provider wants them; tokens per slot), `AssembledPrompt` (`messages`, `breakdown`, `overBudget`), `ContextBlock`, and `ProviderCapabilities` (the one place that knows whether the provider wants `cache_control` breakpoints; `AI_CACHE_BREAKPOINTS`) |
| `app/Ai/Cache` | `SemanticCache` (`lookup`, `lookupAnyCacheable`, `store`), `CachePolicy` (cacheable categories, thresholds, what counts as a generic question or a storable answer), `SupportCacheGate` (when the assistant may read or write the cache), `CacheLookup`, `HelpVersion` (hash of the help-center files), and `PrefixCacheEstimator` (the local estimate of prompt-cache hits from the prefix shared with the previous call) |
| `app/Models/CachedAnswer.php`, `database/migrations/2026_01_12_000000_create_cached_answers.php` | The `cached_answers` table: tenant, category, question, answer, `help_version`, status `auto|approved|rejected`, `hit_count`, expiry, and a `vector(768)` embedding with an HNSW cosine index |
| `database/migrations/2026_01_11_000000_add_context_columns_to_model_calls_table.php` | `model_calls.cached_tokens`, `cached_tokens_estimated`, `ttft_ms`, `context_breakdown` (JSON, tokens per slot) and `semantic_cache_hit` |
| `app/Ai/Stats`, `app/Console/Commands/AiStats.php` | `php artisan ai:stats --feature=support.reply --since=24h`: prompt cache hit rate, semantic cache hit rate, tokens per answer with the per-slot breakdown, and time to first token, against the 7-day figures, with a warning when tokens per answer rises more than 25 percent |
| `app/Console/Commands/SupportAsk.php`, `SupportAskBatch.php`, `storage/samples/howto.json` | `php artisan support:ask "<question>" [--user=]` and `php artisan support:ask-batch storage/samples/howto.json [--user=] [-v]`: ask as a customer (default `acme@example.com`) and see whether the semantic cache answered |
| `app/Console/Commands/CachedAnswersReview.php` | `php artisan cached-answers:review [--approve=1,2 [--share]] [--reject=3]`: list automatic answers; approving keeps an answer private to its tenant, `--share` also makes it visible to every tenant |
| `resources/help/{category}/{slug}.md` | The 120 help-center articles |
| `database/seeders` | Customers, invoices, tickets |
| `docker-compose.yml` | Postgres with pgvector, and Redis |
| `.env.example` | Points at the Docker services and at Ollama on localhost |

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
