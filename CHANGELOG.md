# Changelog

Fixes go on `main`. Chapter tags never move once published; a chapter whose code had to change gets a new tag alongside the old one, such as `ch08-r2`.

## Unreleased

- Security: CSRF protection is back on everywhere except when `APP_ENV=local`. Tags `ch09` and `ch10` exempted `login` and `support/*` in every environment so the book's `curl -b cookies.txt` flow works; `main` now exempts only `login` and `support/ask`, and only locally. In any other environment, send the `XSRF-TOKEN` cookie value back as the `X-XSRF-TOKEN` header. Do not deploy the `ch09` or `ch10` tags as they stand.
- Fix a flaky `help:search` test at and after `ch06`: `RefreshDatabase` rolls back the bulk inserts of the `help:embed` tests, which leaves dead entries in the HNSW index, and an approximate index scan over a handful of rows could then miss the right chunk. The two search tests now turn off index scans for their transaction so they compare exactly; no application code changed. Test-only, so the `ch06` tag is unaffected.

## ch10

- `App\Ai\Structured\StructuredClient::extract($class, $messages, $options = [], $feature = 'structured', $promptVersion = null, $sourceText = '')`: builds the JSON Schema from a DTO with `SchemaBuilder`, sends it through `AiHttpClient::chat()` as a strict `response_format: json_schema` with the `deterministic` profile, decodes the reply (a reply that is not a JSON object raises `EnforcementNotApplied`, because it means the provider is not enforcing), runs `Validator::make()` with the DTO's `rules()` and `afterRules()`, and on failure appends the invalid output and the validator's messages (`RepairPrompt`) for exactly one repair attempt. A second failure throws `StructuredOutputFailed` carrying both attempts and the errors. A non-null `error` field in the reply throws `StructuredRefusal` without a repair. The returned DTO carries `meta` (`ExtractionMeta`: `repaired`, `attempts`).
- `App\Ai\Schema\SchemaBuilder::for()` and `name()` (static; every constructor parameter is `required`, `additionalProperties: false`, nullable types become `["type", "null"]`, backed enums become `enum`, arrays take `#[ItemsOf(Class::class)]`, the `meta` parameter is left out) with the `Describe` and `ItemsOf` attributes. An untyped property or an array without `ItemsOf` throws `UnsupportedSchemaProperty`.
- `App\Ai\Schemas\InvoiceData` and `LineItem`: rules (formats, ranges, ISO 4217 membership through `App\Support\Currency`, a 200-line cap), cross-field `afterRules()` (line items against the subtotal; the total against subtotal plus tax, or line items plus tax when no subtotal is printed, which is the Chapter 7 cross-check as a rule), and a provenance check that runs only when `$sourceText` is passed to `extract()` (`Provenance::missingNumbers()`). `InvoiceData` also has a nullable `error` field so the model has a sanctioned way to decline.
- `App\Ai\Schemas\TicketData`: `category` (`TicketCategory`, emitted as a schema enum) plus nullable `invoice_number`, `customer_email`, `amount_mentioned`, `date_mentioned`.
- `invoice:read` now calls `extract(InvoiceData::class, InvoicePrompt::messages($dataUrl), feature: 'invoice.extract')` against `config('ai.vision_model')` and prints vendor, invoice number, due date, total, line count, and `repaired: yes|no`. `InvoicePrompt::parse()`, `ExtractedInvoice`, `UnreadableInvoice::noJsonInReply()` and the token/time line are removed.
- `ClassifyTicket` returns a `TicketCategory` via `extract(TicketData::class, ...)` and logs under `ticket.classify` with the `TicketClassifyPrompt` version. A reply outside the allowed categories is now repaired once and then raises `StructuredOutputFailed` instead of silently becoming `other`; `ClassifyReceivedTicket` catches that, logs a warning, and stores `other`. `ticket:classify` compares `->value` and fails with the exception message when a sample cannot be classified.
- `php artisan ticket:extract [path]` (default `storage/samples/tickets.json`) with `TicketExtractPrompt` (`VERSION` `2026-11-09.1`, logged under `ticket.extract`): prints `N/N valid · R repaired · F failed` and the count of filled nullable fields.
- Usage log: `ModelCalled` gains `repaired`, `AiHttpClient::chat()` takes a `repaired` argument, and migration `2026_01_04_000000_add_repaired_to_model_calls_table.php` adds `model_calls.repaired` (boolean, default false, indexed with `feature`). The repair attempt is the row with `repaired = true`.
- Tests: `tests/Unit/Ai/Schema/SchemaBuilderTest.php`, `tests/Unit/Ai/Schemas/InvoiceDataTest.php`, `tests/Unit/Ai/Structured/{StructuredClientTest,ProvenanceTest}.php`, `tests/Unit/Ai/Prompts/TicketExtractPromptTest.php`, `tests/Feature/Console/TicketExtractCommandTest.php`, and rewritten `InvoiceReadCommandTest`, `ClassifyTicketTest`, `ClassifyReceivedTicketTest`, `TicketClassifyCommandTest`, `TicketClassifyBehaviorTest`. Every model reply is scripted with `Http::fake()`; no real model was called, so how often a real model needs a repair, and what the bad-scan sample does, is unmeasured.

