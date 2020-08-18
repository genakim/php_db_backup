<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @isset($refresh)
        <meta http-equiv="refresh" content="{{ $refresh }}">
    @endisset

    <title>{{ config('app.name', 'Backup') }}</title>
    <script src="{{ asset('js/app.js') }}" defer></script>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">

</head>
<body>
<div id="app">
    <div class="flex-center position-ref full-height">
        <div class="content">
            @yield('content')
        </div>
    </div>
</div>
</body>
</html>

