<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <title>{{ $title ?? 'Delicius Food' }}</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            padding: 20px;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            border: 1px solid #ddd;
            border-radius: 5px;
            padding: 20px;
        }
        .header {
            text-align: center;
            margin-bottom: 20px;
        }
        .logo-container {
            text-align: center;
            margin-bottom: 20px;
        }
        .logo {
            max-width: 200px;
            height: auto;
        }
        .content {
            margin-bottom: 20px;
        }
        .credentials {
            background-color: #f9f9f9;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .footer {
            text-align: center;
            font-size: 12px;
            color: #777;
            margin-top: 30px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="logo-container">
            <img src="{{ config('app.LOGO_URL') }}" alt="Delicius Food Logo" class="logo">
        </div>
        
        <div class="header">
            <h1>{{ $header ?? 'Delicius Food' }}</h1>
        </div>
        
        <div class="content">
            @yield('content')
        </div>
        
        <div class="footer">
            <p>Este es un correo electrónico automático, por favor no responda a este mensaje.</p>
        </div>
    </div>
</body>
</html>