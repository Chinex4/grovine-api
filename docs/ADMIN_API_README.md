# Grovine Admin API README

This file documents every current admin API endpoint in the backend so a frontend admin dashboard can be built directly against it.

## Base Rules

- Base URL: `{{base_url}}/api`
- Admin prefix: `/admin`
- Auth: every endpoint in this file requires `Authorization: Bearer {{admin_token}}`
- Middleware: `auth.jwt` + `role:admin`
- Default success envelope:

```json
{
  "message": "Human readable status message.",
  "data": {}
}
```

- Default validation error:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "field_name": ["Validation message"]
  }
}
```

## Important Notes For Frontend

- Admin auth is now separate from user OTP auth.
- Admin login endpoint: `POST /api/auth/admin/login`
- Admin password change endpoint: `POST /api/auth/admin/change-password`
- User `status` in the admin user response is the email verification status: `verified` or `unverified`.
- User moderation state is returned separately as `account_status`: `active`, `suspended`, or `banned`.
- The admin template screenshot includes a "Reset Password" action, but this backend uses OTP auth, not password auth. There is currently no admin password-reset endpoint.
- User `stats.followers_count` and `stats.reports_received_count` currently return `0`. There is no follower/reporting module yet.
- File upload endpoints must use `multipart/form-data`.

## Endpoint Catalog

| Method | Path | Purpose |
| --- | --- | --- |
| POST | `/auth/admin/login` | Admin email/password login |
| POST | `/auth/admin/change-password` | Change current admin password |
| GET | `/admin/ads` | List ads |
| POST | `/admin/ads` | Create ad |
| PATCH | `/admin/ads/{ad}` | Update ad |
| DELETE | `/admin/ads/{ad}` | Delete ad |
| GET | `/admin/categories` | List categories |
| POST | `/admin/categories` | Create category |
| PATCH | `/admin/categories/{category}` | Update category |
| DELETE | `/admin/categories/{category}` | Delete category |
| GET | `/admin/products` | List products |
| POST | `/admin/products` | Create product |
| PATCH | `/admin/products/{product}` | Update product |
| DELETE | `/admin/products/{product}` | Delete product |
| POST | `/admin/notifications/send` | Send admin broadcast notification |
| GET | `/admin/niches` | List chef niches |
| POST | `/admin/niches` | Create chef niche |
| PATCH | `/admin/niches/{chefNiche}` | Update chef niche |
| DELETE | `/admin/niches/{chefNiche}` | Delete chef niche |
| GET | `/admin/recipes` | List recipes for moderation |
| PATCH | `/admin/recipes/{recipe}/review` | Approve or reject recipe |
| PATCH | `/admin/recipes/{recipe}/features` | Toggle recipe highlights |
| DELETE | `/admin/recipes/{recipe}` | Delete recipe |
| GET | `/admin/users` | List users with filters/search |
| POST | `/admin/users` | Create user |
| GET | `/admin/users/charts/growth` | User growth chart |
| GET | `/admin/users/charts/activity` | User activity chart |
| GET | `/admin/users/{user}` | User details |
| PATCH | `/admin/users/{user}` | Update user |
| DELETE | `/admin/users/{user}` | Delete user |
| POST | `/admin/users/{user}/warnings` | Send warning to user |
| POST | `/admin/users/{user}/suspend` | Suspend user |
| POST | `/admin/users/{user}/activate` | Reactivate suspended/banned user |
| POST | `/admin/users/{user}/ban` | Ban user |

## Shared Response Shapes

### Admin Auth Response

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
      "role": "admin"
    }
  }
}
```

### Ad Object

```json
{
  "id": "uuid",
  "title": "Launch Promo",
  "image_url": "https://example.com/storage/ads/banner.jpg",
  "link": "https://example.com/promo",
  "is_active": true,
  "sort_order": 1,
  "created_at": "2026-03-16T10:00:00Z",
  "updated_at": "2026-03-16T10:00:00Z"
}
```

### Category Object

```json
{
  "id": "uuid",
  "name": "Fruits",
  "slug": "fruits",
  "description": "Fresh fruits",
  "image_url": "https://example.com/storage/categories/fruits.jpg",
  "is_active": true,
  "sort_order": 1,
  "created_at": "2026-03-16T10:00:00Z",
  "updated_at": "2026-03-16T10:00:00Z"
}
```

### Product Object