## ch09

- Prompt classes in `app/Ai/Prompts/`: `SupportPrompt` v2 (`VERSION` `2026-11-01.2`, moved from `App\Ai\Support`; the four-part system message from Figure 9-9, `messages($question, $contextChunks = [], $history = [])` puts the question in a user turn after a `Context:` block that reads `(no context provided)` until Chapter 15) and `TicketClassifyPrompt` (`VERSION` `2026-11-01.1`, `LABELS`, four few-shot turns, the message always last in a user turn). `SupportAssistant` now builds its messages with `SupportPrompt::messages()`.
- `ClassifyTicket` (`app/Ai/Support`): deterministic profile, `max_tokens` 4, stop at a newline, trim and lowercase, any label outside `TicketClassifyPrompt::LABELS` becomes `other`. Logged under feature `ticket.classify`.
- `php artisan ticket:classify [path]` (default `storage/samples/tickets.json`) prints `N/20 agree with human labels (P%)` and a `misses:` list. `storage/samples/tickets.json` holds twenty messages with human labels (`message`, `label`). No agreement figure is recorded here: it has to be measured against a real model.
- `TicketReceived` event and `ClassifyReceivedTicket` listener: classify the ticket body and store the result in `tickets.category`. Nothing dispatches the event yet because the app has no inbound ingestion (tickets are seeded); `TicketCategory` gains `Account`.
- Usage log: `ModelCalled` gains `promptVersion`, `AiHttpClient::chat()` and `stream()` take a `promptVersion` argument, and migration `2026_01_03_000000_add_prompt_version_to_model_calls_table.php` adds a nullable `model_calls.prompt_version` (indexed with `feature`). `RecordModelCall` writes it. `support.reply` rows carry `2026-11-01.2`, `ticket.classify` rows `2026-11-01.1`.
- Tests: `tests/Unit/Ai/Prompts/{SupportPromptTest,TicketClassifyPromptTest}.php` (token budget, system-text hash that fails when the text changes without a `VERSION` bump, stored message snapshots in `tests/Unit/Ai/Prompts/snapshots/`), `tests/Unit/Ai/Support/ClassifyTicketTest.php`, `tests/Feature/Ai/Prompts/TicketClassifyBehaviorTest.php`, `tests/Feature/Console/TicketClassifyCommandTest.php`, `tests/Feature/Ai/ClassifyReceivedTicketTest.php`. The behavior tests carry `#[Group('model')]` and `#[Group('fake-model')]` and run against `Http::fake()` with a scripted reply per message, so they check the request shape and the validation of whatever comes back, not what a real model does. They never need Ollama.
- Session login, because the book's `curl -b cookies.txt` flow needs a signed-in session and no chapter builds one: `POST /login` (`email`, `password`; JSON `{"data": {id, name, email}}`, 422 on bad credentials, throttled to 10 per minute), for example `acme@example.com` / `password`. `login` and `support/*` are exempt from CSRF token checks so `curl` with a cookie jar works; this is a local demo setting, not something to ship.

## ch08

