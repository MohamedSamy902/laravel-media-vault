<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Laravel Media Vault — Upload</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="stylesheet" href="{{ asset('vendor/media-vault/media-vault.css') }}">
</head>
<body>
    @php
        $uiPrefix = '/' . trim((string) config('media-vault.ui.route_prefix', 'media-vault'), '/');
    @endphp
    <h2>Laravel Media Vault</h2>
    <input type="file" id="afu-fileInput">
    <button class="afu-upload-btn" onclick="afuUploadFile()">Upload</button>
    <div class="afu-progress-bar">
        <div class="afu-progress-bar-inner" id="afu-progress-bar-inner"></div>
    </div>
    <div id="afu-status"></div>
    <script>
        window.MEDIA_VAULT_UI_PREFIX = @json($uiPrefix);
        window.MEDIA_VAULT_CHUNK_SIZE = @json((int) config('media-vault.chunking.default_chunk_size', 5242880));
    </script>
    <script src="{{ asset('vendor/media-vault/media-vault.js') }}"></script>
</body>
</html>