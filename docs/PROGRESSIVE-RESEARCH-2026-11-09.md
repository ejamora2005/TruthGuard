# Progressive evidence review and token use

The [v31 expansion update](RESEARCH-EXPANSION-2026-11-09.md) adds progress-aware
pagination, earlier targeted discovery and protection against recursive
reference crawling for unresolved claims.

`evidence-v30` changes research scheduling so a report can resolve before every
search page and linked article has been fetched. It replaces the exhaustive
first-pass scheduling introduced with unrestricted research in v29.

## Processing flow

1. Read matching collected publisher reviews before another external search.
2. Rank candidates using their claim/title relevance, giving matching published
   fact checks and original records priority. Read a small batch of documents.
3. Run the existing exact-review reconciliation or evidence judge immediately.
   The normal claim, date, quantity, quote and provenance checks still apply.
4. Stop discovery when the reviewed evidence resolves all requested claims.
   An exact published review can avoid paid relationship review and web search.
5. When evidence is insufficient, try other providers before draining one
   provider's continuation pages. Continue reading subsequent batches as needed.
   Linked records are queued rather than fetched in an unbounded chain before
   the first evidence review.
6. Use paid web discovery only for a real evidence gap, once per unchanged set
   of unresolved claim IDs. If that set changes, another targeted search remains
   possible. Later query variants focus on the unresolved claims.

EvidenceJudge already retains completed source reviews and sends only new
documents alongside compact prior findings. Stable document snapshots now also
avoid duplicate review checkpoints. Cached review reuse no longer advances the
displayed review-round number. A failed review stops further paid research and
keeps the existing unavailable-assessment behavior.

Current-status questions retain the additional later-status check. An early
supporting source does not disable the existing correction/supersession rules.
Related articles, search snippets and publisher names alone do not decide a
verdict. The evidence gates are unchanged.

## Configuration

- `FACT_CHECK_PROGRESSIVE_RESEARCH=true` enables the staged flow (default).
- `FACT_CHECK_UNRESTRICTED_RESEARCH=true` still permits broader research when
  evidence remains unresolved; fixed overall source/call caps are not restored.
- Documents are grouped into batches for context packing, rather than imposing
  a limit on total eligible evidence. Network timeouts and Stop remain active.
- Progress messages distinguish searching from reviewing matching evidence.

This reduces avoidable requests and repeated input. It is not a fixed billing
ceiling, a measured accuracy improvement, or a guarantee that difficult claims
will use a particular number of tokens.

## Verification

The final targeted regression run passed **193 tests with 1,286 assertions**.
Mocked tests verify that resolved evidence stops later page/provider requests;
unresolved evidence advances to another provider; unchanged gaps do not repeat
paid web searches; new sources are reviewed without resending old article
bodies; and true, false, unverified and unavailable outcomes remain valid.

No live fact check was submitted for testing. The previously expensive run was
already marked `user_stopped`; it was not restarted.
