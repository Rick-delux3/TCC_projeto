@extends('layout-inicial.simulation')

@section('inline-feedback', true)

@section('content')
    @include('simulation.partials.form-shell', [
        'profile' => 'unregistered',
        'formAction' => $formAction ?? route('simulation.unregistered-company.store'),
    ])
@endsection
