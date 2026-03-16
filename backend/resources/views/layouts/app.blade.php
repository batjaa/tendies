<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Tendies')</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🍗</text></svg>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,400;12..96,600;12..96,700;12..96,800&family=Outfit:wght@300;400;500;600&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css'])
</head>
<body class="bg-surface text-content leading-relaxed antialiased overflow-x-hidden">

<div class="dot-grid"></div>

{{-- Nav --}}
<nav class="sticky top-0 z-50 py-4 backdrop-blur-xl bg-surface/80 border-b border-edge-subtle">
    <div class="max-w-2xl mx-auto px-6 flex items-center justify-between">
        <a href="/" class="font-display font-bold text-base text-content no-underline flex items-center gap-1.5">
            <span class="text-lg">🍗</span> tendies
        </a>
        <div class="flex items-center gap-4">
            <span class="text-[0.82rem] text-content-muted">{{ Auth::user()->email }}</span>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="text-[0.82rem] text-content-dim hover:text-content transition-colors cursor-pointer bg-transparent border-0">Log Out</button>
            </form>
        </div>
    </div>
</nav>

<div class="relative z-[1] max-w-2xl mx-auto px-6 py-10">
    @if(session('success'))
        <div class="mb-6 px-4 py-3 bg-gain/10 border border-gain/20 rounded-[10px] text-[0.85rem] text-gain">
            {{ session('success') }}
        </div>
    @endif

    @yield('content')
</div>

</body>
</html>
