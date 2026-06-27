<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    <style>body { margin: 0; }</style>
</head>
<body>
    {{-- Scalar API Reference renders the live OpenAPI document and provides a
         built-in request tester. Paste a bearer token in the UI to call. The
         CDN script is overridable via config('ai-gateway.api.docs.script_src'). --}}
    <script id="api-reference" data-url="{{ $specUrl }}" data-configuration='{!! $configuration !!}'></script>
    <script src="{{ $scriptSrc }}"></script>
</body>
</html>
