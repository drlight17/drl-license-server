# DRL License Server with API

A simple PHP-based license validation server for shareware applications with Docker support, Swagger UI documentation, and an administrator web interface.

## Features

- License key validation
- License activation
- License creation and deletion (admin only)
- License listing with search, filter, and pagination (admin only)
- Log viewing with filter and pagination (admin only)
- Docker containerization
- JSON API responses
- Comprehensive logging
- Swagger UI for API documentation and testing
- Administrator web interface
- Environment-based configuration
- Email notifications via SMTP
- Localization support
- Creation of licenses without `admin_key` requiring manual administrator activation
- Creation of licenses with `admin_key` for immediate activation

## Prerequisites

- Docker and Docker Compose
- PHP 7.4 or higher (if running without Docker)

## Installation

### Using Docker (Recommended)

1. Clone the repository:
```bash
git clone <repository-url>
cd license-server
```

2. Create `.env` file:
```bash
cp env.example .env
```

3. Edit `.env` file with your settings:
```env
# License Server Configuration
LICENSE_SECRET_KEY=your_very_secret_license_key_here_2023
LICENSE_SALT=your_salt_for_hashing_here
ADMIN_KEY=your_admin_secret_key_for_deletion_2023

# SMTP Configuration for Email Notifications
SMTP_HOST=smtp.yourmailserver.com
SMTP_PORT=587
SMTP_USERNAME=your_smtp_username
SMTP_PASSWORD=your_smtp_password
SMTP_ENCRYPTION=tls
SMTP_FROM=noreply@yourdomain.com
SMTP_FROM_NAME=License Server
SEND_EMAILS=true

# Admin Email for Notifications
ADMIN_EMAIL=admin@yourdomain.com

# Swagger UI Configuration
SWAGGER_SERVER_URL=http://localhost:8080
SWAGGER_SERVER_DESCRIPTION=Local development server
SWAGGER_CONTACT_NAME=API Support
SWAGGER_CONTACT_EMAIL=support@example.com
SWAGGER_API_TITLE=License Server API
SWAGGER_API_DESCRIPTION=API for license validation, activation, and management for shareware applications
SWAGGER_API_VERSION=1.0.0

# Timezone
TZ=UTC
```

4. Create necessary directories and language files:
```bash
mkdir -p data logs lang
```

5. Create language files:
- `lang/en.json` (English translations)
- `lang/ru.json` (Russian translations)

Example `lang/en.json`:
```json
{
  "email_license_created_user_subject": "License Created - Awaiting Activation",
  "email_license_created_user_body": "Hello,\n\nA new license for {product} has been created for you.\n\nLicense Key: {key}\nCreated on: {created}\n{expires_info}\n\nThis license requires manual activation by our administrator. You will receive another email once it's activated.\n\nBest regards,\nLicense Server",
  "email_license_created_admin_subject": "New License Request - Requires Activation",
  "email_license_created_admin_body": "Administrator,\n\nA new license request has been submitted and requires manual activation.\n\nUser: {user}\nProduct: {product}\nLicense Key: {key}\nCreated on: {created}\n{expires_info}\n\nPlease activate this license through the admin panel.\n\nBest regards,\nLicense Server",
  "...": "..."
}
```

6. Start the server:
```bash
docker-compose up -d
```

### Manual Installation

1. Install PHP 7.4+
2. Install Apache or Nginx web server
3. Copy files to web directory
4. Install PHPMailer via Composer or manually (for email functionality):
```bash
composer require phpmailer/phpmailer
```
5. Set proper permissions:
```bash
chmod -R 755 /path/to/web/directory
chmod 666 data/keys.json
chmod 666 logs/license.log
```

## Usage

### Accessing the API and Web Interface

After starting the server, you can access:
- **API Endpoints**: `http://localhost:8080/api`
- **Administrator Web Interface**: `http://localhost:8080/`
- **Swagger UI**: `http://localhost:8080/api/swagger`
- **API Documentation**: JSON specification at `http://localhost:8080/api/swagger-spec.php`

### Administrator Web Interface

1. Open `http://localhost:8080/` in your browser.
2. Enter your `ADMIN_KEY` from the `.env` file to log in.
3. Use the web interface to manage licenses, view logs, and send test emails.

## API Endpoints

