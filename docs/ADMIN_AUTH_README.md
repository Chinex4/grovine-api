# Admin Auth Update README

This file covers the latest admin authentication update: dedicated admin email/password login and admin password change.

## What Changed

- Added `POST /api/auth/admin/login`
- Added `POST /api/auth/admin/change-password`
- Admin auth is now separate from the OTP-based user auth flow
- Admin accounts now support stored hashed passwords
- Admin account creation/update can accept a password when `role=admin`
- The seeded admin account now gets a password from `ADMIN_DEFAULT_PASSWORD`

## Setup

Add this to your environment:

```env
ADMIN_DEFAULT_PASSWORD=ChangeMe123!
```

It has also been added to `.env.example`.

If you use the default seeder, the admin account is:

```text
email: admin@grovine.ng
password: value of ADMIN_DEFAULT_PASSWORD
```

## Endpoint 1: Admin Login

- Method: `POST`
- URL: `/api/auth/admin/login`
- Auth: none
- Content-Type: `application/json`

### Request Body

```json
{
  "email": "admin@grovine.ng",
  "password": "ChangeMe123!"
}
```

### Success Response

```json
{
  "message": "Admin login successful.",
  "data": {
    "token_type": "Bearer",
    "access_token": "jwt-token",
    "expires_at": "2026-03-20T10:00:00Z",
    "user": {
      "id": "uuid",
      "name": "Admin User",
      "username": "admin_user_1001",
      "email": "admin@grovine.ng",
      "role": "admin",
      "profile_picture": null
    }
  }
}
```

### Failure Responses

Invalid credentials:

```json
{
  "message": "Invalid admin credentials."
}
```

Suspended admin:

```json
{
  "message": "This admin account is suspended until Thu, Mar 19, 2026 10:00 AM."
}
```

Banned admin:

```json
{
  "message": "This admin account has been banned."
}
```

## Endpoint 2: Change Admin Password

- Method: `POST`
- URL: `/api/auth/admin/change-password`
- Auth: `Authorization: Bearer {{admin_token}}`
- Access: admin only
- Content-Type: `application/json`

### Request Body

```json
{
  "current_password": "ChangeMe123!",
  "password": "NewSecret456!",
  "password_confirmation": "NewSecret456!"
}
```

### Success Response

```json
{
  "message": "Admin password changed successfully.",
  "data": {
    "updated_at": "2026-03-18T10:00:00Z"
  }
}
```

### Failure Response

```json
{
  "message": "Current password is incorrect.",
  "errors": {
    "current_password": ["Current password is incorrect."]
  }
}
```

## Admin User CRUD Impact

The admin user-management endpoints now support passwords for admin accounts.

### Create Admin User

- Endpoint: `POST /api/admin/users`
- If `role=admin`, `password` is required
- `password_confirmation` must match

Example:

```json
{
  "name": "Second Admin",
  "email": "second-admin@example.com",
  "role": "admin",
  "password": "Secret123!",
  "password_confirmation": "Secret123!"
}
```

### Update User Into Admin

- Endpoint: `PATCH /api/admin/users/{user}`
- If the target becomes an admin and does not already have a password, a password must be supplied
- Password fields are ignored for non-admin roles and will return validation errors if sent

## Postman

The Postman collection now includes:

- `Auth > Admin Login`
- `Admin > Admin Change Password`

Collection file:

- `postman/grovine-api.postman_collection.json`
