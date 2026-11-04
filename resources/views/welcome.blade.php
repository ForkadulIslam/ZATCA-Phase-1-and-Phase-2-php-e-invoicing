<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-slate-50">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>ZATCA Phase 2 - Smart Invoicing</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-repeat: no-repeat;
            background-attachment: fixed;
            background-image: radial-gradient(at 0% 0%, hsla(217,100%,92%,1) 0, transparent 50%),
                              radial-gradient(at 50% 0%, hsla(186,100%,92%,1) 0, transparent 50%),
                              radial-gradient(at 100% 0%, hsla(217,100%,92%,1) 0, transparent 50%);
        }
    </style>
</head>

<body class="h-full antialiased text-slate-900 overflow-x-hidden">
    <div id="root"></div>
</body>

</html>