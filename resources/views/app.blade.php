{{-- Author: ramanpal singh | URL: https://kwebby.com --}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, interactive-widget=resizes-content">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="robots" content="noindex,nofollow">
@viteReactRefresh
@vite(['resources/css/app.css','resources/js/app.tsx'])
@inertiaHead
</head>
<body>@inertia</body>
</html>