- `App\Ai\Http\AiHttpClient`: `chat()` merges a sampling profile (default `assistant`; `'profile' => null` sends none) with per-call options, POSTs to `/chat/completions`, parses a `ChatResponse`, and dispatches `ModelCalled`. `stream()` is a generator that yields content deltas, returns the assembled `ChatResponse`, dispatches the same event, and throws `StreamTruncated` after yielding the partial content when the stream ends without `[DONE]`. Transport failures surface as `ModelRequestFailed` (unreachable or rejected).
- `ChatResponse`, `Usage` (prompt and completion tokens plus provider extras), `StreamAccumulator`, and `RetryPolicy` (HTTP client retry on 408, 409, 425, 429, 500, 502, 503, 504, 529 and connection failures: 3 attempts, 500 ms doubling backoff plus up to 250 ms jitter, `Retry-After` honored). New exceptions `StreamTruncated` and `UnknownSamplingProfile`.
- `php artisan ai:stream "<prompt>"` (`--profile=assistant`).
- `POST /support/ask` (`auth`, `throttle:ai`): `SupportController`, `AskRequest` (`question`: required string, max 2000), `App\Ai\Support\SupportAssistant`, and `SupportPrompt::system()` (v1). The reply shape is the book's `{answer, truncated}`, not the `data` envelope. The `ai` rate limiter allows `config('ai.rate_limit')` (`AI_RATE_LIMIT`, default 30) requests per minute per user. Requests under `support/*` always render JSON errors (a guest gets 401 instead of a redirect to a login route the app does not have yet).
- `config/ai.php`: `provider` (`AI_PROVIDER`, default `ollama`) and `rate_limit`; `.env.example` gains `AI_PROVIDER`.
- Reconciled with ch05 to ch07: `App\Ai\Http\ModelEndpoint` is removed. `ChatSampler` (behind `ai:sample` and `invoice:read`) now calls `AiHttpClient::chat()` with no profile merged, so its requests are unchanged but its calls are now logged to `model_calls` (features `cli.sample` and `invoice.read`). `Embedder` posts through `AiHttpClient::post()`, gaining the retry policy; embedding calls are not written to `model_calls`. `ModelCalled` gains the `Dispatchable` trait.
- Tests: the Chapter 2 prompt-budget test (`BudgetTest`) now has `SupportPrompt` to measure, and `ApiKeyTest` asserts no real key in the testing environment. Every HTTP and streaming test uses `Http::fake()` and `Sleep::fake()`; no real model has been called, so the client's behavior against live Ollama or a cloud provider is unverified.

## ch07

- `php artisan invoice:read <path>` takes a PDF or an image, resizes it to at most 1024 by 1024 pixels as a JPEG (quality 85), sends it as a base64 `image_url` data URL with the system and extraction prompts to `config('ai.vision_model')` using the `deterministic` profile, parses the JSON reply, and prints a field table plus `total (model)`, `total (lines)`, and `agree?` (`NO --- review` when they differ by 0.01 or more). A final line prints prompt and completion tokens and the elapsed seconds.
- `App\Ai\Vision`: `ImageEncoder`, `PdfPageRenderer`, `InvoicePrompt` (system prompt, extraction prompt, lenient `parse()` that tolerates code fences and surrounding prose and throws `UnreadableInvoice` when there is no JSON), and `ExtractedInvoice`.
- `ChatSampler::chat()` accepts a full `messages` array so the user message can carry an image part; `sample()` now calls it.
- `config/ai.php` `vision_model` (`AI_VISION_MODEL`, default `llama3.2-vision:11b`).
- `storage/samples/`: `clean.pdf` (born-digital), `phone-photo.jpg` (rotated, shaded, blurred), and `bad-scan.jpg` (low-resolution, noisy), generated programmatically from one synthetic invoice whose line items sum to 1,284.50.
- Dependency: `intervention/image-laravel` (GD driver). PDFs are rendered with Poppler's `pdftoppm` rather than Imagick, so no PHP extension beyond GD is needed.
- Tests fake the model with `Http::fake()`; the three sample files have not been read by a real vision model.

## ch06

