<?php

namespace App\Livewire;

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.landing')]
#[Title('TruthGuard: AI-Powered Media Verification System')]
class WelcomePage extends Component
{
    public string $logoPath = '';

    public string $logoUrl = '';

    public array $features = [];

    public array $steps = [];

    public function mount(): void
    {
        $this->logoPath = (string) config('app.truthguard_logo', 'images/truthguard-logo.png');
        $this->logoUrl = $this->resolveLogoUrl($this->logoPath);

        $this->features = [
            [
                'title' => 'AI-Generated Media Detection',
                'description' => 'Checks if an image or video may be AI-generated or edited so users can avoid misleading content.',
                'icon' => 'shield-check',
            ],
            [
                'title' => 'Social Media Monitoring',
                'description' => 'Collects public posts from selected platforms so suspicious media can be reviewed in one place.',
                'icon' => 'social-stream',
            ],
            [
                'title' => 'Image & Video Analysis',
                'description' => 'Analyzes photos and videos using clear checks and indicators that are easy to understand.',
                'icon' => 'media-analysis',
            ],
            [
                'title' => 'Automated Workflow (n8n)',
                'description' => 'Automates the process from data collection to reporting, so results are faster and consistent.',
                'icon' => 'workflow-automation',
            ],
            [
                'title' => 'Credibility Scoring',
                'description' => 'Gives each media item a credibility score to help users quickly judge if it can be trusted.',
                'icon' => 'credibility-score',
            ],
            [
                'title' => 'Dashboard Reporting',
                'description' => 'Shows all findings in a simple dashboard with summaries, trends, and review-ready reports.',
                'icon' => 'dashboard-reporting',
            ],
        ];

        $this->steps = [
            [
                'title' => 'Collect Media (Web Scraping)',
                'description' => 'TruthGuard gathers public media posts from selected social platforms.',
                'icon' => 'collect-media',
            ],
            [
                'title' => 'Process via Automation (n8n)',
                'description' => 'The system automatically prepares and routes the files for checking.',
                'icon' => 'pipeline-automation',
            ],
            [
                'title' => 'Analyze with AI Detection Models',
                'description' => 'AI models analyze each file and generate authenticity indicators and scores.',
                'icon' => 'ai-analysis',
            ],
            [
                'title' => 'Display Results on Dashboard',
                'description' => 'Results are shown in a clear dashboard so users can review and act quickly.',
                'icon' => 'dashboard-display',
            ],
        ];
    }

    protected function resolveLogoUrl(string $relativePath): string
    {
        $normalizedPath = ltrim(trim($relativePath), '/');

        if ($normalizedPath === '' || ! is_file(public_path($normalizedPath))) {
            return '';
        }

        return asset($normalizedPath);
    }

    public function icon(string $icon): string
    {
        return match ($icon) {
            'shield-check' => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 3 19 6v5c0 5-3.5 9-7 10-3.5-1-7-5-7-10V6l7-3Z" /><path stroke-linecap="round" stroke-linejoin="round" d="m9.5 12 1.8 1.8 3.2-3.2" />',
            'social-stream' => '<circle cx="12" cy="12" r="8" /><path stroke-linecap="round" stroke-linejoin="round" d="M8 9h8M8 12h8M8 15h5M17 6.5c1.4 1.3 2.2 3 2.3 5" />',
            'media-analysis' => '<rect x="3.5" y="5" width="17" height="14" rx="2" /><path stroke-linecap="round" stroke-linejoin="round" d="m6.5 15 3-3 2.5 2.5 2-2 3 2.5M9.5 9h.01" /><path stroke-linecap="round" stroke-linejoin="round" d="m13 8.5 4 2.5-4 2.5Z" />',
            'workflow-automation' => '<circle cx="6" cy="6" r="2" /><circle cx="18" cy="6" r="2" /><circle cx="6" cy="18" r="2" /><circle cx="18" cy="18" r="2" /><path stroke-linecap="round" stroke-linejoin="round" d="M8 6h8M6 8v8M18 8v8M8 18h8" />',
            'credibility-score' => '<path stroke-linecap="round" stroke-linejoin="round" d="M5 18V10m4 8V7m4 11v-5m4 5V9" /><path stroke-linecap="round" stroke-linejoin="round" d="m8.5 5.5 2.2 2.2 4-4" />',
            'dashboard-reporting' => '<rect x="3.5" y="4.5" width="17" height="15" rx="2" /><path stroke-linecap="round" stroke-linejoin="round" d="M7 15.5h2.5M11 13h2.5M15 10.5h2.5M7 11l2.5-2 2.3 1.6 4-3.1" />',
            'collect-media' => '<path stroke-linecap="round" stroke-linejoin="round" d="M7 17a4 4 0 0 1 .6-7.9A5.2 5.2 0 0 1 17.6 8 3.5 3.5 0 0 1 17 17H7Z" /><path stroke-linecap="round" stroke-linejoin="round" d="m12 9.5v6m0 0 2.2-2.2M12 15.5l-2.2-2.2" />',
            'pipeline-automation' => '<path stroke-linecap="round" stroke-linejoin="round" d="M5 7h5m4 0h5M5 12h4m6 0h4M5 17h5m4 0h5" /><path stroke-linecap="round" stroke-linejoin="round" d="m10 7 2 5-2 5m4-10-2 5 2 5" />',
            'ai-analysis' => '<rect x="6" y="6" width="12" height="12" rx="2" /><path stroke-linecap="round" stroke-linejoin="round" d="M9 3.5v2.5M15 3.5v2.5M9 18v2.5M15 18v2.5M3.5 9H6M3.5 15H6M18 9h2.5M18 15h2.5M10 10h4v4h-4z" />',
            'dashboard-display' => '<rect x="3.5" y="5" width="17" height="12" rx="2" /><path stroke-linecap="round" stroke-linejoin="round" d="M7 13.5h2M11 11.5h2M15 9.5h2M9 19h6" />',
            default => '<circle cx="12" cy="12" r="9" />',
        };
    }

    public function render()
    {
        return view('livewire.welcome-page');
    }
}
