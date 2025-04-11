@extends('layouts.email')

@section('content')
<p>Estimado usuario,</p>

<p>Le damos la bienvenida a Delicius Food. A continuación, encontrará sus credenciales de acceso al sistema:</p>

<div class="credentials">
    @if($email)
    <p><strong>Email:</strong> {{ $email }}</p>
    @endif
    
    @if($nickname)
    <p><strong>Nickname:</strong> {{ $nickname }}</p>
    @endif
    
    @if($password)
    <p><strong>Contraseña:</strong> {{ $password }}</p>
    @endif
</div>

<!-- to do -->
<!-- <p>Por motivos de seguridad, le recomendamos cambiar su contraseña después del primer inicio de sesión.</p> -->

<p>Si tiene alguna pregunta o necesita ayuda, no dude en contactarnos.</p>

<p>Saludos cordiales,<br>
El equipo de Delicius Food</p>
@endsection