```json
{
  "id": "uuid",
  "category_id": "uuid",
  "name": "Grape Basket",
  "slug": "grape-basket",
  "description": "Fresh grape basket",
  "image_url": "https://example.com/storage/products/grape.jpg",
  "price": "4800.79",
  "discount": "200.00",
  "final_price": "4600.79",
  "stock": 50,
  "is_active": true,
  "is_recommended": true,
  "is_rush_hour_offer": true,
  "rush_hour_starts_at": "2026-03-16T09:00:00Z",
  "rush_hour_ends_at": "2026-03-16T12:00:00Z",
  "category": {
    "id": "uuid",
    "name": "Fruits",
    "slug": "fruits"
  },
  "created_at": "2026-03-16T10:00:00Z",
  "updated_at": "2026-03-16T10:00:00Z"
}
```

### Chef Niche Object

```json
{
  "id": "uuid",
  "name": "Soups & Stews",
  "slug": "soups-stews",
  "description": "Soup niche",
  "is_active": true,
  "sort_order": 1,
  "created_at": "2026-03-16T10:00:00Z",
  "updated_at": "2026-03-16T10:00:00Z"
}
```

### Recipe Object

```json
{
  "id": "uuid",
  "chef_id": "uuid",
  "status": "PENDING_APPROVAL",
  "title": "Egusi Soup",
  "slug": "egusi-soup",
  "short_description": "Rich melon soup",
  "instructions": "Returned when ingredients are loaded or include_instructions=1",
  "video_url": "https://example.com/storage/recipes/video.mp4",
  "cover_image_url": "https://example.com/storage/recipes/cover.jpg",
  "duration_seconds": 900,
  "servings": 4,
  "estimated_cost": "3500.00",
  "is_recommended": false,
  "is_quick_recipe": false,
  "views_count": 120,
  "bookmarks_count": 10,
  "ingredients_count": 6,
  "submitted_at": "2026-03-16T10:00:00Z",
  "approved_at": null,
  "rejected_at": null,
  "rejection_reason": null,
  "published_at": null,
  "chef": {
    "id": "uuid",
    "name": "Mary Fubara",
    "chef_name": "Chef Mary",
    "username": "chef_mary",
    "profile_picture": "https://example.com/storage/profiles/avatar.jpg",
    "chef_profile_share_url": "https://example.com/api/chefs/chef_mary",
    "niche": {
      "id": "uuid",
      "name": "Soups",
      "slug": "soups"
    }
  },
  "ingredients": [],
  "created_at": "2026-03-16T10:00:00Z",
  "updated_at": "2026-03-16T10:00:00Z"
}
```

### Admin User Object

```json
{
  "id": "uuid",
  "name": "Mary Fubara",
  "chef_name": null,
  "display_name": "Mary Fubara",
  "username": "maryfubara",
  "email": "mary@example.com",
  "phone": "+2348011111111",
  "address": "Lagos, Nigeria",
  "date_of_birth": "1995-04-12",
  "profile_picture": "https://example.com/storage/profiles/avatar.jpg",
  "role": "user",
  "is_verified": true,
  "verification_status": "verified",
  "account_status": "active",
  "status": "verified",
  "warning_count": 1,
  "last_warned_at": "2026-03-16T10:00:00Z",
  "suspended_until": null,
  "suspension_reason": null,
  "banned_at": null,
  "banned_reason": null,
  "last_active": "2026-03-16T09:40:00Z",
  "last_seen_at": "2026-03-16T09:40:00Z",
  "joined_at": "2026-03-10T14:00:00Z",
  "created_at": "2026-03-10T14:00:00Z",
  "updated_at": "2026-03-16T10:00:00Z",
  "stats": {
    "posts_created_count": 2,
    "bookmarked_recipes_count": 4,
    "notifications_received_count": 7,
    "warnings_count": 1,
    "followers_count": 0,
    "reports_received_count": 0
  },
  "chef_niche": null,
  "chef_niches": []
}
```

## Ads

## Admin Auth

### POST `/auth/admin/login`

- Content-Type: `application/json`
- Body:

```json
{
  "email": "admin@grovine.ng",
  "password": "ChangeMe123!"
}
```

- Rules:
  - `email` required valid email
  - `password` required string min `8`
- Success: returns `Admin Auth Response`
- Invalid credentials:

```json
{
  "message": "Invalid admin credentials."
}
```

- Suspended admin:

```json
{
  "message": "This admin account is suspended until Thu, Mar 19, 2026 10:00 AM."
}
```

