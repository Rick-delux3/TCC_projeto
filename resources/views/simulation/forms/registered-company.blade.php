@extends('layout-inicial.simulation')

@section('inline-feedback', true)

@section('content')
    @include('simulation.partials.form-shell', [
        'profile' => 'registered',
        'formAction' => $formAction ?? route('simulation.registered-company.store'),
    ])
@endsection
