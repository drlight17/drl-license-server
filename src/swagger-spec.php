<?php
header('Content-Type: application/json');

// Load environment variables
function loadEnv($path = '.env') {
    if (!file_exists($path)) {
        return [];
    }
    
    $env = [];
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    
    foreach ($lines as $line) {
        if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
            list($key, $value) = explode('=', $line, 2);
            $env[trim($key)] = trim($value);
        }
    }
    
    return $env;
}

// Load environment variables
$env = loadEnv(__DIR__ . '/.env');

// Get environment variables with defaults
$serverUrl = getenv('SWAGGER_SERVER_URL') ?: $env['SWAGGER_SERVER_URL'] ?: 'http://localhost:8080';
$serverDescription = getenv('SWAGGER_SERVER_DESCRIPTION') ?: $env['SWAGGER_SERVER_DESCRIPTION'] ?: 'Local development server';
$contactName = getenv('SWAGGER_CONTACT_NAME') ?: $env['SWAGGER_CONTACT_NAME'] ?: 'API Support';
$contactEmail = getenv('SWAGGER_CONTACT_EMAIL') ?: $env['SWAGGER_CONTACT_EMAIL'] ?: 'support@example.com';
$apiTitle = getenv('SWAGGER_API_TITLE') ?: $env['SWAGGER_API_TITLE'] ?: 'License Server API';
$apiDescription = getenv('SWAGGER_API_DESCRIPTION') ?: $env['SWAGGER_API_DESCRIPTION'] ?: 'API for license validation, activation, and management for shareware applications';
$apiVersion = getenv('SWAGGER_API_VERSION') ?: $env['SWAGGER_API_VERSION'] ?: '1.0.0';

