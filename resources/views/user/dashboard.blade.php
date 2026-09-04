@extends('layouts.user')

@section('title', 'Dashboard')
@section('page_title', 'Dashboard')

@section('content')
    <div class="truthguard-mobile-page truthguard-mobile-dashboard py-1 md:py-2">
        <div id="fact-check-feed-shell">
            @include('user.dashboard.partials.news-watch')
        </div>
    </div>
@endsection
