# Commerce Product Social Marketing

WooCommerce plugin that lets you run your own marketing and promotions, without paying for third-party SaaS:

- **Auto-post products to social media** — Facebook Pages, Instagram Business accounts and Google Business Profile, via their official APIs.
- **Create ads** — build Meta (Facebook/Instagram) ad campaigns for products straight from WordPress (created paused; activate them in Ads Manager).
- **Your own vouchers & gift certificates** — unique codes with fixed/percentage discounts or a stored balance, usage limits, expiry, email delivery and redemption at checkout.

## Requirements

- WordPress 6.0+
- PHP 7.4+
- WooCommerce active

## Install

1. Download the latest release zip from the [releases page](https://github.com/RogueRazor/commerce-product-social-marketing/releases) (or clone this repo into `wp-content/plugins/commerce-product-social-marketing`).
2. Activate **Commerce Product Social Marketing** in wp-admin.
3. The plugin ships with a built-in updater: tagged GitHub releases show up as normal WordPress updates.

## Setup: social posting

Open **Social & Vouchers** in the admin menu.

### Reusing an existing Meta app or Google OAuth client

If you already use a Meta app (e.g. from another plugin), you do not need a new one — just add this plugin's redirect URIs to the existing app/client and paste the same credentials into the Providers tab (they are stored in this site's options, never in code):

- **Meta app** → Facebook Login (for Business) → Settings → *Valid OAuth Redirect URIs*: add the URI shown on the Providers tab, which is `https://<your-site>/wp-admin/admin.php?page=commerce-product-social-marketing&cpsm_oauth=facebook` (Instagram uses the same connection and URI). Also add your site domain under App Settings → Basic → *App Domains*. Requested scopes: `pages_show_list, pages_manage_posts, pages_read_engagement, instagram_basic, instagram_content_publish` (plus `business_management, ads_management` for ads, which need app review).
- **Google Cloud** → Credentials → your OAuth client → *Authorized redirect URIs*: add `https://<your-site>/wp-admin/admin.php?page=commerce-product-social-marketing&cpsm_oauth=google`. Scope: `https://www.googleapis.com/auth/business.manage`.

### Facebook / Instagram

1. Create an app at [developers.facebook.com](https://developers.facebook.com), add the **Facebook Login** product.
2. Add the shown **Redirect URI** under *Valid OAuth Redirect URIs*.
3. In the Providers tab, save your App ID and App Secret, then click **Connect Facebook** and grant the requested permissions.
4. Click **Refresh pages list** and tick the pages (and linked Instagram business accounts) you want to post to.
5. The ads scopes (`ads_management`, `business_management`) require Meta app review before ads can be created; posting only needs the pages scopes.

### Google Business Profile

1. Create a project in [Google Cloud Console](https://console.cloud.google.com), enable the **Business Profile APIs** (Account Management, Business Information, Local Posts), and create an OAuth client (web application).
2. Add the shown **Redirect URI** as an authorized redirect URI.
3. Save Client ID/Secret, click **Connect Google**, then **Refresh locations list** and tick your locations.

### Posting

- Products are posted automatically when published (configurable on the **Product posting** tab, including caption template with `{title} {price} {link} {excerpt}` placeholders and hashtags).
- Per product you can skip auto-posting or override the caption in the product editor (**Social media** meta box).
- Post a single product manually via the **Post to social** row action in the product list.
- Everything is logged on the **Log** tab.

## Setup: ads

On the **Ads** tab, enable the module, pick a product and provider, set a daily budget (in cents), duration and target country, and create the campaign. It is created **paused** in your Meta ad account; activate and manage it in [Ads Manager](https://adsmanager.facebook.com).

## Vouchers & gift certificates

On the **Vouchers & gift certificates** tab:

- **Fixed amount** — e.g. €10 off.
- **Percentage** — e.g. 20% off the cart.
- **Gift certificate** — a code with a stored balance that can be spent over multiple orders.

Options per voucher: expiry date, usage limit, recipient email (optionally sent immediately with the code).

Customers enter the code in the **Voucher or gift certificate code** field at checkout. The discount is applied as a cart fee and the voucher is redeemed when the order is placed (balance decremented / usage counted, visible on the order).

## Security notes

- All API calls use the official platform APIs with OAuth tokens stored server-side in wp_options; never share your App Secret or access tokens.
- Admin actions are nonce-protected and capability-checked (`manage_options` / `manage_woocommerce`).

## License

GPL-2.0-or-later
