@extends('layouts.app')

@section('content')
    <div class="justify-content-center d-flex">
        {{ $message }}
    </div>
    <div class="justify-content-center d-flex">
        <a class="btn btn-primary" href="{{ $dump_route }}">Начать</a>
    </div>
@endsection