### Base URL
```
http://localhost:8080/api
```

### 1. Validate License

**Endpoint:** `/api` (POST/GET)
**Action:** `validate`

**Request:**
```bash
# POST request
curl -X POST http://localhost:8080/api \
  -H "Content-Type: application/json" \
  -d '{"key": "XXXX-XXXX-XXXX-XXXX"}'

# GET request
curl "http://localhost:8080/api?key=XXXX-XXXX-XXXX-XXXX"
```

**Response (Valid):**
```json
{
    "valid": true,
    "product": "MyApp Pro",
    "user": "user@example.com",
    "expires": "2024-12-07T10:30:00+00:00",
    "activated": true,
    "timestamp": "2023-12-07T12:00:00+00:00",
    "success": true
}
```

**Response (Invalid):**
```json
{
    "valid": false,
    "reason": "Invalid license key",
    "timestamp": "2023-12-07T12:00:00+00:00",
    "success": true
}
```

### 2. Activate License

**Endpoint:** `/api` (POST/GET)
**Action:** `activate`

**Request:**
```bash
curl -X POST http://localhost:8080/api \
  -H "Content-Type: application/json" \
  -d '{
    "key": "XXXX-XXXX-XXXX-XXXX",
    "action": "activate",
    "admin_key": "your_admin_secret_key_for_deletion_2023"
  }'
```

**Response:**
```json
{
    "valid": true,
    "just_activated": true,
    "product": "MyApp Pro",
    "user": "user@example.com",
    "expires": "2024-12-07T10:30:00+00:00",
    "activated": true,
    "timestamp": "2023-12-07T12:00:00+00:00",
    "success": true
}
```

### 3. Create License

#### With `admin_key` (Immediate Activation)

**Endpoint:** `/api` (PUT/POST)
**Action:** `create`

**Request:**
```bash
# PUT request
curl -X PUT http://localhost:8080/api \
  -H "Content-Type: application/json" \
  -d '{
    "admin_key": "your_admin_secret_key_for_deletion_2023",
    "license_data": {
      "user": "user@example.com",
      "product": "MyApp Pro",
      "days": 365
    }
  }'

# POST request
curl -X POST http://localhost:8080/api \
  -H "Content-Type: application/json" \
  -d '{
    "action": "create",
    "admin_key": "your_admin_secret_key_for_deletion_2023",
    "license_data": {
      "user": "user2@example.com",
      "product": "MyApp Enterprise",
      "days": 730,
      "custom_key": "CUSTOM-KEY-FOR-USER"
    }
  }'
```

#### Without `admin_key` (Requires Manual Activation)

**Endpoint:** `/api` (PUT/POST)
**Action:** `create`

**Request:**
```bash
# PUT request
curl -X PUT http://localhost:8080/api \
  -H "Content-Type: application/json" \
  -d '{
    "license_data": {
      "user": "user3@example.com",
      "product": "MyApp Pro",
      "days": 365
    }
  }'

# POST request
curl -X POST http://localhost:8080/api \
  -H "Content-Type: application/json" \
  -d '{
    "action": "create",
    "license_data": {
      "user": "user4@example.com",
      "product": "MyApp Lite",
      "days": 30
    }
  }'
```

**Response (Both cases):**
```json
{
    "created": true,
    "key": "ABCD-EFGH-IJKL-MNOP",
    "license_info": {
        "user": "user@example.com",
        "product": "MyApp Pro",
        "created": "2023-12-07T16:30:00+00:00",
        "expires": "2024-12-07T16:30:00+00:00",
        "activated": false
    },
    "message": "License created, requires manual activation",
    "timestamp": "2023-12-07T16:30:00+00:00",
    "success": true
}
```

### 4. Delete License (Admin Only)

**Endpoint:** `/api` (DELETE/POST)
**Action:** `delete`

**Request:**
```bash
# DELETE request
curl -X DELETE "http://localhost:8080/api?key=ABCD-EFGH-IJKL-MNOP&admin_key=your_admin_secret_key_for_deletion_2023"

# POST request
curl -X POST http://localhost:8080/api \
  -H "Content-Type: application/json" \
  -d '{
    "key": "ABCD-EFGH-IJKL-MNOP",
    "action": "delete",
    "admin_key": "your_admin_secret_key_for_deletion_2023"
  }'
```

