<?php
// swagger.php - Swagger UI for License Server API
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>License Server API - Swagger UI</title>
    <link rel="icon" type="image/x-icon" href="/css/swagger.ico">
    <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@4.15.5/swagger-ui.css" />
    <style>
        body {
            margin: 0;
            padding: 0;
            background: #fafafa;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        .header {
            background: #2b3b4d;
            color: white;
            padding: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .header h1 {
            margin: 0;
            font-size: 24px;
        }
        .header p {
            margin: 5px 0 0 0;
            opacity: 0.8;
        }
        .back-link {
            position: absolute;
            top: 20px;
            right: 20px;
            color: white;
            text-decoration: none;
        }
        .back-link:hover {
            text-decoration: underline;
        }
        #swagger-ui {
            padding: 20px;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>License Server API</h1>
        <p>API Documentation and Testing Interface</p>
        <a href="/" class="back-link">
            <i class="arrow left icon"></i>
            Back to Admin Panel
        </a>
    </div>
    <div id="swagger-ui"></div>

    <script src="https://unpkg.com/swagger-ui-dist@4.15.5/swagger-ui-bundle.js"></script>
    <script src="https://unpkg.com/swagger-ui-dist@4.15.5/swagger-ui-standalone-preset.js"></script>
    <script>
    window.onload = function() {
        const ui = SwaggerUIBundle({
            url: '/swagger-spec.php',
            dom_id: '#swagger-ui',
            deepLinking: true,
            presets: [
                SwaggerUIBundle.presets.apis,
                SwaggerUIStandalonePreset
            ],
            plugins: [
                SwaggerUIBundle.plugins.DownloadUrl
            ],
            layout: "StandaloneLayout"
        });
    };
    </script>
</body>
</html>