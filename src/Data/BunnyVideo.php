<?php

namespace Noo\BunnyStream\Data;

use Statamic\Fields\ArrayableString;

class BunnyVideo extends ArrayableString
{
    public function __construct(
        protected string $guid,
        protected string $hostname,
        protected string|int $libraryId,
        protected ?string $tokenKey = null,
        protected int $tokenExpiry = 24,
    ) {
        parent::__construct($this->url());
    }

    public function __toString(): string
    {
        return $this->url();
    }

    public function url(): string
    {
        return "https://{$this->hostname}/{$this->guid}/playlist.m3u8";
    }

    public function embedUrl(array $params = []): string
    {
        $defaults = [
            'autoplay' => 'false',
            'preload' => 'true',
            'responsive' => 'true',
        ];

        $query = array_merge($defaults, $params);

        if ($this->tokenKey) {
            $expiration = time() + ($this->tokenExpiry * 3600);
            $query['token'] = hash('sha256', $this->tokenKey.$this->guid.$expiration);
            $query['expires'] = $expiration;
        }

        return "https://player.mediadelivery.net/embed/{$this->libraryId}/{$this->guid}?".http_build_query($query);
    }

    public function embed(array $params = []): string
    {
        $src = $this->embedUrl($params);

        return '<div style="position:relative;padding-top:56.25%;"><iframe src="'.e($src).'" loading="lazy" style="border:0;position:absolute;top:0;height:100%;width:100%;" allow="accelerometer;gyroscope;autoplay;encrypted-media;picture-in-picture;" allowfullscreen></iframe></div>';
    }

    public function thumbnail(): string
    {
        return "https://{$this->hostname}/{$this->guid}/thumbnail.jpg";
    }

    public function guid(): string
    {
        return $this->guid;
    }

    public function toArray(): array
    {
        return [
            'url' => $this->url(),
            'embed_url' => $this->embedUrl(),
            'embed' => $this->embed(),
            'thumbnail' => $this->thumbnail(),
            'guid' => $this->guid,
        ];
    }

    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}
