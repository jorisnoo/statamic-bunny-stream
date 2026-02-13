@extends('statamic::layout')

@section('title', $title)

@section('content')
    <bunny-overview
        title="{{ $title }}"
        access="{{ $bunny['apiKey'] }}"
        library="{{ $bunny['library'] }}"
        hostname="{{ $bunny['hostname'] }}"
    ></bunny-overview>

    @include('statamic::partials.docs-callout', [
        'topic' => $addon['name'],
        'url' => $addon['url'],
    ])
@endsection
