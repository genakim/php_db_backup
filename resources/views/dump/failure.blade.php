@extends('layouts.app')

@section('content')
    <div class="justify-content-center d-flex">
        {{ $message }}
    </div>
    <div class="justify-content-center d-flex">
        {{ $error }}
    </div>
@endsection
