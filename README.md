# Online Lighting System

Database Systems final project: an online temple lantern lighting system.

## Project Areas

- `public/`: member-facing pages.
- `admin/`: administrator pages.
- `api/`: reusable request handlers and service endpoints.
- `config/`: database and environment configuration.
- `assets/`: CSS, JavaScript, and image files.
- `sql/`: MySQL schema and seed data.

## First Milestone

Build the main verification flow:

1. Register and log in.
2. Add prayer/dependent information.
3. Browse lantern types.
4. Add lanterns to cart.
5. Create an order.
6. Review and assign a lamp position in admin.
7. Query order status from the member page.

## Google Sign-Up Setup

The code path for Google one-click sign-up is implemented. To enable it:

1. Create an OAuth 2.0 Web application in Google Cloud Console.
2. Add this Authorized redirect URI, replacing the host with your current domain or tunnel URL:
   `https://your-domain.example/public/oauth_callback.php?provider=google`
3. Put the generated Client ID and Client Secret into `config/integrations.local.php`.
4. Keep the Cloudflare Tunnel URL unchanged while testing. If the tunnel URL changes, update `public_base_url` and the Google redirect URI.
