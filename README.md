# Smart Search

Replaces the WordPress search with an index of its own. Three stages build on each other:

| Mode | What it does | Needs |
| --- | --- | --- |
| Keyword | Full-text search on the plugin's index (MySQL/MariaDB FULLTEXT) | nothing |
| Semantic | Additionally finds content by meaning (embeddings, cosine similarity) | AI provider |
| AI answer | Additionally writes a short answer from the best results, with sources | AI provider |

If the provider is unreachable, the monthly budget is used up or a request limit is hit, the search continues as a keyword search.

Requires WordPress 6.6 and PHP 8.1. Global identifiers use the prefix `prstsearch`, PHP classes live in the namespace `PrstSearch`.

## How it works

- **Index.** `wp_after_insert_post` renders a post through `the_content`, reduces it to plain text, splits it into passages and stores them in `{prefix}prstsearch_chunks`. What the database searches is not the text itself but the stem of every word, and that stem written backwards: so "Rezepte" finds "Rezept", and "Kosten" finds "Versandkosten", which a FULLTEXT index cannot do by itself. A word whose stem has three letters is too short for that: "tur" also ends "Natur". Such a word counts as the end of a longer one where the rest is a word the website uses too ("Haus" in "Haustür"), and a little where the rest is at least long enough to be a word and the ending is written exactly as typed ("Mietwagen" for "Wagen" on a website that never says "Miete"). Which compounds a website has is read from its vocabulary. If no page has the search words in any of these ways, pages are shown that contain them anywhere inside a word ("hof" in "Bahnhofstraße"); that reads the whole index, is remembered for ten minutes if it finds nothing, and can be switched off. Because the rendered output is indexed, the editor or page builder does not matter. Keyword search is current the moment a post is saved.
- **Embeddings** are created in the background (WP-Cron) and stored as packed 32-bit floats. Passages whose text did not change keep their embedding, so an edit only costs tokens for what was touched. The comparison runs in PHP. Up to 2,000 passages every one is compared. Beyond that the database first selects the 600 most promising ones by a 512-bit signature of their embedding, read from an index, and only those are compared exactly; that keeps a search fast on websites with thousands of pages.
- **Ranking.** Keyword and semantic results are merged with reciprocal rank fusion and labelled with one of three relevance levels. A page is judged as a whole, wherever on it the words are. Keyword results are ordered by how many of the words a page has, then by how close together they stand, then by the score of the database, and among equals the newer page comes first. A query that finds nothing relevant is searched again with its spelling corrected against the words of the website, and, if enabled, the language model picks the fitting pages from the nearest ones. Through the REST API the results come at once and the model's additions follow in a second request, so nobody waits for it.
- **Recommended results.** In the settings a page can be named for certain searches ("opening hours" → contact page). It is then listed first and marked as recommended. A rule applies in every word form and to longer searches that contain its words. It is meant for the handful of searches the statistics show to find nothing, not as a synonym list.
- **Notation.** `"chicken recipe"` in quotation marks must appear word for word, `-vegan` excludes pages with that word. Both apply to the whole page.
- **What is shown.** Every hit passes one filter before it is returned, whether as a result, a suggestion or a source of an AI answer: it must still be public and searchable. Content is rendered for the index as a logged-out visitor sees it.
- **Providers.** OpenAI, Google Gemini, Mistral and any OpenAI-compatible endpoint (for example Ollama) through one client. The API key is stored encrypted, or defined as `PRSTSEARCH_API_KEY` in `wp-config.php`.
- **Statistics.** Search phrase, time, mode, number of results and optionally tokens, costs, response time and the clicked result. No IP address, no user ID. Costs are stored with each search at the prices of that moment.
- **Streaming.** AI answers can be shown while they are written (off by default). The provider's stream is passed on as server-sent events; the finished answer, with its links and sources, replaces the text at the end. If the server holds output back, the provider does not stream or the browser cannot read event streams, the answer arrives in one piece as before. An answer that breaks off is not shown, but what the provider had written of it counts towards the costs and the budget. A client plugged in through `prstsearch_ai_client` takes part by implementing `StreamingAiClient`.
- **Caches.** The comparison by meaning of a query is kept for ten minutes, the embedding of a query for an hour, AI answers and the model's choices for a day, suggestions for five minutes. Values derived from the whole index, the vocabulary for spelling correction and the mean embedding, are never rebuilt while a visitor waits: after a change the previous value serves until the new one is ready, and the very first one is built by the one request that gets a lock, while others wait for it.
- **Limits.** Visitors can trigger paid provider calls and entries in the statistics, so both are capped per minute, for the whole site and per visitor. Visitors are told apart without anything being kept that identifies them: the IP address is hashed together with the current minute and cut down to one of 4,096 group numbers, which many addresses share and which is another one a minute later. Only a count per group is stored, for two minutes. The address itself is never stored and nothing of it reaches the statistics. Behind a proxy or CDN every visitor arrives with the same address; pass the real one through `prstsearch_visitor_address`, otherwise all visitors share one limit. A search is cut to 200 characters and returns at most 200 results; the number of results is a setting.

## Integration

1. **Replace the engine only** (default): the theme's search form and results template stay; only the posts handed to the loop come from the index.
2. **Block / shortcodes:** the block "Smart Search", or `[prstsearch]`, `[prstsearch_form]`, `[prstsearch_results]`, `[prstsearch_answer]`.
3. **Own templates:** copy a file from `templates/` (form, results, result, type filter, answer) to `smart-search/` in the theme, or use `prstsearch_form()`, `prstsearch_results()`, `prstsearch_answer()`, `prstsearch_get_results()`.