$swaggerSpec = [
    "openapi" => "3.0.0",
    "info" => [
        "title" => $apiTitle,
        "description" => $apiDescription,
        "version" => $apiVersion,
        "contact" => [
            "name" => $contactName,
            "email" => $contactEmail
        ]
    ],
    "servers" => [
        [
            "url" => $serverUrl,
            "description" => $serverDescription
        ]
    ],
    "paths" => [
        "/api" => [
            "get" => [
                "summary" => "Validate or list licenses (redirects to Swagger UI if no parameters)",
                "description" => "Validate a license key or perform administrative actions. If called without parameters, redirects to Swagger UI.",
                "parameters" => [
                    [
                        "name" => "key",
                        "in" => "query",
                        "description" => "License key to validate",
                        "required" => false,
                        "schema" => [
                            "type" => "string",
                            "example" => "ABCD-EFGH-IJKL-MNOP"
                        ]
                    ],
                    [
                        "name" => "action",
                        "in" => "query",
                        "description" => "Action to perform",
                        "required" => false,
                        "schema" => [
                            "type" => "string",
                            "enum" => ["validate", "list", "logs", "delete", "create", "activate"],
                            "default" => "validate"
                        ]
                    ],
                    [
                        "name" => "admin_key",
                        "in" => "query",
                        "description" => "Admin key for protected actions",
                        "required" => false,
                        "schema" => [
                            "type" => "string"
                        ]
                    ],
                    // Pagination parameters for list action
                    [
                        "name" => "page",
                        "in" => "query",
                        "description" => "Page number for pagination (for 'list' and 'logs' actions)",
                        "required" => false,
                        "schema" => [
                            "type" => "integer",
                            "minimum" => 1,
                            "default" => 1
                        ]
                    ],
                    [
                        "name" => "limit",
                        "in" => "query",
                        "description" => "Number of items per page (for 'list' and 'logs' actions)",
                        "required" => false,
                        "schema" => [
                            "type" => "integer",
                            "minimum" => 1,
                            "maximum" => 100,
                            "default" => 20
                        ]
                    ],
                    // Search and filter parameters for list action
                    [
                        "name" => "search",
                        "in" => "query",
                        "description" => "Search term for filtering licenses (for 'list' action)",
                        "required" => false,
                        "schema" => [
                            "type" => "string"
                        ]
                    ],
                    [
                        "name" => "status",
                        "in" => "query",
                        "description" => "Status filter for licenses (for 'list' action)",
                        "required" => false,
                        "schema" => [
                            "type" => "string",
                            "enum" => ["all", "active", "inactive", "expired"],
                            "default" => "all"
                        ]
                    ]
                ],
                "responses" => [
                    "200" => [
                        "description" => "Successful response or redirect to Swagger UI",
                        "content" => [
                            "application/json" => [
                                "schema" => [
                                    "oneOf" => [
                                        [
                                            "type" => "object",
                                            "properties" => [
                                                "valid" => ["type" => "boolean"],
                                                "product" => ["type" => "string"],
                                                "user" => ["type" => "string"],
                                                "expires" => ["type" => "string", "format" => "date-time"],
                                                "activated" => ["type" => "boolean"],
                                                "timestamp" => ["type" => "string", "format" => "date-time"],
                                                "success" => ["type" => "boolean"]
                                            ]
                                        ],
                                        // Response schema for list action with pagination
                                        [
                                            "type" => "object",
                                            "properties" => [
                                                "success" => ["type" => "boolean"],
                                                "count" => ["type" => "integer", "description" => "Number of items on current page"],
                                                "total" => ["type" => "integer", "description" => "Total number of items"],
                                                "page" => ["type" => "integer", "description" => "Current page number"],
                                                "pages" => ["type" => "integer", "description" => "Total number of pages"],
                                                "limit" => ["type" => "integer", "description" => "Items per page"],
                                                "licenses" => [
                                                    "type" => "object",
                                                    "additionalProperties" => [
                                                        "type" => "object",
                                                        "properties" => [
                                                            "user" => ["type" => "string"],
                                                            "product" => ["type" => "string"],
                                                            "created" => ["type" => "string", "format" => "date-time"],
                                                            "expires" => ["type" => "string", "format" => "date-time", "nullable" => true],
                                                            "activated" => ["type" => "boolean"]
                                                        ]
                                                    ]
                                                ],
                                                "timestamp" => ["type" => "string", "format" => "date-time"]
                                            ]
                                        ],
                                        // Response schema for logs action with pagination
                                        [
                                            "type" => "object",
                                            "properties" => [
                                                "success" => ["type" => "boolean"],
                                                "content" => [
                                                    "type" => "array",
                                                    "items" => [
                                                        "type" => "object",
                                                        "properties" => [
                                                            "timestamp" => ["type" => "string", "format" => "date-time"],
                                                            "action" => ["type" => "string"],
                                                            "ip" => ["type" => "string"],
                                                            "user_agent" => ["type" => "string"],
                                                            "details" => ["type" => "object"]
                                                        ]
                                                    ]
                                                ],
                                                "count" => ["type" => "integer", "description" => "Number of items on current page"],
                                                "total" => ["type" => "integer", "description" => "Total number of items"],
                                                "page" => ["type" => "integer", "description" => "Current page number"],
                                                "pages" => ["type" => "integer", "description" => "Total number of pages"],
                                                "limit" => ["type" => "integer", "description" => "Items per page"],
                                                "file_exists" => ["type" => "boolean"],
                                                "timestamp" => ["type" => "string", "format" => "date-time"]
                                            ]
                                        ]
                                    ]
                                ]
                            ],
                            "text/html" => [
                                "schema" => [
                                    "type" => "string"
                                ]
                            ]
                        ]
                    ]
                ]
            ],
            "post" => [
                "summary" => "Validate, create, activate, or delete licenses",
                "description" => "Perform various license operations via POST request",
                "requestBody" => [
                    "required" => false,
                    "content" => [
                        "application/json" => [
                            "schema" => [
                                "type" => "object",
                                "properties" => [
                                    "key" => [
                                        "type" => "string",
                                        "description" => "License key for validation/activation/deletion",
                                        "example" => "ABCD-EFGH-IJKL-MNOP"
                                    ],
                                    "action" => [
                                        "type" => "string",
                                        "enum" => ["validate", "activate", "create", "delete", "list", "logs"],
                                        "description" => "Action to perform",
                                        "default" => "validate"
                                    ],
                                    "admin_key" => [
                                        "type" => "string",
                                        "description" => "Admin key for protected actions (required for activate, delete, list, logs)"
                                    ],
                                    // Pagination parameters
                                    "page" => [
                                        "type" => "integer",
                                        "minimum" => 1,
                                        "default" => 1,
                                        "description" => "Page number for pagination (for 'list' and 'logs' actions)"
                                    ],
                                    "limit" => [
                                        "type" => "integer",
                                        "minimum" => 1,
                                        "maximum" => 100,
                                        "default" => 20,
                                        "description" => "Number of items per page (for 'list' and 'logs' actions)"
                                    ],
                                    // Search and filter parameters
                                    "search" => [
                                        "type" => "string",
                                        "description" => "Search term for filtering licenses (for 'list' action)"
                                    ],
                                    "status" => [
                                        "type" => "string",
                                        "enum" => ["all", "active", "inactive", "expired"],
                                        "default" => "all",
                                        "description" => "Status filter for licenses (for 'list' action)"
                                    ],
                                    "license_data" => [
                                        "type" => "object",
                                        "properties" => [
                                            "user" => [
                                                "type" => "string",
                                                "format" => "email",
                                                "description" => "User email address (required for create action)"
                                            ],
                                            "product" => [
                                                "type" => "string",
                                                "description" => "Product name"
                                            ],
                                            "days" => [
                                                "type" => "integer",
                                                "description" => "License duration in days (0 for no expiration)"
                                            ],
                                            "custom_key" => [
                                                "type" => "string",
                                                "description" => "Custom license key (optional)"
                                            ]
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ]
                ],
                "responses" => [
                    "200" => [
                        "description" => "Successful response"
                    ]
                ]
            ],
            "put" => [
                "summary" => "Create a new license",
                "description" => "Create a new license. If admin_key is provided, license is created and activated immediately. If no admin_key is provided, license is created but requires manual activation by administrator.",
                "requestBody" => [
                    "required" => true,
                    "content" => [
                        "application/json" => [
                            "schema" => [
                                "type" => "object",
                                "properties" => [
                                    "admin_key" => [
                                        "type" => "string",
                                        "description" => "Admin key for immediate license creation and activation (optional)"
                                    ],
                                    "license_data" => [
                                        "type" => "object",
                                        "required" => ["user"],
                                        "properties" => [
                                            "user" => [
                                                "type" => "string",
                                                "description" => "User email address",
                                                "format" => "email"
                                            ],
                                            "product" => [
                                                "type" => "string",
                                                "description" => "Product name"
                                            ],
                                            "days" => [
                                                "type" => "integer",
                                                "description" => "License duration in days (0 for no expiration)"
                                            ],
                                            "custom_key" => [
                                                "type" => "string",
                                                "description" => "Custom license key (optional)"
                                            ]
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ]
                ],
                "responses" => [
                    "200" => [
                        "description" => "License created successfully",
                        "content" => [
                            "application/json" => [
                                "schema" => [
                                    "type" => "object",
                                    "properties" => [
                                        "success" => ["type" => "boolean"],
                                        "created" => ["type" => "boolean"],
                                        "key" => ["type" => "string"],
                                        "license_info" => [
                                            "type" => "object",
                                            "properties" => [
                                                "user" => ["type" => "string", "format" => "email"],
                                                "product" => ["type" => "string"],
                                                "created" => ["type" => "string", "format" => "date-time"],
                                                "expires" => ["type" => "string", "format" => "date-time", "nullable" => true],
                                                "activated" => ["type" => "boolean"]
                                            ]
                                        ],
                                        "message" => ["type" => "string"],
                                        "timestamp" => ["type" => "string", "format" => "date-time"]
                                    ]
                                ]
                            ]
                        ]
                    ],
                    "400" => [
                        "description" => "Bad request - missing required fields or invalid data"
                    ],
                    "401" => [
                        "description" => "Unauthorized - invalid admin key"
                    ],
                    "409" => [
                        "description" => "Conflict - license key already exists"
                    ]
                ]
            ]
        ],
        "/api/swagger" => [
            "get" => [
                "summary" => "Swagger UI Documentation",
                "description" => "Interactive API documentation and testing interface",
                "responses" => [
                    "200" => [
                        "description" => "Swagger UI HTML interface"
                    ]
                ]
            ]
        ]
    ]
];

echo json_encode($swaggerSpec, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>