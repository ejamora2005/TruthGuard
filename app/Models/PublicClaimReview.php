<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PublicClaimReview extends Model
{
    protected $fillable = [
        'feed_item_id',
        'publisher',
        'headline',
        'claim',
        'claimant',
        'rating',
        'tone',
        'source_domain',
        'url',
        'image_url',
        'logo_url',
        'query',
        'feed_source_type',
        'publisher_filter',
        'source_payload',
        'published_at',
        'first_seen_at',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'source_payload' => 'array',
            'published_at' => 'datetime',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toFeedItem(): array
    {
        $domain = $this->source_domain ?: ($this->url ? parse_url($this->url, PHP_URL_HOST) : null);
        $publishedAt = $this->published_at;

        return [
            'id' => $this->feed_item_id,
            'publisher' => $this->publisher ?: 'Fact-check partner',
            'headline' => $this->headline ?: 'Fact-check result',
            'claim' => $this->claim ?: 'Public claim reviewed by a fact-checking partner.',
            'claimant' => $this->claimant ?: 'Online claim',
            'rating' => $this->rating ?: 'Reviewed',
            'tone' => $this->tone ?: 'neutral',
            'url' => $this->url,
            'host' => $domain,
            'source_domain' => $domain,
            'logo_url' => $this->logo_url ?: ($domain ? $this->logoUrl((string) $domain) : null),
            'image_url' => $this->image_url,
            'query' => $this->query,
            'feed_source_type' => $this->feed_source_type ?: 'saved',
            'publisher_filter' => $this->publisher_filter,
            'date_label' => $publishedAt?->format('M d, Y') ?? 'Date unavailable',
            'timestamp' => $publishedAt?->timestamp ?? 0,
            'saved' => true,
        ];
    }

    private function logoUrl(string $domain): string
    {
        $domain = preg_replace('/^www\./', '', Str::lower($domain)) ?: $domain;

        return 'https://www.google.com/s2/favicons?domain='.urlencode($domain).'&sz=64';
    }
}