**Response:**
```json
{
    "deleted": true,
    "key": "ABCD-EFGH-IJKL-MNOP",
    "deleted_info": {
        "user": "user@example.com",
        "product": "MyApp Pro",
        "created": "2023-12-07T10:30:00+00:00",
        "expires": "2024-12-07T10:30:00+00:00",
        "activated": true
    },
    "timestamp": "2023-12-07T15:00:00+00:00",
    "success": true
}
```

### 5. List All Licenses (Admin Only)

**Endpoint:** `/api` (GET/POST)
**Action:** `list`

**Request:**
```bash
# Get all licenses (page 1, 20 items)
curl "http://localhost:8080/api?action=list&admin_key=your_admin_secret_key_for_deletion_2023"

# Get page 2 with 10 items per page
curl "http://localhost:8080/api?action=list&admin_key=your_admin_secret_key_for_deletion_2023&page=2&limit=10"

# Search for licenses containing "John"
curl "http://localhost:8080/api?action=list&admin_key=your_admin_secret_key_for_deletion_2023&search=John"

# Filter for active licenses
curl "http://localhost:8080/api?action=list&admin_key=your_admin_secret_key_for_deletion_2023&status=active"
```

**Response:**
```json
{
    "count": 5,
    "total": 50,
    "page": 1,
    "pages": 10,
    "limit": 5,
    "licenses": {
        "ABCD-EFGH-IJKL-MNOP": {
            "user": "user@example.com",
            "product": "MyApp Pro",
            "created": "2023-12-07T10:30:00+00:00",
            "expires": "2024-12-07T10:30:00+00:00",
            "activated": true
        }
    },
    "timestamp": "2023-12-07T15:00:00+00:00",
    "success": true
}
```

### 6. View Logs (Admin Only)

**Endpoint:** `/api` (GET/POST)
**Action:** `logs`

**Request:**
```bash
# Get all logs (page 1, 50 entries)
curl "http://localhost:8080/api?action=logs&admin_key=your_admin_secret_key_for_deletion_2023"

# Get page 2 with 25 entries per page
curl "http://localhost:8080/api?action=logs&admin_key=your_admin_secret_key_for_deletion_2023&page=2&limit=25"

# Filter for 'create' operations
curl "http://localhost:8080/api?action=logs&admin_key=your_admin_secret_key_for_deletion_2023&operation=create"
```

**Response:**
```json
{
    "content": [
        {
            "timestamp": "2023-12-07T18:35:05+00:00",
            "action": "validate",
            "ip": "172.20.0.1",
            "user_agent": "MyApp/1.0",
            "details": {
                "key": "ABCD-EFGH-IJKL-MNOP",
                "valid": true,
                "reason": null
            }
        }
    ],
    "count": 1,
    "total": 150,
    "page": 1,
    "pages": 150,
    "limit": 1,
    "file_exists": true,
    "timestamp": "2023-12-07T19:00:00+00:00",
    "success": true
}
```

## Swagger UI

The API includes Swagger UI for easy testing and documentation:

1. Visit `http://localhost:8080/api/swagger`
2. Use the interactive interface to test all API endpoints
3. All examples and parameters are pre-configured
4. Responses are displayed in real-time

### Customizing Swagger UI

You can customize Swagger UI through environment variables in `.env`:

```env
# Swagger UI Configuration
SWAGGER_SERVER_URL=https://api.yourcompany.com/license
SWAGGER_SERVER_DESCRIPTION=Production License Server
SWAGGER_CONTACT_NAME=Development Team
SWAGGER_CONTACT_EMAIL=dev@yourcompany.com
SWAGGER_API_TITLE=YourApp License Management API
SWAGGER_API_DESCRIPTION=API for managing licenses for YourApp products
SWAGGER_API_VERSION=2.1.0
```

## Error Responses

All error responses follow this format:
```json
{
    "success": false,
    "error": "Error message",
    "timestamp": "2023-12-07T12:00:00+00:00"
}
```

Common error codes:
- `400` - Bad Request (missing parameters)
- `401` - Unauthorized (invalid admin key)
- `403` - Forbidden (access denied)
- `404` - Not Found
- `405` - Method Not Allowed
- `409` - Conflict (license key already exists)


## Environment Variables

