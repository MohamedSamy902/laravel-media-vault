<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Advanced File Upload</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="stylesheet" href="{{ asset('vendor/media-vault/media-vault.css') }}">
</head>
<body>
    <h2>Advanced File Upload</h2>
    <input type="file" id="afu-fileInput">
    <button class="afu-upload-btn" onclick="afuUploadFile()">Upload</button>
    <div class="afu-progress-bar">
        <div class="afu-progress-bar-inner" id="afu-progress-bar-inner"></div>
    </div>
    <div id="afu-status"></div>
    <script src="{{ asset('vendor/media-vault/media-vault.js') }}"></script>
</body>
</html>