# API Backend - Catálogo, Carrito Público y Usuarios

API REST consumida por el proyecto Laravel cliente.

## Requisitos

- PHP 8.2+
- Composer
- MySQL

## Instalación

1. Ejecutar el SQL en MySQL Workbench:
   - Abrir `database/schema_productos.sql`
   - Ejecutar todo el script (crea la base de datos `catalogo_api` y la tabla `productos` con datos de ejemplo)

2. Configurar `.env` si es necesario:
   - `DB_DATABASE=catalogo_api`
   - `DB_USERNAME=root`
   - `DB_PASSWORD=` (o tu contraseña de MySQL)

## Ejecutar la API

```bash
php artisan serve --port=8001
```

La API quedará en: **http://127.0.0.1:8001**

## Endpoints públicos

| Método | Ruta | Descripción |
|--------|------|-------------|
| GET | /api/products | Listado de productos |
| GET | /api/products/{id} | Detalle de un producto |

## Endpoints de autenticación

| Método | Ruta | Descripción |
|--------|------|-------------|
| POST | /api/register | Registro de usuario (retorna token) |
| POST | /api/login | Inicio de sesión (retorna token) |

## Endpoints protegidos (requieren Bearer token)

| Método | Ruta | Descripción |
|--------|------|-------------|
| POST | /api/logout | Cierre de sesión (revoca token actual) |
| GET | /api/profile | Ver perfil |
| PUT | /api/profile | Actualizar datos generales (name, email, phone) |
| POST | /api/profile/avatar | Actualizar imagen de perfil |
| PUT | /api/profile/password | Actualizar contraseña (retorna nuevo token) |

## Campos esperados por endpoint

- `POST /api/register`: `name`, `email`, `password`, `password_confirmation`
- `POST /api/login`: `email`, `password`
- `PUT /api/profile/password`: `current_password`, `password`, `password_confirmation`
- `POST /api/profile/avatar`: multipart/form-data con archivo `avatar`

## Puerto

- **API Backend:** puerto **8001**
- **Cliente Laravel:** puerto **8000** (`php artisan serve` en la raíz del proyecto)
