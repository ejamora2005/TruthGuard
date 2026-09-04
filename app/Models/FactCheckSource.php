<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class FactCheckSource extends Model
{
    use HasFactory;

    protected $fillable = [
        'created_by_user_id',
        'key',
        'name',
        'domain',
        'category',
        'scraper_key',
        'url_template',
        'ready_selectors',
        'article_selectors',
        'exclude_selectors',
        'is_enabled',
        'notes',
    ];

    protected $casts = [
        'ready_selectors' => 'array',
        'article_selectors' => 'array',
        'exclude_selectors' => 'array',
        'is_enabled' => 'boolean',
    ];

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('is_enabled', true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toVerificationSourceConfig(): array
    {
        $extraSelectors = [
            'siteLabel' => $this->name,
        ];

        if ($this->article_selectors !== null && $this->article_selectors !== []) {
            $extraSelectors['articleContainers'] = $this->article_selectors;
        }

        if ($this->exclude_selectors !== null && $this->exclude_selectors !== []) {
            $extraSelectors['excludeSelectors'] = $this->exclude_selectors;
        }

        return [
            'key' => $this->key,
            'name' => $this->name,
            'category' => $this->category ?: 'fact_check',
            'source_key' => $this->scraper_key ?: 'article-search',
            'url_template' => $this->url_template,
            'ready_selectors' => $this->ready_selectors ?? [],
            'extra_selectors' => $extraSelectors,
        ];
    }

    public function searchUrl(string $query): string
    {
        return str_replace('{query}', rawurlencode($query), $this->url_template);
    }

    public function categoryLabel(): string
    {
        return match ($this->category) {
            'news' => 'Newsroom',
            'official' => 'Official',
            'reference' => 'Reference',
            default => 'Fact-check',
        };
    }

    public function displayDomain(): ?string
    {
        if (filled($this->domain)) {
            return (string) $this->domain;
        }

        return self::domainFromTemplate($this->url_template);
    }

    public static function domainFromTemplate(?string $urlTemplate): ?string
    {
        $template = trim((string) $urlTemplate);

        if ($template === '') {
            return null;
        }

        $url = str_replace('{query}', rawurlencode('sample claim'), $template);
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || trim($host) === '') {
            return null;
        }

        return Str::of($host)->lower()->replaceStart('www.', '')->toString();
    }
}