### REST API

Namespace `prstsearch/v1`.

| Route | Access | Purpose |
| --- | --- | --- |
| `GET /search?q=…&page=…&type=…` | public | One page of results, as data and as rendered HTML, optionally of one content type. `pending` in the response means the language model can still add to it; the same request with `refine=1` and the `token` returns the page after it was asked |
| `GET /suggestions?q=…` | public | Page titles for the text typed so far |
| `GET /answer?q=…` | public | The AI answer to a query |
| `GET /answer/stream?q=…` | public | The same answer as server-sent events while it is written, if that is switched on |
| `POST /click` | public | Records the result that was opened, if click tracking is on |
| `GET`, `DELETE /index` | manage | State of the index; empty it |
| `POST /index/rebuild`, `/index/embeddings` | manage | Rebuild the index step by step |
| `GET`, `POST /index/posts/{id}` | editors of the post | Index status of a post; reindex or exclude it |
| `GET /index/pages?q=…` | manage | Indexed pages by title, for choosing a recommended result |
| `POST /provider/test`, `GET /provider/models` | manage | Connection test and model list of the provider |

The public routes return nothing a visitor could not read on the published website.

### Hooks

| Hook | Purpose |
| --- | --- |
| `prstsearch_is_indexable` | Decide per post whether it is indexed |
| `prstsearch_post_text` | Change the text indexed for a post |
| `prstsearch_hits` | Filter the hits of a query: results, suggestions and sources of AI answers alike |
| `prstsearch_centered_similarity_thresholds` | Tune the relevance levels of semantic search |
| `prstsearch_similarity_thresholds` | The same for small websites, where similarities are compared as the provider reports them |
| `prstsearch_ai_client` | Plug in a provider with a different API |
| `prstsearch_requests_per_minute` | What visitors may trigger per minute, for the site and per visitor, by kind (`chat`, `embedding`, `log`); 0 switches a limit off |
| `prstsearch_visitor_address` | The address visitors are told apart by for these limits; needed behind a proxy or CDN |
| `prstsearch_template` | Change the path of a front-end template |

## Measuring

Three scripts in `tests/benchmark/` run against a real installation, given as `WP_LOAD_PATH=/path/to/wp-load.php`:

| Script | What it does |
| --- | --- |
| `travel-corpus.php [count] [first]` | Creates travel offers in German as posts, the same text for the same number. `delete` removes them again |
| `measure.php` | Times a list of searches, with the number of results and database queries |
| `quality.php` | Compares the keyword search with the search of WordPress on the corpus: for each of about forty queries it is known which trips are wanted |

On 3,000 posts (3,500 passages) on a development machine a search takes 10 to 250 ms on top of what WordPress needs to start, a question of ten words about 400 ms, and uses seven to eleven database queries whatever the number of results. In the comparison all of the first ten results were wanted ones for every query, against two thirds with the search of WordPress, which finds nothing for another form of a word or a typing error. The queries were written by the author of the plugin; the numbers say what the rules are built for, not how visitors of a given website search.

## Front-end styling

The front-end components inherit font and colour from the theme. Everything else is a custom property. The defaults have no specificity, so a plain rule in the theme overrides them, wherever its stylesheet is loaded:

```css
.prstsearch-form,
.prstsearch-results,
.prstsearch-answer {
	--prstsearch-accent: #b4002d;
	--prstsearch-radius: 0;
}
```

The admin tokens are defined in `assets/src/styles/_tokens.scss`.

## Development

```bash
composer install
npm install

npm run build        # compile SCSS and JavaScript to build/
npm run start        # watch mode

composer check       # coding standards, static analysis, PHP unit tests
composer test:integration   # the SQL against a real database, see below
npm run lint:js
npm run lint:css
npm test             # JavaScript unit tests

composer i18n:pot    # refresh languages/smart-search.pot
composer i18n:build  # compile the .po files (needs php and node on the PATH)
```

The integration tests need a WordPress installation to connect through. They create tables of their own and remove them again; the tables of the plugin and the content of the site are not touched. Without the variable they are skipped.

```bash
WP_LOAD_PATH=/path/to/wp-load.php composer test:integration
```

The tests of the key storage need the sodium extension, which WordPress replaces with a fallback at runtime: `php -d extension=sodium vendor/bin/phpunit`.

At runtime only `build/` is needed. The plugin has its own PSR-4 autoloader and runs without `vendor/` and `node_modules/`.

### Layout

```
src/
  Admin/      settings page, form handling, status indicators
  Contracts/  interface of the services that register hooks
  Database/   schema, the two repositories (the SQL on the plugin's tables lives here) and their interfaces
  Frontend/   query integration, block, shortcodes, templates, assets
  Index/      rendering, chunking, indexing, background jobs, status
  Privacy/    suggested text for the privacy policy
  Provider/   AI client, provider catalog, prices, budget, request limits
  Rest/       REST routes
  Search/     keyword and semantic search, ranking, spelling, AI answer
  Security/   capabilities, storage of the API key
  Settings/   schema, sanitizer, storage
  Stats/      search log, reporting periods, charts
  Support/    container, cache, lock, asset and CSV helpers
templates/    front-end templates, overridable by themes
views/admin/  markup of the settings page
assets/src/   SCSS and JavaScript sources
tests/        PHPUnit (unit and integration) and Jest tests
```