### POST `/auth/admin/change-password`

- Auth: admin bearer token required
- Content-Type: `application/json`
- Body:

```json
{
  "current_password": "ChangeMe123!",
  "password": "NewSecret456!",
  "password_confirmation": "NewSecret456!"
}
```

- Rules:
  - `current_password` required
  - `password` required, min `8`, confirmed
- Success:

```json
{
  "message": "Admin password changed successfully.",
  "data": {
    "updated_at": "2026-03-18T10:00:00Z"
  }
}
```

- Failure:

```json
{
  "message": "Current password is incorrect.",
  "errors": {
    "current_password": ["Current password is incorrect."]
  }
}
```

### GET `/admin/ads`

- Body: none
- Response: `data` is `Ad[]`

### POST `/admin/ads`

- Content-Type: `multipart/form-data`
- Body fields:
  - `title` required string max `160`
  - `image` required image max `5MB`
  - `link` nullable valid URL
  - `is_active` optional boolean
  - `sort_order` optional integer `>= 0`
- Success: `201`, returns created `Ad`

### PATCH `/admin/ads/{ad}`

- Content-Type: `multipart/form-data`
- Body fields: same as create, all optional
- Success: `200`, returns updated `Ad`

### DELETE `/admin/ads/{ad}`

- Body: none
- Success:

```json
{
  "message": "Ad deleted successfully."
}
```

## Categories

### GET `/admin/categories`

- Body: none
- Response: `data` is `Category[]`

### POST `/admin/categories`

- Content-Type: `multipart/form-data`
- Body fields:
  - `name` required string max `120`
  - `description` nullable string
  - `image` nullable image max `5MB`
  - `is_active` optional boolean
  - `sort_order` optional integer `>= 0`
- Success: `201`, returns created `Category`

### PATCH `/admin/categories/{category}`

- Content-Type: `multipart/form-data`
- Body fields: same as create, all optional
- Success: `200`, returns updated `Category`

### DELETE `/admin/categories/{category}`

- Body: none
- Success:

```json
{
  "message": "Category deleted successfully."
}
```

- Failure:

```json
{
  "message": "Cannot delete category with existing products."
}
```

## Products

### GET `/admin/products`

- Body: none
- Response: `data` is `Product[]`

### POST `/admin/products`

- Content-Type: `multipart/form-data`
- Body fields:
  - `name` required string max `160`
  - `description` nullable string
  - `image` required image max `5MB`
  - `price` required numeric `>= 0`
  - `category_id` required UUID
  - `stock` required integer `>= 0`
  - `discount` optional numeric `>= 0` and `<= price`
  - `is_active` optional boolean
  - `is_recommended` optional boolean
  - `is_rush_hour_offer` optional boolean
  - `rush_hour_starts_at` nullable date
  - `rush_hour_ends_at` nullable date and must be `>= rush_hour_starts_at`
- Success: `201`, returns created `Product`

### PATCH `/admin/products/{product}`

- Content-Type: `multipart/form-data` or JSON
- Body fields: same as create, all optional
- Special rule: if resolved `discount > price`, response is:

```json
{
  "message": "Discount cannot be greater than price."
}
```

- Success: `200`, returns updated `Product`

### DELETE `/admin/products/{product}`

- Body: none
- Success:

```json
{
  "message": "Product deleted successfully."
}
```

## Notifications

### POST `/admin/notifications/send`

- Content-Type: `application/json`
- Body:

```json
{
  "title": "Fast Sale",
  "message": "Fresh products now discounted.",
  "category": "ADMIN",
  "channels": ["all"],
  "audience": "roles",
  "roles": ["user"],
  "action_url": "https://app.grovine.ng/offers",
  "image_url": null,
  "data": {
    "screen": "offers"
  }
}
```

- Validation rules:
  - `title` required string max `180`
  - `message` required string max `4000`
  - `category` optional: `ACCOUNT_ACTIVITY`, `SYSTEM`, `ADMIN`
  - `channels` required array of `in_app`, `email`, `push`, `all`
  - `audience` required: `all`, `users`, `roles`
  - `user_ids` required if `audience=users`
  - `roles` required if `audience=roles`, values: `user`, `chef`, `admin`
  - `action_url` nullable string max `2048`
  - `image_url` nullable string max `2048`
  - `data` nullable object
- Success:

