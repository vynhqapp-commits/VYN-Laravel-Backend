<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ config('app.name') }} - Swagger UI</title>
    <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5/swagger-ui.css">
    <style>
        html, body { margin: 0; padding: 0; }
        #swagger-ui { min-height: 100vh; }
    </style>
</head>
<body>
<div id="swagger-ui"></div>
<script src="https://unpkg.com/swagger-ui-dist@5/swagger-ui-bundle.js"></script>
<script>
    window.onload = function () {
        window.ui = SwaggerUIBundle({
            url: "/swagger/openapi.yaml",
            dom_id: '#swagger-ui',
            deepLinking: true,
            tryItOutEnabled: true,
            persistAuthorization: true,
        });
    };
</script>
</body>
</html>
