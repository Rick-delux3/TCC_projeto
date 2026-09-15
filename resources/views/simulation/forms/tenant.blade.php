@extends('layout-inicial.simulation')

@section('inline-feedback', true)

@section('content')
    @include('simulation.partials.form-shell', [
        'profile' => 'tenant',
        'formAction' => $formAction ?? route('simulation.tenant.store'),
    ])
@endsection
