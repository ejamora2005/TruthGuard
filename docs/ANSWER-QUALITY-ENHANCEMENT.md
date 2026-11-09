# Answer quality enhancement — November 9, 2026

## Findings and implemented changes

The existing Laravel pipeline already separates image text from visual context,
plans claims, prioritizes compatible reviewed findings, searches several providers,
fetches source documents, validates evidence relationships and assembles a typed
report. The local classifiers remain separate from factual evidence. These
components were retained.

Three concrete gaps were addressed:

| Failure | Root cause | Change |
| --- | --- | --- |
| A request for an actual price can become a check of a different caption allegation. | The question parser recognized yes/no requests but omitted open information questions. | Preserve English and Filipino information questions as explicit research targets; retain image context separately and prevent an automatic Yes/No introduction. |
| Currency-qualified claims can match incompatible reviewed variations. | Normalization removed currency symbols and percentages. | Preserve currency/percentage scope and re-normalize the original stored variation before accepting its lookup hash. Old unsafe hashes fail closed. |
| Fluent wording can contain facts not established by its citations. | The wording contract checked format, verdict, length and source-ID membership, without sentence-to-passage bindings. | Add a final, deterministic statement-validation stage and an extractive fallback; the evidence-based verdict is unchanged. |

## Evidence-to-answer validation

The existing evidence-review request now optionally returns `statement_evidence`
inside its wording candidate. Each explanation sentence has a claim ID, source
ID and an exact supporting excerpt. This uses the same request, not a separate
paid rewrite or model self-grading call. If the model cannot provide a bounded,
grounded explanation, it can omit the optional wording candidate.

`AnswerEvidenceValidator` runs after relationship validation and verdict
assembly. It checks:

- All explanation sentences are covered, in order.
- Each source/claim binding points to an admitted relationship or context finding.
- The quoted passage belongs to that source and claim.
- Figures retain their currency, magnitude and selected units.
- Quoted wording is faithful to the bound passage.
- There is a basic lexical anchor between a statement and its passage.
- Selected negation and numerical-estimate qualifiers are not reversed or lost.
- The citation list matches the bound source IDs.

The short verdict rationale is generated from the final verdict by the server;
it cannot introduce an unchecked price or new event. The recommended action is
also server-owned and procedural; advice cannot smuggle in a new allegation.

When a wording candidate fails, retain its diagnostic issues, preserve the
verdict and display an exact, attributed passage from the validated evidence.
Conflicting evidence retains both directions when complete quotes fit the
display allowance. If they cannot fit, show the fixed conflict rationale rather
than truncating qualifications or selecting one side. No full source body or
unreviewed discovery snippet becomes decisive evidence through this fallback.

Relationship-level explanations also replace unsupported numerical additions
with the original verified passage. The quote and evidence relationship remain
intact; a prose defect does not erase valid evidence or manufacture a new verdict.

`answer_validation` records whether generated text passed bounded checks or was
rebuilt. Its scope is explicitly limited. These deterministic checks are not a
complete semantic-entailment classifier: paraphrases, complex negation,
causation, spelled-out quantities, subject substitutions and mathematical
derivations still require independent review. A valid binding is not a guarantee
that a source is truthful or that every paraphrase follows logically. Numerical
equivalents outside the supported notation may be conservatively rejected.
New calculations are not inferred merely because numbers appear in a record;
unsupported unit prices cannot be supplied by the prose generator.

## Question and reuse behavior

The original question remains in `request.question`. Open questions such as
“How much did each pictured vehicle actually cost?” and “Magkano talaga ang bawat
sasakyan?” remain the planning target. They do not become a guessed price or an
unrelated claim from the image. Searchable factual information questions remain
eligible for research; value judgments remain non-factual.

Reviewed database reuse remains exact and subject to existing expiry, correction,
cutoff and scope safeguards. Currency-aware matching does not add a semantic
index, a vector database, or automatic fuzzy verdict reuse. Existing variations
with obsolete currency hashes may require explicit re-saving/review before they
can match again. No historical report or variation is silently rewritten.

## Preserved architecture and costs

- Existing search providers run before supplementary OpenAI web discovery.
- Preferred original/institutional/research sources remain priorities, with
  broader retrieved-source review when unresolved claims need it.
- Existing source passage, date, conflict, supersession and scope checks remain.
- Existing OpenAI models, token limits, retry controls and queue settings remain.
- AI-origin weights, thresholds, scores and video frame scope remain unchanged.
- The stage-evaluation review sheet now includes generated wording and the answer
  validation diagnostics alongside evidence and claim data.

