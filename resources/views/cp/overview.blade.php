<bunny-overview
    title="{{ $title }}"
    access="{{ $bunny['apiKey'] }}"
    library="{{ $bunny['library'] }}"
    hostname="{{ $bunny['hostname'] }}"
></bunny-overview>

<ui-docs-callout :topic="'{{ $addon['name'] }}'" url="{{ $addon['url'] }}" />
