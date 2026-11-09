# Changelog

Fixes go on `main`. Chapter tags never move once published; a chapter whose code had to change gets a new tag alongside the old one, such as `ch08-r2`.

## Unreleased

- Fix a flaky `help:search` test at and after `ch06`: `RefreshDatabase` rolls back the bulk inserts of the `help:embed` tests, which leaves dead entries in the HNSW index, and an approximate index scan over a handful of rows could then miss the right chunk. The two search tests now turn off index scans for their transaction so they compare exactly; no application code changed. Test-only, so the `ch06` tag is unaffected.

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