No dependencies, migrations, File Search index, embedding service, function-call
layer or alternative queue were needed for these confirmed failures. Optional
retrieval infrastructure should be assessed only against measured retrieval
gaps. Additional statement bindings consume response tokens within the existing
allowance; complex responses can fall back to validated passages rather than
exceed the configured budget.

## Compatibility and operation

Files changed for this enhancement:

- Question handling: `SubmissionTask.php`, `Evidence/ClaimPlanner.php`,
  `ReportAnswer.php` under `app/Services/Detections/`.
- Evidence and wording: new `AnswerEvidenceValidator.php`, `ResultWording.php`,
  `ResultContract.php`, `Evidence/EvidenceJudge.php`, `EvidenceVerificationService.php`.
- Reuse and calibration compatibility: `ReviewedClaimLookup.php`,
  `EvidenceVerdictCalibration.php`.
- Evaluation observations: `automation/evaluation/evaluate_pipeline.py`.
- New tests: `tests/Unit/AnswerEvidenceValidatorTest.php`,
  `tests/Unit/InformationQuestionTest.php`, `tests/Feature/AnswerQualityWorkflowTest.php`.
- Extended regressions: `tests/Unit/EvidenceMeaningGuardTest.php`,
  `tests/Feature/ReviewedClaimLookupTest.php`.
- Documentation: this report, `README.md`, `docs/PIPELINE-EVALUATION.md`.

The task resolver, evidence pipeline and wording versions were advanced. The
existing submission fingerprint already includes wording/evidence versions;
fresh requests cannot silently inherit incompatible old generated prose.
Calibration identity now covers the wording validator and reviewed-claim lookup
as well. Existing validated calibration artifacts cannot silently certify a
changed pipeline. Historical reports remain stored as originally recorded.

Restart long-running evidence workers after deploying the changed services.
No database migration or environment-variable change is required. Continue to
run a dedicated `evidence` worker when queued checks are enabled.

## Verification and remaining evaluation

Regression coverage includes open price/identity/date questions, yes/no wording
boundaries, currencies, percentages, stale normalized hashes, unsupported prices,
misattributed passages, omitted sentence bindings, units, estimates, negation,
conflicting quotes, changed source snapshots and normalization of claim IDs.
Existing research, context, cached-answer, report and media tests remain relevant.

The combined verification run passed 280 PHP tests (2,097 assertions), and the
stage evaluator's eight Python tests passed. Report fixtures rendered without
database changes. These counts describe software verification only.
After hardening procedural recommended actions, all 25 targeted wording/workflow
tests passed as a final follow-up. That run overlaps the combined suite.

One fresh, forced-research pilot used the original saved vehicle image and the
question “How much did each pictured vehicle actually cost?”. Extraction,
planning and two evidence reviews completed, along with supplementary web search.
The five OpenAI requests reported 24,467 tokens and approximately 59 seconds.
The claim stayed a factual information question and the result was Unverified;
no matching actual unit price was established. This is different in scope from
checking the caption's separate ₱10-million allegation.

That pilot exposed caption text leaking into the fallback's description of the
question. The final fix was replayed against the frozen evidence, without another
paid research run. The resulting answer is “The actual per-unit purchase price
could not be established from the reviewed records.” Original pilot archives and
saved report #20 were retained. Artifacts are under
`output/validation/answer-enhancement-2026-11-09/`; the presentation replay is
explicitly marked as a replay rather than a new research result.

Use the [stage evaluation workflow](PIPELINE-EVALUATION.md) to review actual
explanations and citations. Test fixtures establish behavior, not factual accuracy.
Live captures are development diagnostics, not independent labels or benchmark
results. Independent held-out review is still required to measure verdict
accuracy, citation precision, answer relevance and unsupported-statement rate.

## Fuller public explanations

Public evidence-review wording now targets 140–220 words and six to eight
grounded sentences when the admitted evidence supports that detail. Sentence
bindings support up to eight sentences. The wording contract allows up to 320
words; a sparse record still requires a shorter, qualified explanation.

The local fallback retains up to three distinct admitted passages, preserving
both directions for conflicting evidence. A short fallback explains the exact
claim and the scope of the cited material. Missing original dates and the
difference between a factual caption check and AI-origin screening are explained
when the saved input records those conditions. An absence of records is not
described as proof that an event never occurred.

Short saved extractive fallbacks receive this fuller presentation when opened,
without rewriting their persisted verdict, sources, assessment date or usage
ledger. Validated generated narratives remain intact. No additional model
request, source search or automatic reanalysis is added; future wording remains
part of the existing evidence-review response. Restart evidence workers after
deploying these PHP changes. No migration or new environment setting is needed.