```json
{
  "message": "Notification dispatch completed.",
  "data": {
    "recipient_count": 12,
    "summary": {
      "in_app": 12,
      "email": 0,
      "push": 0,
      "failed": 0
    }
  }
}
```

## Chef Niches

### GET `/admin/niches`

- Body: none
- Response: `data` is `Chef Niche[]`

### POST `/admin/niches`

- Content-Type: `application/json`
- Body:

```json
{
  "name": "Soups & Stews",
  "description": "Soup niche",
  "is_active": true,
  "sort_order": 1
}
```

- Success: `201`, returns created `Chef Niche`

### PATCH `/admin/niches/{chefNiche}`

- Content-Type: `application/json`
- Body fields: same as create, all optional
- Success: `200`, returns updated `Chef Niche`

### DELETE `/admin/niches/{chefNiche}`

- Body: none
- Success:

```json
{
  "message": "Chef niche deleted successfully."
}
```

- Failure:

```json
{
  "message": "Cannot delete chef niche currently assigned to users."
}
```

## Recipes

### GET `/admin/recipes`

- Query params:
  - `status` optional: `DRAFT`, `PENDING_APPROVAL`, `APPROVED`, `REJECTED`
  - `chef_id` optional UUID
  - `q` optional string max `120`
- Response: `data` is `Recipe[]`

### PATCH `/admin/recipes/{recipe}/review`

- Content-Type: `application/json`
- Approve body:

```json
{
  "action": "approve",
  "is_recommended": true,
  "is_quick_recipe": false
}
```

- Reject body:

```json
{
  "action": "reject",
  "reason": "Please improve video quality."
}
```

- Rules:
  - `action` required: `approve` or `reject`
  - `reason` required when rejecting
  - `is_recommended` optional boolean
  - `is_quick_recipe` optional boolean
- Success: `200`, returns updated `Recipe`

### PATCH `/admin/recipes/{recipe}/features`

- Content-Type: `application/json`
- Body:

```json
{
  "is_recommended": true,
  "is_quick_recipe": true
}
```

- Rules:
  - at least one of `is_recommended`, `is_quick_recipe` must be provided
  - only approved recipes can be updated
- Possible failures:

```json
{
  "message": "Provide at least one feature flag to update."
}
```

```json
{
  "message": "Only approved recipes can be marked as quick or recommended."
}
```

- Success: `200`, returns updated `Recipe`

### DELETE `/admin/recipes/{recipe}`

- Body: none
- Success:

```json
{
  "message": "Recipe deleted successfully."
}
```

## Users

### GET `/admin/users`

- Query params:
  - `q` optional search over `name`, `chef_name`, `username`, `email`, `phone`
  - `role` optional: `user`, `chef`, `admin`
  - `verification_status` optional: `verified`, `unverified`
  - `account_status` optional: `active`, `suspended`, `banned`
  - `sort_by` optional: `name`, `email`, `role`, `created_at`, `last_seen_at`
  - `sort_direction` optional: `asc`, `desc`
  - `per_page` optional integer `1-100`
- Success:

```json
{
  "message": "Users fetched successfully.",
  "data": {
    "items": [
      {
        "id": "uuid",
        "name": "Mary Fubara",
        "email": "mary@example.com",
        "role": "user",
        "verification_status": "verified",
        "account_status": "active",
        "last_active": "2026-03-16T09:40:00Z",
        "stats": {
          "posts_created_count": 2,
          "followers_count": 0,
          "reports_received_count": 0
        }
      }
    ],
    "pagination": {
      "current_page": 1,
      "last_page": 2,
      "per_page": 15,
      "total": 20,
      "from": 1,
      "to": 15
    }
  }
}
```

### POST `/admin/users`

- Content-Type: `multipart/form-data`
- Body fields:
  - `name` required string max `120`
  - `username` optional string min `3`, max `50`, regex `^[A-Za-z0-9_]+$`
  - `email` required unique email
  - `phone` nullable string max `30`
  - `date_of_birth` nullable date before today
  - `address` nullable string max `500`
  - `role` optional: `user`, `chef`, `admin`
  - `chef_name` nullable string max `120`
  - `chef_niche_id` nullable UUID of active niche
  - `chef_niche_ids[]` optional array of active niche UUIDs
  - `onboarding_completed` optional boolean
  - `is_verified` optional boolean. Defaults to `true` on create.
  - `profile_picture` optional image max `5MB`
  - `password` required for `role=admin`, min `8`, confirmed
- Success: `201`, returns full `Admin User Object`

