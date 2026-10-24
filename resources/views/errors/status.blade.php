<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ $status }} — WojteX Forum</title>
    <style>
        *{box-sizing:border-box}body{margin:0;background:#0c101c;color:#eef0fa;font:16px/1.6 system-ui,sans-serif;min-height:100vh;display:grid;place-items:center;padding:24px}main{max-width:540px;width:100%;padding:36px;border:1px solid #30364e;border-radius:20px;background:#151a2a}strong{color:#ae96ff;font-size:14px;letter-spacing:.14em}h1{font-size:54px;margin:8px 0}p{color:#bbc4d9}a{color:#bdadff;display:inline-block;padding:12px 0}a:focus-visible{outline:2px solid #bdadff;outline-offset:4px}
    </style>
</head>
<body><main><strong>VEYRA FORUM</strong><h1>{{ $status }}</h1><p>{{ $message }}</p><a href="/">{{ __('ui.page.back_to_forum') }} →</a></main></body>
</html>
