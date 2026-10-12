<?php

namespace Noo\BunnyStream\Data;

use Illuminate\Support\Str;
use Noo\BunnyStream\Assets\Streams;
use Statamic\Contracts\Assets\Asset;
use Statamic\Fields\ArrayableString;
use Statamic\Fields\Value;

class BunnyVideo extends ArrayableString
{
    public function __construct(
        protected string $guid,
        protected string $hostname,
        protected string|int $libraryId,
        protected ?string $tokenKey = null,
        protected int $tokenExpiry = 24,
        protected string $thumbnailFilename = 'thumbnail.jpg',
    ) {
        parent::__construct($this->url());
    }

    public static function make(string $guid, string|int|null $libraryId = null, string $thumbnailFilename = 'thumbnail.jpg'): self
    {
        return new self(
            guid: $guid,
            hostname: (string) config('statamic.bunny-stream.hostname'),
            libraryId: $libraryId ?? config('statamic.bunny-stream.library_id'),
            tokenKey: config('statamic.bunny-stream.token_key'),
            tokenExpiry: (int) config('statamic.bunny-stream.token_expiry', 24),
            thumbnailFilename: $thumbnailFilename,
        );
    }

    /**
     * Resolve a legacy Bunny field value (GUID or BunnyVideo) or a migrated asset to its stream.
     * Templates using this work before and after `bunny-stream:migrate-fields`.
     */
    public static function from(mixed $value): ?self
    {
        if ($value instanceof Value) {
            $value = $value->value();
        }

        return match (true) {
            $value instanceof self => $value,
            $value instanceof Asset => Streams::video($value->get('bunny_stream')),
            is_string($value) && Str::isUuid($value) => self::make($value),
            default => null,
        };
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
        return "https://{$this->hostname}/{$this->guid}/".rawurlencode(basename($this->thumbnailFilename));
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