### GET `/admin/users/{user}`

- Body: none
- Success: `200`, returns full `Admin User Object`

### PATCH `/admin/users/{user}`

- Content-Type: `application/json` or `multipart/form-data`
- Body fields: same as create, all optional
- Notes:
  - changing role away from `chef` clears `chef_name`, `chef_niche_id`, and synced chef niches
  - setting `is_verified` to `false` clears `email_verified_at`
  - `password` is only accepted for admin accounts
  - if a user is being changed into an admin and has no existing password, a password must be supplied
- Success: `200`, returns full `Admin User Object`

### DELETE `/admin/users/{user}`

- Body: none
- Success:

```json
{
  "message": "User deleted successfully."
}
```

- Special rule: admin cannot perform this action on their own account

### POST `/admin/users/{user}/warnings`

- Content-Type: `application/json`
- Body:

```json
{
  "title": "Account warning",
  "message": "Please update your profile details.",
  "channels": ["in_app"]
}
```

- Rules:
  - `title` optional string max `180`
  - `message` required string max `4000`
  - `channels` optional array of `in_app`, `email`, `push`, `all`
- Success:

```json
{
  "message": "Warning sent successfully.",
  "data": {
    "user": {},
    "summary": {
      "in_app": 1,
      "email": 0,
      "push": 0,
      "failed": 0
    }
  }
}
```

### POST `/admin/users/{user}/suspend`

- Content-Type: `application/json`
- Body:

```json
{
  "duration": "7_days",
  "reason": "Spam activity"
}
```

- `duration` values:
  - `24_hours`
  - `7_days`
  - `30_days`
  - `indefinite`
- Success: `200`, returns updated `Admin User Object`
- Special rule: admin cannot suspend their own account

### POST `/admin/users/{user}/activate`

- Body: none
- Success: `200`, returns updated `Admin User Object`
- Purpose: clears suspension and ban state and sets `account_status` back to `active`

### POST `/admin/users/{user}/ban`

- Content-Type: `application/json`
- Body:

```json
{
  "reason": "Fraudulent activity"
}
```

- Success: `200`, returns updated `Admin User Object`
- Special rule: admin cannot ban their own account

## User Charts

### GET `/admin/users/charts/growth`

- Query params:
  - `period` optional: `7d`, `30d`, `90d`
- Success:

```json
{
  "message": "User growth chart fetched successfully.",
  "data": {
    "period": "30d",
    "start_date": "2026-02-16",
    "end_date": "2026-03-16",
    "summary": {
      "total_users": 120,
      "new_users_in_range": 24
    },
    "series": [
      {
        "date": "2026-03-10",
        "label": "Mar 10",
        "new_users": 3,
        "cumulative_users": 99
      }
    ]
  }
}
```

### GET `/admin/users/charts/activity`

- Query params:
  - `period` optional: `7d`, `30d`, `90d`
- Success:

```json
{
  "message": "User activity chart fetched successfully.",
  "data": {
    "period": "30d",
    "start_date": "2026-02-16",
    "end_date": "2026-03-16",
    "summary": {
      "active_users_today": 18,
      "active_users_in_range": 64,
      "total_hits_in_range": 140
    },
    "series": [
      {
        "date": "2026-03-10",
        "label": "Mar 10",
        "active_users": 8,
        "hits": 14
      }
    ]
  }
}
```

## Frontend Implementation Notes

- For the users table:
  - use `verification_status` for the screenshot-style verified/unverified badge
  - use `account_status` for suspend/ban logic
  - use `last_active` for the "Last Active" column
- For the user details modal:
  - use `joined_at`, `phone`, `address`, and `stats`
- For suspend modal:
  - map UI buttons to `duration` values: `24_hours`, `7_days`, `30_days`, `indefinite`
- For filters:
  - role filter maps to `role`
  - verified filter maps to `verification_status`
  - moderation filter maps to `account_status`
- For charts:
  - growth chart uses `data.series[].new_users`
  - activity chart uses `data.series[].active_users` or `data.series[].hits`

## Postman Collection

The Postman collection has been updated at:

- `postman/grovine-api.postman_collection.json`

The admin user requests added there are:

- `Admin Users`
- `User Growth Chart`
- `User Activity Chart`
- `Create User`
- `User Details`
- `Update User`
- `Send User Warning`
- `Suspend User`
- `Activate User`
- `Ban User`
- `Delete User`
- `Admin Change Password`