### License Server Configuration
| Variable | Description | Default |
|----------|-------------|---------|
| `LICENSE_SECRET_KEY` | Secret key for license operations | `default_secret_key` |
| `LICENSE_SALT` | Salt for hashing operations | `default_salt` |
| `ADMIN_KEY` | Admin key for protected operations | `admin_secret_key_2023` |
| `ADMIN_EMAIL` | Admin email for notifications | `null` |
| `TZ` | Timezone | `UTC` |

### SMTP Configuration
| Variable | Description | Default |
|----------|-------------|---------|
| `SMTP_HOST` | SMTP server | `null` |
| `SMTP_PORT` | SMTP port | `587` |
| `SMTP_USERNAME` | SMTP username | `null` |
| `SMTP_PASSWORD` | SMTP password | `null` |
| `SMTP_ENCRYPTION` | SMTP encryption (tls, ssl, '') | `tls` |
| `SMTP_FROM` | Sender email | `noreply@localhost` |
| `SMTP_FROM_NAME` | Sender name | `License Server` |
| `SEND_EMAILS` | Enable email sending (true/false) | `false` |

### Swagger UI Configuration
| Variable | Description | Default |
|----------|-------------|---------|
| `SWAGGER_SERVER_URL` | API server URL | `http://localhost:8080` |
| `SWAGGER_SERVER_DESCRIPTION` | Server description | `Local development server` |
| `SWAGGER_CONTACT_NAME` | Contact name | `API Support` |
| `SWAGGER_CONTACT_EMAIL` | Contact email | `support@example.com` |
| `SWAGGER_API_TITLE` | API title | `License Server API` |
| `SWAGGER_API_DESCRIPTION` | API description | `API for license validation...` |
| `SWAGGER_API_VERSION` | API version | `1.0.0` |

## Docker Commands

```bash
# Start server
docker-compose up -d

# View logs
docker-compose logs -f

# Stop server
docker-compose down

# Rebuild container
docker-compose up -d --build

# Access container shell
docker-compose exec license-server bash
```

## Data Persistence

License data and logs are stored in Docker volumes:
- `./data` - License keys (`keys.json`)
- `./logs` - Activity logs (`license.log`)

## Security Considerations

1. **Change default keys** in `.env` file
2. **Use HTTPS** in production
3. **Restrict admin key access**
4. **Regular log monitoring**
5. **Backup license data regularly**
6. **Use strong, unique passwords for admin operations**
7. **Configure a secure SMTP server for email notifications**

## Example Usage in some web app

```javascript
async function validateLicense(licenseKey) {
    try {
        const response = await fetch('http://localhost:8080/api', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ key: licenseKey })
        });

        const result = await response.json();
        return result.valid;
    } catch (error) {
        console.error('License validation failed:', error);
        return false;
    }
}
```

## Troubleshooting

### Common Issues

1. **Permission denied errors:**
   ```bash
   chmod -R 755 www-data:www-data data logs
   chmod 666 data/keys.json logs/license.log
   ```

2. **Docker permission errors:**
   ```bash
   sudo usermod -aG docker $USER
   ```

3. **Port already in use:**
   Change port in `docker-compose.yml`

4. **Environment variables not loading:**
   Make sure `.env` file exists and has correct format

### Log Files

Check logs for debugging:
```bash
# Docker logs
docker-compose logs license-server

# Application logs
tail -f logs/license.log
```

## Development

### Project Structure
- `/src/api.php` - Core API logic (main file from KB)
- `/src/index.php` - Entry point, localization, web interface
- `/src/main.html` - Web interface template
- `/src/js/main.js` - Web interface JavaScript
- `/src/swagger.php` - Swagger UI interface
- `/src/swagger-spec.php` - OpenAPI specification
- `.env` - Configuration variables
- `Dockerfile` - Docker image definition
- `docker-compose.yml` - Docker orchestration
- `/src/lang/*.json` - Localization files
- `/src/css/style.css` - main CSS style

### Adding New Features
1. Modify `api.php` to add new endpoints
2. Update `swagger-spec.php` with new API documentation
3. Test using Swagger UI
4. Update `index.php` and `main.html`/`main.js` to integrate with web interface
5. Update README.md with new features

## License

This project is licensed under the MIT License - see the LICENSE file for details.

## Support

For issues and feature requests, please create an issue in the repository.
