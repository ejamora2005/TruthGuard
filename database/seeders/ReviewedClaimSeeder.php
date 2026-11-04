<?php

namespace Database\Seeders;

use App\Models\ReviewedClaim;
use App\Services\Detections\KnownFactCheckCatalog;
use App\Services\Detections\ReportAnswer;
use App\Services\Detections\ResultWording;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ReviewedClaimSeeder extends Seeder
{
    public function run(): void
    {
        $philstar = 'https://www.philstar.com/headlines/2026/';
        $entries = [
            ['suan-fabricated-bato-shoot-to-kill-quote', 'FALSE', 'Lordan Suan threatened a shoot-to-kill resolution against Bato dela Rosa.',
                'The viral quote claiming Representative Lordan Suan threatened a shoot-to-kill resolution against Senator Ronald "Bato" dela Rosa is fabricated. Suan denied making the statement on November 2, 2026. Reports about dela Rosa\'s surrender deadline do not establish that Suan issued such a threat. This finding applies specifically to the quotation attributed to Suan.',
                'The statement was falsely attributed to Suan, who publicly denied it.',
                ['Lordan Suan threatened a shoot-to-kill resolution against Bato dela Rosa.',
                    'Lordan Suan warned Bato dela Rosa of a shoot-to-kill order if he fails to surrender.',
                    'If Bato dela Rosa fails to surrender by the deadline, I will convene a House committee hearing and file a shoot-to-kill resolution.',
                    'Nagbanta si Lordan Suan ng shoot-to-kill resolution laban kay Bato dela Rosa.',
                    'FACT CHECK: Shoot-to-kill Bato dela Rosa warning by Lordan Suan is fake'],
                [
                    ['Philstar', 'House prosecution panel member denies shoot-to-kill claim vs Bato', 'https://www.philstar.com/headlines/2026/11/02/2567378/house-prosecution-panel-member-denies-shoot-kill-claim-vs-bato/amp/', 'Documents Suan\'s November 2 denial of the fabricated quotation.'],
                    ['SunStar', 'Rep. Suan denies shoot-to-kill quote card vs Bato dela Rosa', 'https://www.sunstar.com.ph/amp/story/davao/rep-suan-denies-shoot-to-kill-quote-card-vs-bato-dela-rosa', 'Describes the quote card and reports Suan\'s denial; the same underlying denial, not independent proof.'],
                    ['ABS-CBN News', 'Rep. Suan denies online claims of shoot-to-kill resolution for Bato dela Rosa', 'https://www.abs-cbn.com/news/nation/2026/11/2/rep-suan-denies-online-claims-of-shoot-to-kill-resolution-for-bato-dela-rosa-1311', 'Related coverage of the denial.'],
                    ['Daily Tribune', 'CdO solon denies threatening Bato', 'https://tribune.net.ph/2026/11/02/cdo-solon-denies-threatening-bato', 'Supporting coverage of the same denial, not a separate underlying confirmation.'],
                    ['GMA News', 'PNP: No shoot-to-kill order vs Bato dela Rosa', 'https://www.gmanetwork.com/news/topstories/nation/1008107/pnp-no-shoot-to-kill-order-vs-bato-dela-rosa/story/', 'Related police clarification; distinguishes an alleged order from the fabricated Suan quotation.'],
                ]],
            ['castro-edited-drug-remarks', 'FALSE', 'Claire Castro accused Marcos of drug use.',
                'The circulating clip removes references to Rodrigo Duterte from Claire Castro’s remarks about marijuana and fentanyl. Philstar traced the footage to remarks in which she was discussing Duterte, not Marcos. The accompanying claim that she had left her position is contradicted by October 23 reporting that her courtesy resignation would not be accepted. This finding concerns the specific edited clip reviewed in October 2026.',
                'The edit changes the apparent subject of Castro’s remarks and adds an inaccurate departure narrative.',
                ['Claire Castro accused Marcos of drug use.', 'Claire Castro said Marcos uses marijuana and fentanyl.', 'Sinabi ni Claire Castro na gumagamit ng marijuana at fentanyl si Marcos.'],
                [
                    ['Philstar', 'Fact check: Edited clip turns Castro’s Duterte remarks into attack on Marcos', $philstar.'10/28/2566115/fact-check-edited-clip-turns-castros-duterte-remarks-attack-marcos', 'Direct review of the edited clip.'],
                    ['PCO', 'Press briefing with Ambassador Josel Francisco Ignacio', 'https://pco.gov.ph/press-briefing/press-briefing-of-pco-undersecretary-and-palace-press-officer-claire-castro-with-philippine-ambassador-to-the-republic-of-india-josel-francisco-ignacio/', 'Primary transcript identifying Duterte as the subject.'],
                    ['Philstar', 'Claire Castro to retain post', $philstar.'10/23/2565221/claire-castro-retain-post-all-pco-execs-file-courtesy-resignations', 'Reporting on the courtesy resignation.'],
                    ['AFP via Yahoo', 'Posts claiming Philippine presidential spokesperson berated own boss share edited clip', 'https://malaysia.news.yahoo.com/posts-claiming-philippine-presidential-spokesperson-090608392.html', 'Separate review of the spliced remarks.'],
                ]],
            ['hev-abi-fake-bucket-quote', 'FALSE', 'Hev Abi cursed at Marcos over his bucket advice.',
                'The vulgar statement in the circulating graphic was falsely attributed to Hev Abi. Philstar documented an October 19 denial from his record label, Urban Department PH, which identified the graphic as fake. The denial was also shared through the rapper’s official Facebook page. The controversy over collecting rainwater does not authenticate the quote. This finding applies to the specific graphic reviewed in October 2026.',
                'The artist’s record label rejected the graphic; the statement was not authenticated.',
                ['Hev Abi cursed at Marcos over his bucket advice.', 'Hev Abi insulted Marcos over collecting rainwater.', 'Minura ni Hev Abi si Marcos dahil sa payo nitong bumili ng timba.'],
                [
                    ['Philstar', 'Fact check: Hev Abi didn’t curse at Marcos over bucket advice', $philstar.'10/21/2564798/fact-check-hev-abi-didnt-curse-marcos-over-bucket-advice', 'Documents the record label’s denial.'],
                    ['Philstar', 'Palace: Marcos’ buy buckets advice part of El Niño preparations', $philstar.'10/13/2562945/palace-marcos-buy-buckets-advice-part-serious-el-nio-preparations', 'Background only; not independent evidence about Hev Abi’s statement.'],
                ]],
            ['marcos-edited-2028-election-video', 'FALSE', 'Marcos announced plans to cancel the 2028 elections.',
                'The circulating videos use edited footage and captions to present Marcos as announcing an intention to stop the 2028 presidential elections. Philstar traced the footage to his October 11 clarification of an earlier remark. In the fuller exchange, he was referring to allegations involving constitutional changes and elections. That exchange does not establish the announcement claimed by the overlays. This assessment concerns that specific October 2026 footage.',
                'The video presents a response to an allegation as an announcement of an election-cancellation plan.',
                ['Marcos announced plans to cancel the 2028 elections.', 'BBM babaguhin ang Constitution para walang election sa 2028.', 'BBM announced there will be no presidential election in 2028.'],
                [
                    ['Philstar', 'Fact check: Marcos video taken out of context to claim 2028 elections will be scrapped', $philstar.'10/13/2562913/fact-check-marcos-video-taken-out-context-claim-2028-elections-will-be-scrapped', 'Direct review of the circulating footage.'],
                    ['Philstar', 'Marcos clarifies buwaon remark', $philstar.'10/12/2562665/watch-marcos-clarifies-buwaon-remark-says-it-was-not-directed-vp-sara', 'Fuller reporting on the exchange.'],
                ]],
            ['fire-trikes-ten-million', 'FALSE', 'The fire tricycles in viral posts cost 10 million pesos each.',
                'The October 2026 posts connect locally acquired three-wheeled firefighting vehicles with a separate announcement about purchasing full-sized fire trucks. The reviewed reporting identifies the pictured tricycles as local-government acquisitions unveiled before that announcement. Remulla’s 10-million-peso figure concerned a proposed purchase of 1,500 fire trucks from South Korea. It does not establish the prices of those fire tricycles, whose actual costs should not be guessed from the announcement.',
                'The posts attach the price of a different planned vehicle purchase to existing local fire tricycles.',
                ['The fire tricycles in viral posts cost 10 million pesos each.', 'The viral fire trikes cost P10 million each.', 'Ang mga viral fire trike ay nagkakahalaga ng 10 million pesos bawat isa.'],
                [
                    ['Philstar', 'Fact check: Viral fire trikes falsely tagged at P10 million each', $philstar.'10/06/2561335/fact-check-viral-fire-trikes-falsely-tagged-p10-million-each', 'Identifies the different vehicles and purchases.'],
                    ['Radyo Natin', 'DILG chief debunks P10M fire trike claim', 'https://www.radyonatin.com.ph/post/dilg-chief-debunks-p10m-fire-trike-claim-clarifies-planned-bfp-truck-purchase', 'Supporting report crediting Philstar; not an independent confirmation.'],
                    ['Daily Tribune', 'DILG eyes P9-B savings in 1,500 fire truck deal', 'https://tribune.net.ph/2026/10/08/dilg-eyes-p9-b-savings-in-1500-fire-truck-deal', 'Procurement background.'],
                    ['SMNI', 'DILG: Viral mini-fire trucks, hindi mula sa national govt', 'https://smninewschannel.com/dilg-viral-mini-fire-trucks-hindi-mula-sa-national-govt/', 'Reports the DILG clarification.'],
                    ['Bombo Radyo Davao', 'DILG ug BFP gipaklaro ang viral nga fire trike', 'https://davao.bomboradyo.com/dilg-ug-bfp-gipaklaro-ang-viral-nga-fire-trike-nga-gikonektar-sa-%E2%82%B110-million-fire-truck/', 'Supporting explanation of the vehicle mix-up.'],
                ]],
            ['trump-visit-sara-presidency-november-2026', 'MISLEADING', 'Trump’s Philippine visit means Sara Duterte will become president in November 2026.',
                'As of November 4, 2026, the post combines an announced visit with an unsupported conclusion about presidential succession. The PCO confirmed Trump’s planned attendance at the ASEAN Summit in Manila. That announcement does not establish that Sara Duterte will become president or confirm the alleged courtesy call. Article VII, Section 8 of the Constitution specifies the circumstances for succession; a foreign leader’s visit does not itself trigger succession. Later developments require a fresh review.',
                'A real visit announcement is used to imply a presidential transition that the cited evidence does not establish.',
                ['Trump’s Philippine visit means Sara Duterte will become president in November 2026.', 'Trump will visit VP Sara because she will be president in November 2026.', 'Bibisita si Trump kay Sara dahil siya na ang presidente sa November 2026.'],
                [
                    ['Explained PH', 'Misleading: Trump’s PH visit and Sara presidency claim', 'https://explained.ph/misleading-trumps-ph-visit-to-include-courtesy-call-to-vp-sara-who-will-assume-presidency-next-month/', 'Direct review of the combined claim.'],
                    ['PCO', 'Palace confirms Trump’s attendance at ASEAN Summit in Manila', 'https://pco.gov.ph/news_releases/palace-confirms-trumps-attendance-at-asean-summit-in-manila/', 'Primary attendance announcement.'],
                    ['Supreme Court E-Library', 'Constitution: Article VII', 'https://elibrary.judiciary.gov.ph/thebookshelf/showdocs/45/25550', 'Section 8 specifies presidential succession.'],
                    ['SBS', 'Trump to attend ASEAN Summit in the Philippines', 'https://news.sbs.co.kr/english/article.do?news_id=N1008797861', 'Corroborates the visit announcement only.'],
                    ['PCO', 'October 14, 2026 press briefing', 'https://pco.gov.ph/press-briefing/20261014-malacanang-press-briefing-of-palace-press-officer-and-pco-undersecretary-claire-castro-with-department-of-social-welfare-and-development-dswd-secretary-rex-gatchalian/', 'Related primary document from the same briefing.'],
                ]],
        ];

        DB::transaction(function () use ($entries): void {
            foreach ($entries as [$slug, $verdict, $claim, $explanation, $summary, $variations, $documents]) {
                $sources = [];
                foreach ($documents as $index => [$name, $title, $url, $purpose]) {
                    $sources[] = ['id' => $slug.'-'.($index + 1), 'name' => $name, 'title' => $title,
                        'url' => $url, 'type' => 'reviewed_document', 'used_for' => $purpose];
                }
                $result = ReportAnswer::complete([
                    'schema_version' => 4, 'primary_task' => 'claim', 'claim' => $claim,
                    'verdict' => $verdict, 'summary' => $explanation, 'sources' => $sources,
                    'answer_segments' => [['text' => $explanation, 'source_ids' => array_column($sources, 'id')]],
                    'generated_wording' => ['version' => ResultWording::VERSION, 'verdict' => $verdict,
                        'explanation' => $explanation, 'result_summary' => $summary,
                        'recommended_action' => 'Read the linked original material before sharing this claim.',
                        'source_ids' => array_column($sources, 'id')],
                ]);
                $this->store($slug, $claim, $result, $variations, $slug === 'trump-visit-sara-presidency-november-2026' ? '2026-11-05 00:00:00' : '2026-12-01 00:00:00');
            }
            $claim = 'The world will end on Friday, November 13, 2026, according to a scientist mathematical prediction.';
            $this->store('november-13-2026-doomsday', $claim, KnownFactCheckCatalog::match($claim), [
                $claim, 'The world will end on November 13, 2026.',
                'Matatapos ang mundo sa November 13, 2026.',
                'Scientist predicts doomsday on November 13, 2026.',
                'BIG BREAKING: World will end in Two weeks. World will end on Friday 13 November 2026: According to an old study published in journal Science.',
            ], null);
        });
    }

    private function store(string $slug, string $text, array $result, array $variations, ?string $expiry): void
    {
        $claim = ReviewedClaim::updateOrCreate(['slug' => $slug], ['claim' => $text,
            'result' => $result, 'active' => true, 'reviewed_at' => '2026-11-04 00:00:00', 'expires_at' => $expiry]);
        foreach (array_unique([$text, $result['claim'] ?? $text, ...$variations]) as $variation) {
            $claim->variations()->updateOrCreate(['text' => $variation], ['text' => $variation]);
        }
    }
}
