# Published fact-check discovery and collected-review lookup

## Confirmed causes

Report #21 asked for the actual unit price of pictured vehicles. The old first
queries described generic emergency vehicles and did not include the caption's
distinctive monetary allegation. The local public-review collection contained
86 records, with no fire-vehicle review or the supplied Rappler article. A recent
feed is a partial collection, not a complete index of published investigations.

The user's open information question was also used to match collected reviews;
it lacked the subject and allegation used in publisher claim-review metadata.
Finally, useful partial findings were discarded when they did not establish the
exact price, and unsuccessful procurement-page fetches appeared as source cards.

## General changes

- Preserve the question as the answer target while using OCR caption allegations
  and visible subject descriptions for discovery. The first-pass search includes
  a caption amount or substantive caption sentence with fact-check terminology.
- Search collected reviews using both the requested claim and caption context.
  Caption discovery requires a substantive subject/category anchor and caption
  overlap; common words, colors, generic descriptors and counts alone cannot
  match an unrelated publication. This wider discovery does not approve a verdict.
- Consider publications from other publishers as candidates, retaining their
  source classification. A publisher outside the preferred list is not promoted
  to a trusted fact-check authority by a saved rating.
- Read a supplied reference link alongside an uploaded image. Preserve OCR and
  the user question even if the supporting link fails; do not turn the reference
  article into additional submitted assertions.
- Retain validated contextual findings when an exact value remains unknown. A
  partial relationship may supply literal, bounded related passages with matching
  caption/category anchors and dates. These are contextual citations, not an
  authenticated image match or a transferred publisher verdict. Preserve quote
  boundaries and magnitude qualifiers; unrelated projects remain ineligible.
- Show related findings in the explanation and label related source cards.
  Exclude failed fetches and rejected/unrelated candidates from ordinary evidence
  cards. Located fact-check pages that cannot be read receive a separate notice.
  Only explicitly supplied or relevance-matched unavailable references appear in
  that notice. Source badges use explicit ratings; descriptive text containing
  words such as “authenticated” cannot manufacture a Confirmed badge.
- Collect metadata from readable publications with an extracted, complete
  claim-review record. Store the publisher's original claim/rating, publication
  date and provenance for future discovery. Do not create an approved
  `ReviewedClaim` or treat a publisher label as independent ground truth.

These rules apply across publishers and topics. No Rappler URL, vehicle verdict,
purchase price, or preferred conclusion was hardcoded into production logic.
Existing source-fetch safety, source-priority rules, budgets and exact-answer
reuse safeguards remain in effect. No new dependency or migration is required.

## Report #21

The application's article reader retrieved the user-supplied Rappler page. Its
publisher metadata was added to `public_claim_reviews`, increasing the collection
from 86 to 87 records. A real local lookup then returned that relevant publication
for the caption and visible subject, while excluding unrelated reward claims with
the same amount. A question-only query remains insufficient for that match.

The report was researched again with the supplied reference. After the live
review marked related passages insufficient to establish the exact price, the
new contextual retention rule was applied to its frozen, retrieved evidence.
That last reconstruction made zero additional API requests and preserved the
overall Missing Context verdict. The report now includes cited source context
explaining the different procurement. It does not claim that the actual unit
price, the caption's lower estimate, or exact image identity was established.

Original results, fetched source data, recheck results, reconstruction metadata
and desktop/mobile screenshots are preserved under
`output/validation/report-21-fact-check-discovery/`.

## Implementation and verification

Changed services: `Evidence/ClaimPlanner.php`, `Evidence/ResearchGuidance.php`,
`Evidence/ArticleSearch.php`, `Evidence/SubmissionExtractor.php`,
`Evidence/EvidenceJudge.php`, `Evidence/EvidencePassages.php`,
`EvidenceVerificationService.php`, `ReportAnswer.php`, `ResultWording.php`,
`AnswerEvidenceValidator.php`, and `ResultContract.php`.
The existing advanced result card now distinguishes related and unavailable
sources. Evidence and wording versions invalidate incompatible fresh-result reuse.

New regression suites cover multiple publishers, unfamiliar publishers,
nonfinancial captions, shared amounts on unrelated subjects, metadata collection,
image-plus-link extraction, partial findings and failed source presentation.
Existing research, source scope, cache/reuse, quotation and wording tests remain
part of verification. One obsolete cache-version fixture was corrected to use
the current article cache key. Report #21 was rendered read-only and checked at
1366px and 390px; its source links and overflow checks passed.
The final combined suite passed 190 tests with 1,497 assertions. Source-card
checks also verify that contextual findings are not labeled Confirmed.

This work improves discovery and evidence handoffs. It is not an independently
measured accuracy improvement and does not guarantee that every relevant article
will be found. Coverage still depends on the collection, provider indexes,
available source content, query quality, and the configured research budget.
