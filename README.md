# Verified Caller ID

## cPanel / Subdomain install
1. Download the ZIP from GitHub
2. Extract it
3. Upload the extracted folder into: `/home/tunayos/public_html/call`
4. In cPanel, set the subdomain document root to: `/home/tunayos/public_html/call`
5. Open: `https://call.tunayos.com/install.php`

## Important
- The app is intended to run from the folder the subdomain points to.
- For your subdomain, use `/home/tunayos/public_html/call`
- If Twilio is not enabled, the app logs codes to `storage/verification.log`

## Database
Create a MySQL database in cPanel, then use the installer to configure it.
