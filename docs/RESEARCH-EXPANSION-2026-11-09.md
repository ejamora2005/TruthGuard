# Faster expansion without recursive crawling

The existing TG-30 job completed before the requested cancellation could stop
it. Its worker duration was 15 minutes 9 seconds and its recorded usage was
36,735 tokens. The saved result was retained. It was not rechecked during this
change.

`evidence-v31` keeps the existing evidence, scope, date, quantity and citation
validation. It changes how unresolved research expands:

- A provider's continuation pages require substantive evidence progress. More
  distinct URLs with the same insufficient finding are not progress. Other
  providers and unused query variants remain available.
- Newly discovered direct sources are reviewed before their linked references.
  Unresolved claims receive targeted web queries containing the exact assertion
  and requests for fact checks/original records before citation expansion.
- A separate cited-record pass remains available after targeted discovery. A
  pass that produces no new evidence is not repeated for an unchanged gap.
- A cited original record is not automatically treated as a new crawling seed.
  Direct PDF/full-text downloads from that record remain eligible, but arbitrary
  references of references are not recursively traversed.
- Evidence progress tracks claim outcomes, validated context categories and
  resolved subjects, rather than accumulated page counts or source IDs.
- Timing logs record provider/page status, new candidate counts and review
  checkpoints without logging claim text, article bodies or source URLs.

All previously eligible publishers remain eligible. The changes do not force
an outcome or reintroduce fixed total source/call caps. They also do not establish
a measured live speedup: the regression checks use synthetic provider responses.

The existing `FACT_CHECK_PROGRESSIVE_RESEARCH=true` configuration activates this
scheduling. Request reuse changes through the pipeline version. Existing saved
reports remain unchanged, and no live assessment is automatically restarted.

Verification passed **366 tests with 2,714 assertions**, including regression
coverage for dates, quantities, citations, source matching, factual/context
verdicts, AI routing, cancellation and the new expansion order. Local runtime
inspection confirmed v31 with progressive and unrestricted modes enabled and
an idle evidence queue before the worker was replaced.