- `App\Ai\Embeddings\Embedder` (`query()` and `document()` add the `search_query: ` / `search_document: ` prefixes, POST `/embeddings`, and check the vector length against `AI_EMBED_DIMENSIONS`), `MarkdownChunker` (splits on `##` headings, keeps `Title › Heading` in every chunk, merges short neighbouring sections and splits long ones on paragraphs to stay within 200 to 400 tokens of the bound `TokenCounter`), and `HelpIndexer`.
- `php artisan help:embed` chunks `resources/help/**`, embeds every chunk, and upserts `help_chunks` by `(source, position)`, removing chunks of vanished articles or positions. `php artisan help:search "<question>" [--limit=5]` embeds the question and prints `similarity source › heading` for the nearest chunks.
- Migration `2026_01_02_000000_create_help_chunks_table.php`: pgvector extension, `help_chunks` (`source`, `position`, `heading`, `content`, `tokens`, `vector(768)` column) with an HNSW cosine index and a unique `(source, position)` index. `App\Models\HelpChunk` and `App\Casts\Vector`.
- `config/ai.php`: `embed_model` (`AI_EMBED_MODEL`, `nomic-embed-text`), `embed_dimensions` (`AI_EMBED_DIMENSIONS`, 768), `chunking`. `.env.example` gains the two env vars.
- `App\Ai\Http\ModelEndpoint` is the shared HTTP call for `ChatSampler` and `Embedder`; `AiHttpClient` still arrives in Chapter 8, so `Embedder` takes `ModelEndpoint` rather than the book's `AiHttpClient`.
- The test suite now runs on a Postgres `ledgerly_test` database with the `vector` extension (`phpunit.xml`, `docker/postgres/init.sql`). Embedding calls in tests are faked with `Http::fake()`.

## ch05

- `php artisan ai:sample "<prompt>"` with `--profile`, `--model`, `--runs`, `--temperature`, `--top-p`, `--top-k`, `--min-p`, `--max-tokens`, `--seed`, `--frequency-penalty`, `--presence-penalty`, `--repeat-penalty`, and repeatable `--stop` (`\n` is read as a newline). Prints `[n] completion  finish_reason · in N out M` per run.
- `App\Ai\Sampling\ChatSampler` and `SampleResult`: the one OpenAI-compatible `/chat/completions` call behind the command, with `App\Ai\Exceptions\ModelRequestFailed` for unreachable, rejected, and malformed responses. The full client (`AiHttpClient`) still arrives in Chapter 8; `ai:sample` does not write `model_calls` rows.
- `config/ai.php`: `base_url`, `api_key`, `model`, `connect_timeout`, `timeout` (all from `.env`), and the four sampling `profiles` (`deterministic`, `grounded`, `assistant`, `creative`) from Figure 5-10.
- Tests use `Http::fake()` only; no model is called by the test suite.

## ch04

- Identical to `ch02`; the chapter has no companion-app change. The tag points at the same commit.

## ch03

- Identical to `ch02`; the chapter has no companion-app change. The tag points at the same commit.

## ch02

- `model_calls` usage log: migration `2026_01_01_000000_create_model_calls_table.php`, `App\Models\ModelCall`, the `App\Ai\Events\ModelCalled` event, and the `App\Ai\Listeners\RecordModelCall` listener (cost in micro-dollars from `config('ai.rates')`, warning log when `finish_reason` is `length`).
- Token counting behind one interface: `App\Ai\Tokens\TokenCounter`, `TiktokenCounter` (exact, `yethee/tiktoken`, default `o200k_base`) and `RatioCounter` (estimate, 3.6 characters per token by default). `config/ai.php` `token_counter` (env `AI_TOKEN_COUNTER`: `ratio` or `tiktoken`; `server` is reserved for Chapters 22 and 23 and raises `UnsupportedTokenCounter`).
- `config/ai.php` `budget` and `App\Ai\Prompt\Budget` (history allowance, margin, `assertFits`, `PromptTooLarge`), with the Figure 2-8 numbers for an 8,192-token window.
- Tests under `tests/Feature/Ai` and `tests/Unit/Ai`. The book's system-prompt-fits-budget test waits for `SupportPrompt`, which arrives in Chapter 8.
- No model is called; no AI feature exists yet.

## ch01

- Initial Ledgerly baseline: Laravel app with customers, invoices, payments, a support inbox, and a 120-article help center in `resources/help/`.
- Docker Compose for Postgres (with pgvector) and Redis.
- Seeders: 40 customers, about 600 invoices in every status, 93 support tickets.
- No AI code.
